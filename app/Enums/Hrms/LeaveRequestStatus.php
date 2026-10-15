<?php

namespace App\Enums\Hrms;

enum LeaveRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    /**
     * Hex, never a Tailwind palette name (P2.6 lesson, HrmsEnumColorTest).
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => '#6b7280',
            self::Submitted => '#0284cb',
            self::Pending => '#d97706',
            self::Approved => '#059669',
            self::Rejected => '#dc2626',
            self::Cancelled => '#9ca3af',
            self::Expired => '#78716c',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Approved
            || $this === self::Rejected
            || $this === self::Cancelled
            || $this === self::Expired;
    }

    /**
     * Whether the ask still reserves balance. Drafts never did; terminal
     * states released theirs (rejection) or posted it (approval).
     */
    public function reservesBalance(): bool
    {
        return $this === self::Submitted || $this === self::Pending;
    }
}
