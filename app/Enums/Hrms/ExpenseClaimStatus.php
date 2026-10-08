<?php

namespace App\Enums\Hrms;

enum ExpenseClaimStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
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
            self::Paid => '#047857',
            self::Cancelled => '#4b5563',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Approved
            || $this === self::Rejected
            || $this === self::Paid
            || $this === self::Cancelled;
    }

    /**
     * Claims that still accept edits. Submission locks the figures — an
     * approver decides what was filed, not a moving target.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
