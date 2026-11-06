<?php

declare(strict_types=1);

namespace App\Services\Ai\Documents;

final readonly class ExtractedDocument
{
    public function __construct(
        public string $text,
        public int $pages = 1,
        public bool $truncated = false,
    ) {}
}
