<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

final readonly class AiResponse
{
    public const STOP = 'stop';

    public const TOOL_CALLS = 'tool_calls';

    public const LENGTH = 'length';

    /** @param list<AiToolCall> $toolCalls */
    public function __construct(
        public string $text = '',
        public array $toolCalls = [],
        public AiUsage $usage = new AiUsage,
        public string $finishReason = self::STOP,
    ) {}

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }
}
