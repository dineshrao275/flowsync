<?php

namespace App\Enums\Hrms;

enum EmployeeStatus: string
{
    case Active = 'active';
    case Probation = 'probation';
    case OnNotice = 'on_notice';
    case Suspended = 'suspended';
    case Exited = 'exited';
    case Terminated = 'terminated';

    /**
     * Still on the payroll, whatever else is true.
     *
     * This is the flag leave, payroll and attendance eligibility hang off: an
     * employee on notice is still employed and still accrues, and a suspended
     * employee has not left. `Exited` and `Terminated` have both, so a leaver
     * cannot be sent to a bank with a payroll run.
     */
    public function isEmployed(): bool
    {
        return ! in_array($this, [self::Exited, self::Terminated], true);
    }

    /**
     * Employed, but not currently expected to work.
     *
     * Distinguishes "counts as headcount" from "appears on today's roster":
     * suspended staff and staff serving notice are employed but not working.
     */
    public function isWorking(): bool
    {
        return in_array($this, [self::Active, self::Probation], true);
    }

    /**
     * A status change into this state ends the employment.
     */
    public function isOffboarding(): bool
    {
        return in_array($this, [self::Exited, self::Terminated], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Probation => 'Probation',
            self::OnNotice => 'On Notice',
            self::Suspended => 'Suspended',
            self::Exited => 'Exited',
            self::Terminated => 'Terminated',
        };
    }

    /**
     * A hex colour, not a Tailwind palette name.
     *
     * The clients render it as `backgroundColor: \`${color}22\``, which is only
     * a valid colour if this is a hex — a palette name like `emerald` produces
     * `emerald22`, the pill silently loses its background, and every status
     * renders identically. Task statuses and priorities already store hex for
     * the same reason, so this keeps one convention across the app instead of a
     * second one that looks fine until something is styled with it.
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => '#10b981',
            self::Probation => '#0ea5e9',
            self::OnNotice => '#f59e0b',
            self::Suspended => '#f97316',
            self::Exited => '#6b7280',
            self::Terminated => '#ef4444',
        };
    }
}
