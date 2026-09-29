<?php

namespace App\Policies\Hrms\Payroll;

use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\User;

/**
 * Payroll/HRMS — who may run payroll.
 *
 * One permission answers every method: `hrms.payroll.run` opens, calculates,
 * transitions and locks runs. There is no reader/writer split here — a run
 * in review shows every payslip on it, so anyone who may see the grid may
 * move it, and the per-payslip reads stay behind `PayslipPolicy`.
 */
class PayrollRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canRun($user);
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $this->canRun($user);
    }

    public function create(User $user): bool
    {
        return $this->canRun($user);
    }

    public function calculate(User $user, PayrollRun $run): bool
    {
        return $this->canRun($user);
    }

    public function approve(User $user, PayrollRun $run): bool
    {
        return $this->canRun($user);
    }

    public function publish(User $user, PayrollRun $run): bool
    {
        return $this->canRun($user);
    }

    public function markPaid(User $user, PayrollRun $run): bool
    {
        return $this->canRun($user);
    }

    public function lock(User $user, PayrollRun $run): bool
    {
        return $this->canRun($user);
    }

    private function canRun(User $user): bool
    {
        return $user->hasPermission('hrms.payroll.run');
    }
}
