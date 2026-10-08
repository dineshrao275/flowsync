<?php

namespace App\Policies\Hrms\Holiday;

use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Models\User;

/**
 * Holiday/HRMS — who may read and manage holiday calendars.
 *
 * Reads take `hrms.holidays.view` (calendars are shared reference data,
 * not personal records, so there is no self-service leg); all writes
 * take `hrms.holidays.manage`.
 */
class HolidayCalendarPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canRead($user);
    }

    public function view(User $user, HolidayCalendar $calendar): bool
    {
        return $this->canRead($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.holidays.manage');
    }

    public function update(User $user, HolidayCalendar $calendar): bool
    {
        return $user->hasPermission('hrms.holidays.manage');
    }

    public function delete(User $user, HolidayCalendar $calendar): bool
    {
        return $user->hasPermission('hrms.holidays.manage');
    }

    private function canRead(User $user): bool
    {
        return $user->hasPermission('hrms.holidays.view')
            || $user->hasPermission('hrms.holidays.manage');
    }
}
