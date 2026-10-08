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
            self::Office => '#64748b',
            self::Hybrid => '#0ea5e9',
            self::Remote => '#10b981',
        };
    }
}
