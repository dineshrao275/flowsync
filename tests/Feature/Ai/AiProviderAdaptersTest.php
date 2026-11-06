<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Dto\AiChunk;
use App\Services\Ai\Dto\AiMessage;
use App\Services\Ai\Dto\AiRequest;
use App\Services\Ai\Dto\AiResponse;
use App\Services\Ai\Dto\AiToolCall;
use App\Services\Ai\Dto\AiToolSpec;
use App\Services\Ai\Dto\AiUsage;
use App\Services\Ai\Exceptions\AiErrorKind;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\FakeAiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderAdaptersTest extends TestCase
{
    private function provider(string $name, array $extra = []): AiProvider
    {
        config(array_merge([
            'ai.enabled' => true, 'ai.provider' => $name, 'ai.retry_base_ms' => 0, 'ai.max_retries' => 2,
            'ai.providers.gemini.key' => 'g-key', 'ai.providers.groq.key' => 'q-key',
        ], $extra));

        return app(AiProviderResolver::class)->provider();
    }

    /** Http::fake stacks stubs; start each scenario from a clean factory so an earlier stub cannot answer. */
    private function freshHttp(): void
    {
        Http::swap(new Factory);
    }

    private function searchTool(): AiToolSpec
    {
        return new AiToolSpec('search_tasks', 'Find tasks', [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => ['overdue' => ['type' => 'boolean'], 'limit' => ['type' => 'integer']],
        ]);
    }

    private function conversation(): array
    {
        return [
            AiMessage::system('Be brief.'),
            AiMessage::user('overdue tasks?'),
        ];
    }

    // ---- Gemini ----

    public function test_gemini_sends_the_documented_request_and_translates_a_tool_call(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'search_tasks', 'args' => ['overdue' => true]]]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 30, 'candidatesTokenCount' => 7],
        ])]);

        $response = $this->provider('gemini', ['ai.model' => 'gemini-3.1-flash-lite'])
            ->complete(new AiRequest($this->conversation(), [$this->searchTool()]));

        $this->assertTrue($response->wantsTools());
        $this->assertSame('search_tasks', $response->toolCalls[0]->name);
        $this->assertSame(['overdue' => true], $response->toolCalls[0]->arguments);
        $this->assertSame(AiResponse::TOOL_CALLS, $response->finishReason);
        $this->assertSame(37, $response->usage->total());

        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return str_ends_with($r->url(), '/models/gemini-3.1-flash-lite:generateContent')
                && $r->header('x-goog-api-key') === ['g-key']
                && $body['systemInstruction']['parts'][0]['text'] === 'Be brief.'
                && $body['contents'][0] === ['role' => 'user', 'parts' => [['text' => 'overdue tasks?']]]
                && $body['tools'][0]['functionDeclarations'][0]['name'] === 'search_tasks'
                && ! array_key_exists('additionalProperties', $body['tools'][0]['functionDeclarations'][0]['parameters']);
        });
    }

    public function test_gemini_maps_tool_results_back_as_function_responses_in_one_user_turn(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Two overdue.']]], 'finishReason' => 'STOP']]])]);

        $this->provider('gemini')->complete(new AiRequest([
            AiMessage::user('overdue?'),
            AiMessage::assistant('', [new AiToolCall('c1', 'search_tasks', ['overdue' => true]), new AiToolCall('c2', 'list_projects', [])]),
            AiMessage::tool('c1', 'search_tasks', '{"count":2}'),
            AiMessage::tool('c2', 'list_projects', '{"count":1}'),
        ], [$this->searchTool()]));

        Http::assertSent(function (Request $r) {
            $contents = $r->data()['contents'];

            return $contents[1]['role'] === 'model'
                && isset($contents[1]['parts'][0]['functionCall'])
                && $contents[2]['role'] === 'user'
                && count($contents[2]['parts']) === 2
                && $contents[2]['parts'][0]['functionResponse']['response']['content'] === ['count' => 2];
        });
    }

    public function test_gemini_streams_text_then_usage_then_done(): void
    {
        $sse = 'data: '.json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Hel']]]]]])."\n\n"
            .'data: '.json_encode(['candidates' => [['content' => ['parts' => [['text' => 'lo']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 4, 'candidatesTokenCount' => 2]])."\n\n";
        Http::fake(['*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

        $chunks = iterator_to_array($this->provider('gemini')->stream(new AiRequest($this->conversation())), false);

        $this->assertSame('Hello', implode('', array_map(fn ($c) => $c->text, array_filter($chunks, fn ($c) => $c->type === AiChunk::TEXT))));
        $this->assertSame(6, collect($chunks)->firstWhere('type', AiChunk::USAGE)->usage->total());
        $this->assertSame(AiChunk::DONE, end($chunks)->type);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), ':streamGenerateContent?alt=sse'));
    }

    public function test_gemini_thought_signatures_are_echoed_back_with_the_function_call(): void
    {
        // Gemini 3 answers a 400 ("missing a thought_signature") if the signature of a function call is not returned.
        Http::fake(['*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'search_tasks', 'args' => ['overdue' => true]], 'thoughtSignature' => 'sig-abc']]], 'finishReason' => 'STOP']]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'None.']]], 'finishReason' => 'STOP']]]),
        ]);
        $provider = $this->provider('gemini');
        $tool = $this->searchTool();
        $first = $provider->complete(new AiRequest($this->conversation(), [$tool]));

        $this->assertSame('sig-abc', $first->toolCalls[0]->signature);

        $provider->complete(new AiRequest([
            ...$this->conversation(),
            AiMessage::assistant('', $first->toolCalls),
            AiMessage::tool($first->toolCalls[0]->id, 'search_tasks', '{"total":0}'),
        ], [$tool]));

        Http::assertSent(function (Request $r) {
            $model = collect($r->data()['contents'] ?? [])->firstWhere('role', 'model');

            return $model !== null && ($model['parts'][0]['thoughtSignature'] ?? null) === 'sig-abc';
        });
    }

    // ---- Groq (OpenAI-compatible) ----

    public function test_groq_sends_openai_style_tools_and_parses_tool_calls(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => null, 'tool_calls' => [['id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'search_tasks', 'arguments' => '{"overdue":true}']]]], 'finish_reason' => 'tool_calls']],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5],
        ])]);

        $response = $this->provider('groq', ['ai.model' => 'llama-3.3-70b-versatile'])
            ->complete(new AiRequest($this->conversation(), [$this->searchTool()]));

        $this->assertSame('call_9', $response->toolCalls[0]->id);
        $this->assertSame(['overdue' => true], $response->toolCalls[0]->arguments);

        Http::assertSent(function (Request $r) {
            $b = $r->data();

            return $r->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $r->header('Authorization') === ['Bearer q-key']
                && $b['model'] === 'llama-3.3-70b-versatile'
                && $b['tools'][0]['type'] === 'function'
                && $b['tools'][0]['function']['name'] === 'search_tasks'
                && $b['messages'][0] === ['role' => 'system', 'content' => 'Be brief.'];
        });
    }

    public function test_groq_assembles_streamed_tool_call_fragments(): void
    {
        $events = [
            ['choices' => [['delta' => ['content' => 'Looking']]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'search_tasks', 'arguments' => '{"over']]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'due":true}']]]]], 'finish_reason' => 'tool_calls']],
            ['choices' => [], 'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 3]],
        ];
        $sse = implode('', array_map(fn ($e) => 'data: '.json_encode($e)."\n\n", $events))."data: [DONE]\n\n";
        Http::fake(['*' => Http::response($sse, 200)]);

        $chunks = iterator_to_array($this->provider('groq')->stream(new AiRequest($this->conversation(), [$this->searchTool()])), false);

        $call = collect($chunks)->firstWhere('type', AiChunk::TOOL_CALL)->toolCall;
        $this->assertSame(['overdue' => true], $call->arguments);
        $this->assertSame('search_tasks', $call->name);
        $this->assertSame(12, collect($chunks)->firstWhere('type', AiChunk::USAGE)->usage->total());
        $this->assertSame(AiResponse::TOOL_CALLS, end($chunks)->finishReason);
    }

    // ---- Capabilities, errors, retries ----

    public function test_unsupported_capabilities_fail_explicitly_instead_of_degrading(): void
    {
        Http::fake();
        $llama = $this->provider('groq', ['ai.model' => 'llama-3.3-70b-versatile']);

        try {
            $llama->complete(new AiRequest($this->conversation(), [], null, 0.2, ['type' => 'object']));
            $this->fail('Structured output should be unsupported on this model.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiErrorKind::Unsupported, $e->kind);
        }

        // gpt-oss supports strict JSON output but not together with tools.
        $oss = $this->provider('groq', ['ai.model' => 'openai/gpt-oss-120b']);
        $this->expectException(AiProviderException::class);
        $oss->complete(new AiRequest($this->conversation(), [$this->searchTool()], null, 0.2, ['type' => 'object']));
    }

    public function test_nothing_is_sent_when_a_capability_check_fails(): void
    {
        Http::fake();
        config(['ai.streaming_enabled' => false]);

        try {
            iterator_to_array($this->provider('gemini')->stream(new AiRequest($this->conversation())));
        } catch (AiProviderException) {
        }

        Http::assertNothingSent();
    }

    public function test_provider_errors_are_normalized_by_status(): void
    {
        $cases = [[401, AiErrorKind::Auth], [403, AiErrorKind::Auth], [400, AiErrorKind::BadRequest], [500, AiErrorKind::Unavailable], [504, AiErrorKind::Timeout], [429, AiErrorKind::RateLimit]];

        foreach ($cases as [$status, $kind]) {
            $this->freshHttp();
            Http::fake(['*' => Http::response(['error' => ['message' => 'nope']], $status)]);
            config(['ai.max_retries' => 0]);

            try {
                $this->provider('groq')->complete(new AiRequest($this->conversation()));
                $this->fail("Status {$status} should throw.");
            } catch (AiProviderException $e) {
                $this->assertSame($kind, $e->kind, "status {$status}");
                $this->assertStringContainsString('nope', $e->getMessage());
            }
        }
    }

    public function test_transient_failures_are_retried_and_permanent_ones_are_not(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'busy']], 429, ['Retry-After' => '0'])
            ->push(['error' => ['message' => 'down']], 503)
            ->push(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]]);

        $this->assertSame('ok', $this->provider('groq')->complete(new AiRequest($this->conversation()))->text);
        Http::assertSentCount(3);

        $this->freshHttp();
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad key']], 401)]);
        try {
            $this->provider('groq')->complete(new AiRequest($this->conversation()));
        } catch (AiProviderException) {
        }
        Http::assertSentCount(1);
    }

    public function test_a_connection_timeout_becomes_a_timeout_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        config(['ai.max_retries' => 0]);

        try {
            $this->provider('gemini')->complete(new AiRequest($this->conversation()));
            $this->fail('Expected a timeout.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiErrorKind::Timeout, $e->kind);
            $this->assertSame(504, $e->kind->httpStatus());
        }
    }

    public function test_invalid_tool_arguments_from_the_model_are_a_bad_output_error(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['tool_calls' => [['id' => 'x', 'function' => ['name' => 'a', 'arguments' => '{not json']]]]]]])]);

        $this->expectException(AiProviderException::class);
        $this->provider('groq')->complete(new AiRequest($this->conversation(), [$this->searchTool()]));
    }

    public function test_the_same_conversation_yields_the_same_normalized_answer_on_every_provider(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Two overdue tasks.']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 4]]),
            'api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'Two overdue tasks.'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4]]),
        ]);

        $request = new AiRequest($this->conversation());
        $answers = [];
        foreach (['gemini', 'groq'] as $name) {
            $answers[$name] = $this->provider($name)->complete($request);
        }
        $fake = (new FakeAiProvider)->script(new AiResponse('Two overdue tasks.', [], new AiUsage(10, 4)));
        $answers['fake'] = $fake->complete($request);

        $this->assertEquals($answers['gemini'], $answers['groq']);
        $this->assertEquals($answers['gemini'], $answers['fake']);
    }
}
