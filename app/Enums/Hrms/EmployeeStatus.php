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

    public function color(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::Probation => 'sky',
            self::OnNotice => 'amber',
            self::Suspended => 'orange',
            self::Exited => 'gray',
            self::Terminated => 'red',
        };
    }
}
