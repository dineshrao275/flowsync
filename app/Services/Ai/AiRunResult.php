<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Dto\AiUsage;

final readonly class AiRunResult
{
    /**
     * @param  list<array<string,mixed>>  $cards
     * @param  list<string>  $tools
     */
    public function __construct(
        public int $conversationId,
        public string $answer,
        public array $cards,
        public array $tools,
        public AiUsage $usage,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['conversation_id' => $this->conversationId, 'answer' => $this->answer, 'cards' => $this->cards, 'tools' => $this->tools];
    }
}
