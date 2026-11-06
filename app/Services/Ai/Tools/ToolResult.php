<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

/**
 * What a tool hands back. `data` is the ONLY thing the model sees (already scope-filtered);
 * `cards` are display rows for the UI (title, subtitle, badges, link), built from the same rows.
 */
final readonly class ToolResult
{
    /**
     * @param  array<string,mixed>  $data
     * @param  list<array<string,mixed>>  $cards
     */
    public function __construct(
        public array $data,
        public array $cards = [],
        public bool $isError = false,
    ) {}

    public static function error(string $message): self
    {
        return new self(['error' => $message], [], true);
    }

    public function forModel(): string
    {
        return (string) json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
