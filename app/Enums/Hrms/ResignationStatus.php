<?php

declare(strict_types=1);

namespace App\Enums\Hrms;

enum ResignationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /** Hex, never a Tailwind palette name (HrmsEnumColorTest). */
    public function color(): string
    {
        return match ($this) {
            self::Pending => '#d97706',
            self::Approved => '#059669',
            self::Rejected => '#dc2626',
            self::Withdrawn => '#6b7280',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
