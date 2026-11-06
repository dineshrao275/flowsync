<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Dto\AiChunk;
use App\Services\Ai\Dto\AiMessage;
use App\Services\Ai\Dto\AiRequest;
use App\Services\Ai\Dto\AiResponse;
use App\Services\Ai\Dto\AiToolCall;
use App\Services\Ai\Dto\AiUsage;
use App\Services\Ai\Exceptions\AiErrorKind;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Tools\AiToolRegistry;
use Illuminate\Support\Facades\Log;

/**
 * One chat turn: model -> tool calls -> tool results -> model ... -> answer, with hard bounds.
 *
 * It knows only the `AiProvider` contract and the tool registry. The model decides WHICH tool to
 * ask for; the registry decides whether the caller may use it and what data comes back. Nothing
 * the model says is executed except through that registry.
 */
final class AiOrchestrator
{
    public function __construct(
        private readonly AiProviderResolver $resolver,
        private readonly AiToolRegistry $tools,
        private readonly AiConversationService $conversations,
        private readonly AiUsageService $usage,
    ) {}

    /**
     * @param  array{route?:string,product?:string}  $context
     * @param  (callable(string, array<string,mixed>): void)|null  $emit  receives token|reset|tool|cards events
     */
    public function run(User $user, AiConversation $conversation, string $text, array $context = [], bool $stream = false, ?callable $emit = null): AiRunResult
    {
        $provider = $this->resolver->provider();
        $this->usage->assertWithinLimits();
        $startedAt = hrtime(true);

        $messages = [
            AiMessage::system(AiPrompt::system($user, $context)),
            ...$this->conversations->history($conversation, (int) config('ai.history_messages')),
            AiMessage::user($text),
        ];
        $this->conversations->append($conversation, AiMessage::USER, $text);

        $emit ??= static function (): void {};
        $specs = $provider->capabilities()->tools ? $this->tools->specsFor($user) : [];
        $rounds = max(0, (int) config('ai.max_tool_rounds'));
        $total = new AiUsage;
        $cards = [];
        $used = [];
        $answer = '';

        for ($round = 0; $round <= $rounds; $round++) {
            // The last round offers no tools, so the loop always ends with an answer, never another request.
            $request = new AiRequest($messages, $round < $rounds ? $specs : [], (int) config('ai.max_output_tokens'));
            $response = $this->call($provider, $request, $stream, $emit);
            $total = $total->plus($response->usage);

            if (! $response->wantsTools()) {
                $answer = $response->text;
                break;
            }

            $emit('reset', []);
            $messages[] = AiMessage::assistant($response->text, $response->toolCalls);

            foreach ($response->toolCalls as $call) {
                $emit('tool', ['name' => $call->name]);
                $result = $this->tools->run($user, $call);
                $used[] = $call->name;
                $cards = [...$cards, ...$result->cards];
                $messages[] = AiMessage::tool($call->id, $call->name, $result->forModel());
            }
            $emit('cards', ['cards' => $cards]);
        }

        $answer = trim($answer) !== '' ? $answer : __('I could not put together an answer. Please try rephrasing.');
        $this->conversations->append($conversation, AiMessage::ASSISTANT, $answer, ['cards' => $cards, 'tools' => $used], $total->inputTokens, $total->outputTokens);
        $this->usage->record($total, $provider->model());

        // Metadata only. Never the question, the answer or any tool data.
        Log::info('ai.chat', [
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'user_id' => $user->id,
            'rounds' => count($used),
            'tools' => $used,
            'input_tokens' => $total->inputTokens,
            'output_tokens' => $total->outputTokens,
            'latency_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);

        return new AiRunResult($conversation->id, $answer, $cards, $used, $total);
    }

    /** One provider call, with the explicitly configured fallback (only) on a transient failure. */
    private function call(AiProvider $provider, AiRequest $request, bool $stream, callable $emit): AiResponse
    {
        try {
            return $this->attempt($provider, $request, $stream, $emit);
        } catch (AiProviderException $e) {
            $this->usage->record(new AiUsage, $provider->model(), failed: true);
            Log::warning('ai.provider.failed', ['provider' => $provider->name(), 'kind' => $e->kind->value]);

            $fallback = $e->kind->retryable() ? $this->resolver->fallback() : null;
            if ($fallback === null) {
                throw $e;
            }

            Log::notice('ai.provider.fallback', ['from' => $provider->name(), 'to' => $fallback->name()]);

            return $this->attempt($fallback, $request, $stream, $emit);
        }
    }

    private function attempt(AiProvider $provider, AiRequest $request, bool $stream, callable $emit): AiResponse
    {
        if (! ($stream && $provider->capabilities()->streaming)) {
            $response = $provider->complete($request);
            if (! $response->wantsTools() && $response->text !== '') {
                $emit('token', ['text' => $response->text]);
            }

            return $response;
        }

        $text = '';
        $calls = [];
        $usage = new AiUsage;
        $finish = AiResponse::STOP;

        foreach ($provider->stream($request) as $chunk) {
            match ($chunk->type) {
                AiChunk::TEXT => $this->streamText($chunk, $text, $emit),
                AiChunk::TOOL_CALL => $calls[] = $chunk->toolCall,
                AiChunk::USAGE => $usage = $chunk->usage ?? $usage,
                AiChunk::DONE => $finish = $chunk->finishReason,
                default => null,
            };
        }

        if ($text === '' && $calls === []) {
            throw new AiProviderException(AiErrorKind::BadOutput, 'The provider streamed no content.', $provider->name());
        }

        return new AiResponse($text, array_values(array_filter($calls, fn ($c) => $c instanceof AiToolCall)), $usage, $finish);
    }

    private function streamText(AiChunk $chunk, string &$text, callable $emit): void
    {
        $text .= $chunk->text;
        $emit('token', ['text' => $chunk->text]);
    }
}
