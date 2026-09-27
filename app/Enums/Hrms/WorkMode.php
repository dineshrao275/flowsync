<?php

namespace App\Enums\Hrms;

enum WorkMode: string
{
    case Office = 'office';
    case Hybrid = 'hybrid';
    case Remote = 'remote';

    /**
     * Whether attendance for this person is tracked by a clock-in rather than
     * assumed from a roster — the switch `hrms.attendance.remote` and the
     * remote clock-in policy both hinge on it.
     */
    public function needsClockIn(): bool
    {
        return $this !== self::Office;
    }

    public function label(): string
    {
        return match ($this) {
            self::Office => 'Office',
            self::Hybrid => 'Hybrid',
            self::Remote => 'Remote',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Office => 'slate',
            self::Hybrid => 'sky',
            self::Remote => 'emerald',
        };
    }
}
