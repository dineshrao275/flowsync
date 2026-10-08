<?php

namespace App\Policies\Hrms\Payroll;

use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\User;

/**
 * Compensation/HRMS — who may read or change a pay head.
 *
 * Reads take `hrms.compensation.view` (or manage, which implies the surface);
 * every mutation takes `hrms.compensation.manage` alone. The service — not
 * this policy — refuses system and statutory rows, because "which rows are
 * editable" is a lifecycle rule, not an identity rule.
 */
class SalaryComponentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, SalaryComponent $component): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.compensation.manage');
    }

    public function update(User $user, SalaryComponent $component): bool
    {
        return $user->hasPermission('hrms.compensation.manage');
    }

    public function delete(User $user, SalaryComponent $component): bool
    {
        return $user->hasPermission('hrms.compensation.manage');
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.compensation.view')
            || $user->hasPermission('hrms.compensation.manage');
    }
}
