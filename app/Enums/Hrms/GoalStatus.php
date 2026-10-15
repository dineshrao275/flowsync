<?php

namespace App\Enums\Hrms;

enum GoalStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Achieved = 'achieved';
    case Missed = 'missed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Achieved => 'Achieved',
            self::Missed => 'Missed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => '#6b7280',
            self::Active => '#0284cb',
            self::Achieved => '#059669',
            self::Missed => '#dc2626',
            self::Cancelled => '#4b5563',
        };
    }
}
