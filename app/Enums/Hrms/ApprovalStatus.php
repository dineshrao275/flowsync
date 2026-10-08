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

    /**
     * A hex colour, not a Tailwind palette name.
     *
     * The clients render it as `backgroundColor: `${color}22``, which is only a
     * valid colour if this is a hex. A palette name like `emerald` produces
     * `emerald22`, which is not a colour at all: the pill silently loses its
     * background and every value renders identically. `EmployeeStatus::color()`
     * shipped that way until P2.6, and `HrmsEnumColorTest` now pins the
     * convention for every HRMS enum so the next one cannot.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => '#f59e0b',
            self::Approved => '#10b981',
            self::Rejected => '#ef4444',
            self::Cancelled => '#6b7280',
        };
    }
}
