<?php

namespace App\Policies\Hrms\Payroll;

use App\Models\Hrms\Payroll\SalaryStructure;
use App\Models\User;

/**
 * Compensation/HRMS — who may read or change a salary template.
 *
 * The component policy's shape exactly: reads take
 * `hrms.compensation.view` (or manage), every mutation — including the head
 * list sync — takes `hrms.compensation.manage` alone. Two classes rather
 * than one shared base so Laravel's convention discovery binds a named
 * policy to each model (the Org precedent).
 */
class SalaryStructurePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, SalaryStructure $structure): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.compensation.manage');
    }

    public function update(User $user, SalaryStructure $structure): bool
    {
        return $user->hasPermission('hrms.compensation.manage');
    }

    public function delete(User $user, SalaryStructure $structure): bool
    {
        return $user->hasPermission('hrms.compensation.manage');
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.compensation.view')
            || $user->hasPermission('hrms.compensation.manage');
    }
}
