<?php

namespace App\Enums\Hrms;

enum CompOffRequestStatus: string
{
    case Submitted = 'submitted';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::Submitted => '#0284cb',
            self::Pending => '#d97706',
            self::Approved => '#059669',
            self::Rejected => '#dc2626',
            self::Cancelled => '#9ca3af',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Approved
            || $this === self::Rejected
            || $this === self::Cancelled;
    }
}
