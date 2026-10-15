<?php

namespace App\Policies\Hrms\Leave;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeavePolicy;
use App\Models\User;

/**
 * Leave/HRMS — who may read and manage leave policies.
 *
 * Same shape as the type catalogue: employee-open reads (the request form
 * shows accrual rules), `hrms.leave.manage` writes.
 */
class LeavePolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canRead($user) || $this->hasEmployee($user);
    }

    public function view(User $user): bool
    {
        return $this->canRead($user) || $this->hasEmployee($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.leave.manage');
    }

    public function update(User $user, LeavePolicy $policy): bool
    {
        return $user->hasPermission('hrms.leave.manage');
    }

    public function delete(User $user, LeavePolicy $policy): bool
    {
        return $user->hasPermission('hrms.leave.manage');
    }

    private function canRead(User $user): bool
    {
        return $user->hasPermission('hrms.leave.view')
            || $user->hasPermission('hrms.leave.manage');
    }

    private function hasEmployee(User $user): bool
    {
        return Employee::where('user_id', $user->id)->exists();
    }
}
