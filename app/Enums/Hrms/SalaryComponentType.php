<?php

namespace App\Enums\Hrms;

enum SalaryComponentType: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';
    case EmployerContribution = 'employer_contribution';
    case Reimbursement = 'reimbursement';

    public function label(): string
    {
        return match ($this) {
            self::Earning => 'Earning',
            self::Deduction => 'Deduction',
            self::EmployerContribution => 'Employer contribution',
            self::Reimbursement => 'Reimbursement',
        };
    }
}
