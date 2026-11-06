<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Dto\AiResponse;
use App\Services\Ai\Dto\AiToolCall;
use App\Services\Ai\Dto\AiUsage;
use App\Services\Ai\Exceptions\AiErrorKind;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;

/**
 * Turns provider payloads and failures into FlowSync's internal shapes, so nothing above the
 * adapters ever sees a vendor field name or status code.
 */
final class AiResponseNormalizer
{
    public function error(string $provider, int $status, ?array $body, ?string $retryAfter = null): AiProviderException
    {
        $detail = $this->detail($body);
        $retry = is_numeric($retryAfter) ? (int) $retryAfter : null;

        $kind = match (true) {
            $status === 429 => AiErrorKind::RateLimit,
            $status === 401 || $status === 403 => AiErrorKind::Auth,
            $status === 408 || $status === 504 => AiErrorKind::Timeout,
            $status >= 500 => AiErrorKind::Unavailable,
            default => AiErrorKind::BadRequest,
        };

        return new AiProviderException($kind, "{$provider} answered {$status}".($detail !== '' ? ": {$detail}" : ''), $provider, $retry);
    }

    public function connection(string $provider, ConnectionException $e): AiProviderException
    {
        $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');

        return new AiProviderException(
            $timedOut ? AiErrorKind::Timeout : AiErrorKind::Unavailable,
            $timedOut ? "{$provider} did not answer in time." : "{$provider} could not be reached.",
            $provider,
        );
    }

    /** @param array<string,mixed> $body an OpenAI-style chat completion */
    public function fromOpenAi(string $provider, array $body): AiResponse
    {
        $choice = $body['choices'][0] ?? null;
        if (! is_array($choice)) {
            throw new AiProviderException(AiErrorKind::BadOutput, "{$provider} returned no choices.", $provider);
        }

        $message = $choice['message'] ?? [];
        $calls = [];
        foreach ($message['tool_calls'] ?? [] as $call) {
            $calls[] = $this->openAiCall($provider, $call);
        }

        return new AiResponse(
            text: (string) ($message['content'] ?? ''),
            toolCalls: $calls,
            usage: $this->openAiUsage($body),
            finishReason: $calls !== [] ? AiResponse::TOOL_CALLS : $this->finish($choice['finish_reason'] ?? 'stop'),
        );
    }

    /** @param array<string,mixed> $call */
    public function openAiCall(string $provider, array $call): AiToolCall
    {
        return new AiToolCall(
            (string) ($call['id'] ?? 'call_'.Str::random(12)),
            (string) ($call['function']['name'] ?? ''),
            $this->arguments($provider, $call['function']['arguments'] ?? null),
        );
    }

    /** @param array<string,mixed> $body */
    public function openAiUsage(array $body): AiUsage
    {
        $usage = $body['usage'] ?? $body['x_groq']['usage'] ?? [];

        return new AiUsage((int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0));
    }

    /** @param array<string,mixed> $body a Gemini generateContent response (or one streamed event) */
    public function fromGemini(string $provider, array $body): AiResponse
    {
        $candidate = $body['candidates'][0] ?? null;
        if (! is_array($candidate)) {
            $blocked = $body['promptFeedback']['blockReason'] ?? null;
            throw new AiProviderException(
                AiErrorKind::BadOutput,
                $blocked ? "{$provider} blocked the prompt ({$blocked})." : "{$provider} returned no candidates.",
                $provider,
            );
        }

        $text = '';
        $calls = [];
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (isset($part['text']) && empty($part['thought'])) {
                $text .= $part['text'];
            }
            if (isset($part['functionCall'])) {
                $calls[] = new AiToolCall(
                    'call_'.Str::random(12),
                    (string) ($part['functionCall']['name'] ?? ''),
                    (array) ($part['functionCall']['args'] ?? []),
                    isset($part['thoughtSignature']) && is_string($part['thoughtSignature']) ? $part['thoughtSignature'] : null,
                );
            }
        }

        return new AiResponse($text, $calls, $this->geminiUsage($body), $calls !== [] ? AiResponse::TOOL_CALLS : $this->finish($candidate['finishReason'] ?? 'STOP'));
    }

    /** @param array<string,mixed> $body */
    public function geminiUsage(array $body): AiUsage
    {
        $usage = $body['usageMetadata'] ?? [];

        return new AiUsage((int) ($usage['promptTokenCount'] ?? 0), (int) ($usage['candidatesTokenCount'] ?? 0));
    }

    /** @return array<string,mixed> */
    private function arguments(string $provider, mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new AiProviderException(AiErrorKind::BadOutput, "{$provider} sent tool arguments that are not valid JSON.", $provider);
        }

        return $decoded;
    }

    private function finish(string $reason): string
    {
        return in_array(strtolower($reason), ['length', 'max_tokens'], true) ? AiResponse::LENGTH : AiResponse::STOP;
    }

    /** @param array<string,mixed>|null $body */
    private function detail(?array $body): string
    {
        $message = $body['error']['message'] ?? $body['message'] ?? '';

        return Str::limit(is_string($message) ? $message : '', 200);
    }
}
