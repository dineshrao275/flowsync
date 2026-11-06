<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/** A tool as the model sees it: a name, a description and a JSON Schema for its arguments. */
final readonly class AiToolSpec
{
    /** @param array<string,mixed> $parameters JSON Schema object */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters = ['type' => 'object', 'properties' => new \stdClass],
    ) {}
}
