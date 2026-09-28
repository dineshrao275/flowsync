<?php

namespace App\Enums\Hrms;

enum OffboardingCaseStatus: string
{
    case Initiated = 'initiated';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Initiated || $this === self::InProgress;
    }

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Initiated',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
