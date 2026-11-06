<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Dto\AiCapabilities;
use App\Services\Ai\Dto\AiChunk;
use App\Services\Ai\Dto\AiMessage;
use App\Services\Ai\Dto\AiRequest;
use App\Services\Ai\Dto\AiResponse;
use App\Services\Ai\Dto\AiUsage;
use App\Services\Ai\Exceptions\AiProviderException;

/**
 * Deterministic provider for tests and local runs without a key. Replies come from a script
 * (responses or exceptions, consumed in order); every request is recorded so a test can assert
 * exactly what would have been sent to a model.
 */
final class FakeAiProvider implements AiProvider
{
    /** @var list<AiRequest> */
    public array $requests = [];

    /** @var list<AiResponse|AiProviderException> */
    private array $script = [];

    public function __construct(
        private readonly string $providerName = 'fake',
        private readonly string $modelName = 'fake-1',
        private readonly AiCapabilities $caps = new AiCapabilities(true, true, true, true, true, true, false, 32000),
    ) {}

    public function script(AiResponse|AiProviderException ...$steps): self
    {
        $this->script = array_values($steps);

        return $this;
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function capabilities(): AiCapabilities
    {
        return $this->caps;
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;
        $next = $this->next($request);

        if ($next instanceof AiProviderException) {
            throw $next;
        }

        return $next;
    }

    public function stream(AiRequest $request): \Generator
    {
        $response = $this->complete($request);

        foreach (preg_split('/(?<=\s)/', $response->text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            yield new AiChunk(AiChunk::TEXT, $word);
        }
        foreach ($response->toolCalls as $call) {
            yield new AiChunk(AiChunk::TOOL_CALL, toolCall: $call);
        }

        yield new AiChunk(AiChunk::USAGE, usage: $response->usage);
        yield new AiChunk(AiChunk::DONE, finishReason: $response->finishReason);
    }

    private function next(AiRequest $request): AiResponse|AiProviderException
    {
        if ($this->script !== []) {
            return array_shift($this->script);
        }

        $last = '';
        foreach (array_reverse($request->messages) as $m) {
            if ($m->role === AiMessage::USER) {
                $last = $m->content;
                break;
            }
        }

        return new AiResponse("Echo: {$last}", [], new AiUsage(10, 5));
    }
}
