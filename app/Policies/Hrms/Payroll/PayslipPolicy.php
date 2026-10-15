<?php

namespace App\Policies\Hrms\Payroll;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\User;

/**
 * Payroll/HRMS — who may read a payslip.
 *
 * Two different questions, not one role check: `viewAny` opens the run
 * review grid for payroll runners (`hrms.payroll.run` — the grid is a
 * payroll tool, not a directory, so `view_all` alone does not open it);
 * `view` opens one payslip for `view_all` holders, or for the person in it
 * when they additionally hold `hrms.payroll.view`. A tenant-level view
 * permission never grants someone else's payslip, and being the person in
 * it never waives the view permission.
 */
class PayslipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hrms.payroll.run');
    }

    public function view(User $user, Payslip $payslip): bool
    {
        if ($user->hasPermission('hrms.payroll.view_all')) {
            return true;
        }

        return $this->isSelf($user, $payslip)
            && $user->hasPermission('hrms.payroll.view');
    }

    /**
     * Hand a bonus or recovery onto a review payslip. A runner tool, like
     * the grid — the run's own state machine (review-only) is the second
     * half of the answer, enforced in the service.
     */
    public function adjust(User $user, Payslip $payslip): bool
    {
        return $user->hasPermission('hrms.payroll.run');
    }

    /**
     * Whether this payslip is the caller's own.
     *
     * `user_id` is the link, not the email — the same rule as
     * `EmployeePolicy::isSelf`, because a payslip for a record with no login
     * belongs to a real person who cannot see themselves.
     */
    private function isSelf(User $user, Payslip $payslip): bool
    {
        $employee = $payslip->employee ?? Employee::find($payslip->employee_id);

        return $employee !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === (int) $user->id;
    }
}
