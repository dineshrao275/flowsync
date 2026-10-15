<?php

namespace App\Enums\Hrms;

enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Calculating = 'calculating';
    case Review = 'review';
    case Approved = 'approved';
    case Processing = 'processing';
    case Paid = 'paid';
    case Void = 'void';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Calculating => 'Calculating',
            self::Review => 'Review',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Void => 'Void',
            self::Locked => 'Locked',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => '#6b7280',
            self::Calculating => '#0284cb',
            self::Review => '#d97706',
            self::Approved => '#059669',
            self::Processing => '#7c3aed',
            self::Paid => '#047857',
            self::Void => '#dc2626',
            self::Locked => '#111827',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Paid || $this === self::Void || $this === self::Locked;
    }

    /**
     * Runs that still accept (re)calculation. Everything else — including
     * `paid`, which only `lock()` may touch — refuses with a 422.
     */
    public function isCalculable(): bool
    {
        return $this === self::Draft || $this === self::Review;
    }
}
