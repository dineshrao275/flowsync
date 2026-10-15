<?php

namespace App\Enums\Hrms;

enum ReviewStatus: string
{
    case Draft = 'draft';
    case Calibrating = 'calibrating';
    case Final = 'final';
    case Acknowledged = 'acknowledged';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Calibrating => 'Calibrating',
            self::Final => 'Final',
            self::Acknowledged => 'Acknowledged',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => '#6b7280',
            self::Calibrating => '#d97706',
            self::Final => '#0284cb',
            self::Acknowledged => '#059669',
        };
    }
}
