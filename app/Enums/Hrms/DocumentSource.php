<?php

namespace App\Enums\Hrms;

enum DocumentSource: string
{
    case Employee = 'employee';
    case Hr = 'hr';
    case Onboarding = 'onboarding';
    case Offboarding = 'offboarding';
    case Expense = 'expense';
    case Asset = 'asset';
    case Payslip = 'payslip';

    /**
     * How the file arrived, so the checklist, claim, assignment or payslip
     * that caused it can point at the file it left behind. `hr` is the
     * default because an HR-entered document is the common one.
     */
    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Employee',
            self::Hr => 'HR',
            self::Onboarding => 'Onboarding',
            self::Offboarding => 'Offboarding',
            self::Expense => 'Expense',
            self::Asset => 'Asset',
            self::Payslip => 'Payslip',
        };
    }
}
