<?php

namespace App\Enums\Hrms;

enum ApprovalStepStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * A step with no resolvable approver (nobody holds the role, the employee
     * has no manager, a department has no head). The flow must not deadlock on
     * it, so it is marked skipped and the engine advances.
     */
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    public function isActionable(): bool
    {
        return $this === self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Skipped => 'Skipped',
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
            self::Skipped => '#6b7280',
        };
    }
}
