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

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'emerald',
            self::Rejected => 'red',
            self::Skipped => 'gray',
        };
    }
}
