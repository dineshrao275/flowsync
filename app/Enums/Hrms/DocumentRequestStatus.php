<?php

namespace App\Enums\Hrms;

enum DocumentRequestStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Waived = 'waived';
    case Rejected = 'rejected';

    /**
     * Accepted and waived are terminal: the file is evidence, or HR decided
     * no file is needed. A rejection reopens the loop — the employee uploads
     * again — rather than closing it, because “try again” is not “go away”.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Submitted, self::Waived], true),
            self::Submitted => in_array($next, [self::Accepted, self::Rejected, self::Waived], true),
            self::Rejected => in_array($next, [self::Submitted, self::Waived], true),
            self::Accepted, self::Waived => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Submitted => 'Submitted',
            self::Accepted => 'Accepted',
            self::Waived => 'Waived',
            self::Rejected => 'Rejected',
        };
    }
}
