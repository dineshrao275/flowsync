<?php

namespace App\Policies\Hrms\Attendance;

use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;

/**
 * Attendance/HRMS — who may read attendance records.
 *
 * Resolved through the array form (`authorize('view', [AttendanceDay::class,
 * $employee])`, the CommentController precedent) because the thing being
 * authorized is a person's curve, not one row: the month grid, today's
 * widget and the export all answer the same question. Self-service included
 * (D2.12) — anyone else needs `hrms.attendance.view` or `manage`.
 */
class AttendanceDayPolicy
{
    public function view(User $viewer, Employee $employee): bool
    {
        return $this->isSelf($viewer, $employee)
            || $viewer->hasPermission('hrms.attendance.view')
            || $viewer->hasPermission('hrms.attendance.manage');
    }

    private function isSelf(User $viewer, Employee $employee): bool
    {
        return $employee->user_id !== null
            && (int) $employee->user_id === (int) $viewer->id;
    }
}
