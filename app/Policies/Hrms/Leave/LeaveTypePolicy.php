<?php

namespace App\Policies\Hrms\Leave;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\User;

/**
 * Leave/HRMS — who may read and manage the leave-type catalogue.
 *
 * Reads are employee-open: anyone filing leave needs the catalogue to file
 * against, so `viewAny`/`view` take any employment record, not a tenant
 * permission (the D2.12 self-service rule). Writes are `hrms.leave.manage`.
 */
class LeaveTypePolicy
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

    public function update(User $user, LeaveType $type): bool
    {
        return $user->hasPermission('hrms.leave.manage');
    }

    public function delete(User $user, LeaveType $type): bool
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
