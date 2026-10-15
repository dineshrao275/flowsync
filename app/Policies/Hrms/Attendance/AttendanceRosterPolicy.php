<?php

namespace App\Policies\Hrms\Attendance;

use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;

/**
 * Shift/HRMS — who may read and write rosters.
 *
 * The whole-tenant grid takes `hrms.shifts.view`; a person may always read
 * their own rows (self-service, through the `mine` route which resolves the
 * employee from the login). Writes take `hrms.shifts.manage`.
 */
class AttendanceRosterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hrms.shifts.view') || $user->hasPermission('hrms.shifts.manage');
    }

    public function view(User $user, AttendanceRoster $roster): bool
    {
        return $this->viewAny($user)
            || Employee::query()->whereKey($roster->employee_id)->where('user_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.shifts.manage');
    }

    public function delete(User $user, AttendanceRoster $roster): bool
    {
        return $user->hasPermission('hrms.shifts.manage');
    }
}
