<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/** One increment of a streamed reply: text, a completed tool call, final usage, or the end marker. */
final readonly class AiChunk
{
    public const TEXT = 'text';

    public const TOOL_CALL = 'tool_call';

    public const USAGE = 'usage';

    public const DONE = 'done';

    public function __construct(
        public string $type,
        public string $text = '',
        public ?AiToolCall $toolCall = null,
        public ?AiUsage $usage = null,
        public string $finishReason = AiResponse::STOP,
    ) {}
}
