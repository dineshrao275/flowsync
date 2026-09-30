<?php

namespace App\Policies\Hrms\Expense;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\User;

/**
 * Expense/HRMS — who may file, see, or decide a claim.
 *
 * Listing and reading are self-or-view (a person sees their own queue
 * without a permission, everyone else's with one); filing and submitting
 * are self-or-manage (HR files for people who cannot); deciding is the
 * approve permission alone — and the chain plus the service still name
 * who may actually act, so the permission opens the door but not the
 * verdict.
 */
class ExpenseClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasEmployee($user) || $this->canView($user);
    }

    public function view(User $user, ExpenseClaim $claim): bool
    {
        return $this->isSelf($user, $claim) || $this->canView($user);
    }

    public function file(User $user, Employee $employee): bool
    {
        return $this->isSelfEmployee($user, $employee)
            || $user->hasPermission('hrms.expenses.manage');
    }

    public function submit(User $user, ExpenseClaim $claim): bool
    {
        return $this->isSelf($user, $claim)
            || $user->hasPermission('hrms.expenses.manage');
    }

    public function decide(User $user, ExpenseClaim $claim): bool
    {
        return $user->hasPermission('hrms.expenses.approve');
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.expenses.view')
            || $user->hasPermission('hrms.expenses.manage');
    }

    private function hasEmployee(User $user): bool
    {
        return Employee::where('user_id', $user->id)->exists();
    }

    private function isSelf(User $user, ExpenseClaim $claim): bool
    {
        return $claim->employee !== null && $this->isSelfEmployee($user, $claim->employee);
    }

    private function isSelfEmployee(User $user, ?Employee $employee): bool
    {
        return $employee !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === (int) $user->id;
    }
}
