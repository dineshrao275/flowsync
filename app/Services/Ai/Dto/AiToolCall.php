<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/** A tool invocation the model asked for. Untrusted: arguments are validated by the tool before anything runs. */
final readonly class AiToolCall
{
    /** @param array<string,mixed> $arguments */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
    ) {}
}
