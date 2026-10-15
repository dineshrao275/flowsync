<?php

namespace App\Enums\Hrms;

enum RevisionStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Applied = 'applied';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Applied => 'Applied',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => '#6b7280',
            self::Approved => '#059669',
            self::Rejected => '#dc2626',
            self::Applied => '#0284cb',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Rejected || $this === self::Applied;
    }
}
