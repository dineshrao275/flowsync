<?php

namespace App\Policies\Hrms\Attendance;

use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\User;

/**
 * Shift/HRMS — who may read and manage the shift catalogue.
 *
 * Shifts are shared reference data (no self-service leg); reads take
 * `hrms.shifts.view`, every write takes `hrms.shifts.manage`.
 */
class AttendanceShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hrms.shifts.view') || $user->hasPermission('hrms.shifts.manage');
    }

    public function view(User $user, AttendanceShift $shift): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.shifts.manage');
    }

    public function update(User $user, AttendanceShift $shift): bool
    {
        return $user->hasPermission('hrms.shifts.manage');
    }

    public function delete(User $user, AttendanceShift $shift): bool
    {
        return $user->hasPermission('hrms.shifts.manage');
    }
}
