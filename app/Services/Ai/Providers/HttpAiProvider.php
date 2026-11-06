<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiResponseNormalizer;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Dto\AiCapabilities;
use App\Services\Ai\Dto\AiChunk;
use App\Services\Ai\Dto\AiRequest;
use App\Services\Ai\Dto\AiResponse;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\SseReader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared transport for HTTP-based providers: capability checks, timeouts, retry with backoff for
 * transient failures, error normalization. A concrete adapter only supplies the wire format.
 */
abstract class HttpAiProvider implements AiProvider
{
    /**
     * @param  array{key:string,base_url:string,model:string,timeout:int,max_retries:int,retry_base_ms:int,max_output_tokens:int}  $settings
     */
    public function __construct(
        protected readonly string $providerName,
        protected readonly array $settings,
        protected readonly AiCapabilities $capabilities,
        protected readonly AiResponseNormalizer $normalizer,
    ) {}

    public function name(): string
    {
        return $this->providerName;
    }

    public function model(): string
    {
        return $this->settings['model'];
    }

    public function capabilities(): AiCapabilities
    {
        return $this->capabilities;
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->assertSupported($request, false);

        $body = $this->withRetries(fn () => $this->send($this->endpoint(false), $this->payload($request, false)));

        return $this->parse($body);
    }

    public function stream(AiRequest $request): \Generator
    {
        $this->assertSupported($request, true);

        $response = $this->withRetries(fn () => $this->open($this->endpoint(true), $this->payload($request, true)));

        yield from $this->chunks(SseReader::events($response->toPsrResponse()->getBody()));
    }

    /** Endpoint URL for a normal or a streaming call. */
    abstract protected function endpoint(bool $stream): string;

    /** @return array<string,string> */
    abstract protected function headers(): array;

    /** @return array<string,mixed> */
    abstract protected function payload(AiRequest $request, bool $stream): array;

    /** @param array<string,mixed> $body */
    abstract protected function parse(array $body): AiResponse;

    /**
     * @param  iterable<int, array<string,mixed>>  $events  decoded stream events
     * @return \Generator<int, AiChunk>
     */
    abstract protected function chunks(iterable $events): \Generator;

    protected function assertSupported(AiRequest $request, bool $stream): void
    {
        if ($stream && ! $this->capabilities->streaming) {
            throw AiProviderException::unsupported($this->providerName, 'streaming');
        }
        if ($request->tools !== [] && ! $this->capabilities->tools) {
            throw AiProviderException::unsupported($this->providerName, 'tool calling');
        }
        if ($request->jsonSchema !== null && ! $this->capabilities->jsonSchemaOutput) {
            throw AiProviderException::unsupported($this->providerName, 'structured JSON output');
        }
        if ($request->jsonSchema !== null && $request->tools !== [] && ! $this->capabilities->structuredWithTools) {
            throw AiProviderException::unsupported($this->providerName, 'structured output together with tools');
        }
    }

    /** @return array<string,mixed> */
    private function send(string $url, array $payload): array
    {
        try {
            $response = $this->client()->post($url, $payload);
        } catch (ConnectionException $e) {
            throw $this->normalizer->connection($this->providerName, $e);
        }

        if ($response->failed()) {
            throw $this->normalizer->error($this->providerName, $response->status(), $response->json(), $response->header('Retry-After'));
        }

        return (array) $response->json();
    }

    private function open(string $url, array $payload): Response
    {
        try {
            $response = $this->client()->withOptions(['stream' => true])->post($url, $payload);
        } catch (ConnectionException $e) {
            throw $this->normalizer->connection($this->providerName, $e);
        }

        if ($response->failed()) {
            throw $this->normalizer->error($this->providerName, $response->status(), json_decode((string) $response->toPsrResponse()->getBody(), true) ?: null, $response->header('Retry-After'));
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders($this->headers())->acceptJson()->asJson()->timeout($this->settings['timeout']);
    }

    /** Retries only transient failures (rate limit, timeout, 5xx) and never beyond the configured count. */
    private function withRetries(callable $call): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $call();
            } catch (AiProviderException $e) {
                if (! $e->kind->retryable() || $attempt >= $this->settings['max_retries']) {
                    throw $e;
                }

                $wait = $e->retryAfterSeconds !== null
                    ? min($e->retryAfterSeconds, 5) * 1000
                    : $this->settings['retry_base_ms'] * (2 ** $attempt);
                $this->pause((int) $wait);
                $attempt++;
            }
        }
    }

    protected function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
