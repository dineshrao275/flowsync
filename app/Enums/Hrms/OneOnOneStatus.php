<?php

namespace App\Enums\Hrms;

enum OneOnOneStatus: string
{
    case Scheduled = 'scheduled';
    case Held = 'held';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Held => 'Held',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::Scheduled => '#0284cb',
            self::Held => '#059669',
            self::Cancelled => '#4b5563',
        };
    }
}
