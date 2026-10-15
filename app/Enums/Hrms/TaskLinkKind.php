<?php

namespace App\Enums\Hrms;

/**
 * What an HRMS↔task link is FOR.
 *
 * The link itself is evidence-only (D2.10): it names the relationship so a
 * reviewer can audit a number, and never feeds a score. Kinds mirror the
 * HRMS domains that raise tasks — a goal measured by linked work, a
 * checklist item converted to a project task, an HR-owned ask living on
 * the employee's home.
 */
enum TaskLinkKind: string
{
    case Goal = 'goal';
    case Onboarding = 'onboarding';
    case Attendance = 'attendance';
    case Expense = 'expense';
    case Payroll = 'payroll';
    case Leave = 'leave';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Goal => 'Goal',
            self::Onboarding => 'Onboarding',
            self::Attendance => 'Attendance',
            self::Expense => 'Expense',
            self::Payroll => 'Payroll',
            self::Leave => 'Leave',
            self::Review => 'Review',
        };
    }

    /**
     * A hex colour, never a Tailwind palette name (the status-pill rule:
     * clients render `backgroundColor: \`${color}22\``).
     */
    public function color(): string
    {
        return match ($this) {
            self::Goal => '#4f46e5',
            self::Onboarding => '#059669',
            self::Attendance => '#d97706',
            self::Expense => '#0284c7',
            self::Payroll => '#7c3aed',
            self::Leave => '#059669',
            self::Review => '#dc2626',
        };
    }
}
