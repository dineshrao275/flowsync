<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/** Everything a provider needs for one model call. Provider-neutral. */
final readonly class AiRequest
{
    /**
     * @param  list<AiMessage>  $messages
     * @param  list<AiToolSpec>  $tools
     * @param  array<string,mixed>|null  $jsonSchema  when set, the reply must be one JSON document matching it
     */
    public function __construct(
        public array $messages,
        public array $tools = [],
        public ?int $maxOutputTokens = null,
        public float $temperature = 0.2,
        public ?array $jsonSchema = null,
    ) {}
}
