<?php

namespace App\Policies\Hrms\Attendance;

use App\Models\Hrms\Attendance\AttendanceRotation;
use App\Models\User;

/**
 * Shift/HRMS — rotation templates are shared reference data: reads take
 * `hrms.shifts.view`, writes and applying one take `hrms.shifts.manage`.
 */
class AttendanceRotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hrms.shifts.view') || $user->hasPermission('hrms.shifts.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.shifts.manage');
    }

    public function update(User $user, AttendanceRotation $rotation): bool
    {
        return $user->hasPermission('hrms.shifts.manage');
    }

    public function delete(User $user, AttendanceRotation $rotation): bool
    {
        return $user->hasPermission('hrms.shifts.manage');
    }

    public function apply(User $user, AttendanceRotation $rotation): bool
    {
        return $user->hasPermission('hrms.shifts.manage');
    }
}
