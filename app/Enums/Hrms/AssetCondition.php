<?php

namespace App\Enums\Hrms;

enum AssetCondition: string
{
    case New = 'new';
    case Good = 'good';
    case Fair = 'fair';
    case Poor = 'poor';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Good => 'Good',
            self::Fair => 'Fair',
            self::Poor => 'Poor',
            self::Damaged => 'Damaged',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::New => '#0284cb',
            self::Good => '#059669',
            self::Fair => '#d97706',
            self::Poor => '#b45309',
            self::Damaged => '#dc2626',
        };
    }
}
