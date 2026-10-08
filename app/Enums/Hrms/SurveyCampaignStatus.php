<?php

namespace App\Enums\Hrms;

enum SurveyCampaignStatus: string
{
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Open => 'Open',
            self::Closed => 'Closed',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::Scheduled => '#6b7280',
            self::Open => '#059669',
            self::Closed => '#4b5563',
        };
    }
}
