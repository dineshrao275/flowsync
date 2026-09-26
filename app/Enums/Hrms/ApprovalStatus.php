<?php

namespace App\Enums\Hrms;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Terminal states never re-enter the flow. A rejected request is closed,
     * not parked — re-requesting creates a new approval.
     */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Approved, self::Rejected, self::Cancelled], true),
            self::Approved, self::Rejected, self::Cancelled => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'emerald',
            self::Rejected => 'red',
            self::Cancelled => 'gray',
        };
    }
}
