<?php

namespace App\Enums\Hrms;

enum AssetAssignmentStatus: string
{
    case Active = 'active';
    case Returned = 'returned';
    case Lost = 'lost';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Returned => 'Returned',
            self::Lost => 'Lost',
            self::Damaged => 'Damaged',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => '#0284cb',
            self::Returned => '#059669',
            self::Lost => '#4b5563',
            self::Damaged => '#dc2626',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Active;
    }
}
