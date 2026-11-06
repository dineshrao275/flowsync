<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/** One conversation turn in FlowSync's own format. Adapters translate it to their wire format and back. */
final readonly class AiMessage
{
    public const SYSTEM = 'system';

    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    public const TOOL = 'tool';

    /** @param list<AiToolCall> $toolCalls assistant turns only */
    public function __construct(
        public string $role,
        public string $content = '',
        public array $toolCalls = [],
        public ?string $toolCallId = null,
        public ?string $toolName = null,
    ) {}

    public static function system(string $content): self
    {
        return new self(self::SYSTEM, $content);
    }

    public static function user(string $content): self
    {
        return new self(self::USER, $content);
    }

    /** @param list<AiToolCall> $toolCalls */
    public static function assistant(string $content, array $toolCalls = []): self
    {
        return new self(self::ASSISTANT, $content, $toolCalls);
    }

    public static function tool(string $callId, string $name, string $content): self
    {
        return new self(self::TOOL, $content, [], $callId, $name);
    }
}
