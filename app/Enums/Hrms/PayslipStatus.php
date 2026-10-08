<?php

namespace App\Enums\Hrms;

enum PayslipStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Disputed = 'disputed';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Disputed => 'Disputed',
            self::Paid => 'Paid',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => '#6b7280',
            self::Published => '#0284cb',
            self::Disputed => '#d97706',
            self::Paid => '#059669',
        };
    }
}
