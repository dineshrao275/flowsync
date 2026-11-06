<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

final readonly class AiUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    public function total(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function plus(self $other): self
    {
        return new self($this->inputTokens + $other->inputTokens, $this->outputTokens + $other->outputTokens);
    }
}
