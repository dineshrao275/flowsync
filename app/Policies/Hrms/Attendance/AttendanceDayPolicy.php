<?php

namespace App\Policies\Hrms\Attendance;

use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\HrmsScope;

/**
 * Attendance/HRMS — who may read attendance records.
 *
 * Resolved through the array form (`authorize('view', [AttendanceDay::class,
 * $employee])`, the CommentController precedent) because the thing being
 * authorized is a person's curve, not one row: the month grid, today's
 * widget and the export all answer the same question. The answer is the
 * shared `HrmsScope::coversEmployee` — self-service included (a caller with
 * no view grant still covers their own record), and anyone holding the
 * `hrms.attendance.view` scope variants, the legacy slug or `manage` reads
 * what that scope covers. The client-supplied `employee_id` on these My-page
 * reads therefore resolves against the caller's real scope, never by luck.
 */
class AttendanceDayPolicy
{
    public function view(User $viewer, Employee $employee): bool
    {
        return HrmsScope::coversEmployee($viewer, 'hrms.attendance', $employee);
    }
}
