<?php

namespace App\Enums\Hrms;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';

    /**
     * The only legal moves. A document is evidence because a person looked at
     * it, so `verified` has to be entered deliberately; `rejected` and
     * `expired` close the row instead. A rejected row stays rejected: fixing
     * the problem means uploading a new document, not editing history.
     */
    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Verified, self::Rejected, self::Expired], true),
            self::Verified => in_array($next, [self::Rejected, self::Expired], true),
            self::Rejected, self::Expired => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }
}
