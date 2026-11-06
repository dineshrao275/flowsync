<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Dto\AiChunk;
use App\Services\Ai\Dto\AiMessage;
use App\Services\Ai\Dto\AiRequest;
use App\Services\Ai\Dto\AiResponse;
use App\Services\Ai\Dto\AiToolSpec;
use App\Services\Ai\Dto\AiUsage;

/** Adapter for the Gemini `generateContent` / `streamGenerateContent` REST API. */
class GeminiProvider extends HttpAiProvider
{
    protected function endpoint(bool $stream): string
    {
        $base = rtrim($this->settings['base_url'], '/')."/models/{$this->settings['model']}";

        return $stream ? "{$base}:streamGenerateContent?alt=sse" : "{$base}:generateContent";
    }

    protected function headers(): array
    {
        return ['x-goog-api-key' => $this->settings['key']];
    }

    protected function payload(AiRequest $request, bool $stream): array
    {
        $system = [];
        $contents = [];

        foreach ($request->messages as $m) {
            if ($m->role === AiMessage::SYSTEM) {
                $system[] = $m->content;

                continue;
            }
            $this->append($contents, $m);
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $request->temperature,
                'maxOutputTokens' => $request->maxOutputTokens ?? $this->settings['max_output_tokens'],
            ],
        ];

        if ($system !== []) {
            $payload['systemInstruction'] = ['parts' => [['text' => implode("\n\n", $system)]]];
        }

        if ($request->tools !== []) {
            $payload['tools'] = [['functionDeclarations' => array_map($this->declaration(...), $request->tools)]];
        }

        if ($request->jsonSchema !== null) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
            $payload['generationConfig']['responseSchema'] = $this->schema($request->jsonSchema);
        }

        return $payload;
    }

    protected function parse(array $body): AiResponse
    {
        return $this->normalizer->fromGemini($this->providerName, $body);
    }

    protected function chunks(iterable $events): \Generator
    {
        $usage = new AiUsage;
        $finish = AiResponse::STOP;
        $sawCall = false;

        foreach ($events as $event) {
            if (isset($event['usageMetadata'])) {
                $usage = $this->normalizer->geminiUsage($event);
            }
            if (! isset($event['candidates'][0])) {
                continue;
            }

            $step = $this->normalizer->fromGemini($this->providerName, $event);
            if ($step->text !== '') {
                yield new AiChunk(AiChunk::TEXT, $step->text);
            }
            foreach ($step->toolCalls as $call) {
                $sawCall = true;
                yield new AiChunk(AiChunk::TOOL_CALL, toolCall: $call);
            }
            if ($step->finishReason === AiResponse::LENGTH) {
                $finish = AiResponse::LENGTH;
            }
        }

        yield new AiChunk(AiChunk::USAGE, usage: $usage);
        yield new AiChunk(AiChunk::DONE, finishReason: $sawCall ? AiResponse::TOOL_CALLS : $finish);
    }

    /**
     * Gemini wants alternating user/model turns; consecutive tool results share one user turn.
     *
     * @param  list<array<string,mixed>>  $contents
     */
    private function append(array &$contents, AiMessage $m): void
    {
        if ($m->role === AiMessage::TOOL) {
            $part = ['functionResponse' => ['name' => $m->toolName, 'response' => ['content' => $this->decoded($m->content)]]];
            $last = count($contents) - 1;
            if ($last >= 0 && $contents[$last]['role'] === 'user' && isset($contents[$last]['parts'][0]['functionResponse'])) {
                $contents[$last]['parts'][] = $part;
            } else {
                $contents[] = ['role' => 'user', 'parts' => [$part]];
            }

            return;
        }

        $parts = $m->content !== '' ? [['text' => $m->content]] : [];
        foreach ($m->toolCalls as $call) {
            $part = ['functionCall' => ['name' => $call->name, 'args' => $call->arguments === [] ? new \stdClass : $call->arguments]];
            if ($call->signature !== null) {
                $part['thoughtSignature'] = $call->signature;
            }
            $parts[] = $part;
        }

        $contents[] = ['role' => $m->role === AiMessage::ASSISTANT ? 'model' : 'user', 'parts' => $parts ?: [['text' => '']]];
    }

    /** @return array<string,mixed> */
    private function declaration(AiToolSpec $tool): array
    {
        $out = ['name' => $tool->name, 'description' => $tool->description];
        $schema = $this->schema($tool->parameters);

        if (! empty($schema['properties'])) {
            $out['parameters'] = $schema;
        }

        return $out;
    }

    /** Gemini accepts an OpenAPI subset of JSON Schema. */
    private function schema(mixed $schema): mixed
    {
        if (! is_array($schema)) {
            return $schema;
        }

        unset($schema['additionalProperties'], $schema['$schema']);

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->schema($value);
            }
        }

        return $schema;
    }

    private function decoded(string $content): mixed
    {
        $json = json_decode($content, true);

        return is_array($json) ? $json : $content;
    }
}
