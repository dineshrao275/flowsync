<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Dto\AiChunk;
use App\Services\Ai\Dto\AiMessage;
use App\Services\Ai\Dto\AiRequest;
use App\Services\Ai\Dto\AiResponse;
use App\Services\Ai\Dto\AiUsage;

/** Adapter for any OpenAI-style `/chat/completions` API (Groq today). */
class OpenAiCompatibleProvider extends HttpAiProvider
{
    protected function endpoint(bool $stream): string
    {
        return rtrim($this->settings['base_url'], '/').'/chat/completions';
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->settings['key']];
    }

    protected function payload(AiRequest $request, bool $stream): array
    {
        $payload = [
            'model' => $this->settings['model'],
            'messages' => array_map($this->message(...), $request->messages),
            'temperature' => $request->temperature,
            'max_completion_tokens' => $request->maxOutputTokens ?? $this->settings['max_output_tokens'],
        ];

        if ($request->tools !== []) {
            $payload['tools'] = array_map(fn ($tool) => [
                'type' => 'function',
                'function' => ['name' => $tool->name, 'description' => $tool->description, 'parameters' => $tool->parameters],
            ], $request->tools);
        }

        if ($request->jsonSchema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'response', 'strict' => true, 'schema' => $request->jsonSchema],
            ];
        }

        if ($stream) {
            $payload['stream'] = true;
            $payload['stream_options'] = ['include_usage' => true];
        }

        return $payload;
    }

    protected function parse(array $body): AiResponse
    {
        return $this->normalizer->fromOpenAi($this->providerName, $body);
    }

    protected function chunks(iterable $events): \Generator
    {
        $calls = [];
        $usage = new AiUsage;
        $finish = AiResponse::STOP;

        foreach ($events as $event) {
            $choice = $event['choices'][0] ?? [];
            $delta = $choice['delta'] ?? [];

            if (($delta['content'] ?? '') !== '') {
                yield new AiChunk(AiChunk::TEXT, (string) $delta['content']);
            }

            foreach ($delta['tool_calls'] ?? [] as $part) {
                $i = (int) ($part['index'] ?? 0);
                $calls[$i] ??= ['id' => '', 'function' => ['name' => '', 'arguments' => '']];
                $calls[$i]['id'] = $part['id'] ?? $calls[$i]['id'];
                $calls[$i]['function']['name'] .= $part['function']['name'] ?? '';
                $calls[$i]['function']['arguments'] .= $part['function']['arguments'] ?? '';
            }

            if (! empty($choice['finish_reason'])) {
                $finish = $choice['finish_reason'] === 'length' ? AiResponse::LENGTH : AiResponse::STOP;
            }

            if (isset($event['usage']) || isset($event['x_groq']['usage'])) {
                $usage = $this->normalizer->openAiUsage($event);
            }
        }

        ksort($calls);
        foreach ($calls as $call) {
            yield new AiChunk(AiChunk::TOOL_CALL, toolCall: $this->normalizer->openAiCall($this->providerName, $call));
        }

        yield new AiChunk(AiChunk::USAGE, usage: $usage);
        yield new AiChunk(AiChunk::DONE, finishReason: $calls !== [] ? AiResponse::TOOL_CALLS : $finish);
    }

    /** @return array<string,mixed> */
    private function message(AiMessage $m): array
    {
        if ($m->role === AiMessage::TOOL) {
            return ['role' => 'tool', 'tool_call_id' => $m->toolCallId, 'content' => $m->content];
        }

        $out = ['role' => $m->role, 'content' => $m->content];

        if ($m->toolCalls !== []) {
            $out['tool_calls'] = array_map(fn ($c) => [
                'id' => $c->id,
                'type' => 'function',
                'function' => ['name' => $c->name, 'arguments' => json_encode($c->arguments === [] ? new \stdClass : $c->arguments)],
            ], $m->toolCalls);
        }

        return $out;
    }
}
