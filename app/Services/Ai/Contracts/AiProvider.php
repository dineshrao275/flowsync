<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Dto\AiCapabilities;
use App\Services\Ai\Dto\AiChunk;
use App\Services\Ai\Dto\AiRequest;
use App\Services\Ai\Dto\AiResponse;
use App\Services\Ai\Exceptions\AiProviderException;

/**
 * The only surface FlowSync's AI code talks to. Provider specifics (endpoints, auth, wire format,
 * stream parsing, tool-call translation) live behind it; nothing above this interface knows which
 * provider is active.
 */
interface AiProvider
{
    public function name(): string;

    public function model(): string;

    public function capabilities(): AiCapabilities;

    /** @throws AiProviderException */
    public function complete(AiRequest $request): AiResponse;

    /**
     * @return \Generator<int, AiChunk>
     *
     * @throws AiProviderException
     */
    public function stream(AiRequest $request): \Generator;
}
