<?php

namespace App\Enums\Hrms;

enum CaseTaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Skipped = 'skipped';
    case Waived = 'waived';

    /**
     * Done is done; waived is resolved-but-not-done, which is why progress
     * counts the two separately. A waived mandatory item still needed its
     * reason and its permission at waive time — that was the check, not this.
     */
    public function isResolved(): bool
    {
        return $this === self::Done || $this === self::Waived;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In progress',
            self::Done => 'Done',
            self::Skipped => 'Skipped',
            self::Waived => 'Waived',
        };
    }
}
