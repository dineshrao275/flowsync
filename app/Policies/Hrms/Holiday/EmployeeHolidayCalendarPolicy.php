<?php

namespace App\Policies\Hrms\Holiday;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Holiday\EmployeeHolidayCalendar;
use App\Models\User;

/**
 * Holiday/HRMS — who may manage calendar assignments.
 *
 * Assignments are HR administration, not self-service: following a
 * calendar is decided for employees, not by them. Every answer here
 * takes `hrms.holidays.manage`, and the resolved per-employee view (the
 * grid clients actually read) authorizes separately, where self-service
 * applies.
 */
class EmployeeHolidayCalendarPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hrms.holidays.manage');
    }

    public function view(User $user, EmployeeHolidayCalendar $assignment): bool
    {
        return $user->hasPermission('hrms.holidays.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.holidays.manage');
    }

    public function delete(User $user, EmployeeHolidayCalendar $assignment): bool
    {
        return $user->hasPermission('hrms.holidays.manage');
    }

    /**
     * The resolved per-employee grid: anyone reads their own, anyone else
     * needs the view permission. Public holidays are shared reference
     * data, but the grid also names taken optionals, which are personal.
     */
    public function resolved(User $viewer, Employee $employee): bool
    {
        return ($employee->user_id !== null && (int) $employee->user_id === (int) $viewer->id)
            || $viewer->hasPermission('hrms.holidays.view')
            || $viewer->hasPermission('hrms.holidays.manage');
    }
}
