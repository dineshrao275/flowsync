<?php

namespace App\Policies\Hrms\Holiday;

use App\Models\Hrms\Holiday\Holiday;
use App\Models\User;

/**
 * Holiday/HRMS — who may read and manage individual holidays.
 *
 * Same shape as the calendar policy: shared reference data, `view` reads
 * and `manage` writes. Nested creation authorizes against the parent
 * calendar's update — adding to a catalogue you may not edit would let a
 * reader smuggle rows into it.
 */
class HolidayPolicy
{
    public function view(User $user, Holiday $holiday): bool
    {
        return $user->hasPermission('hrms.holidays.view')
            || $user->hasPermission('hrms.holidays.manage');
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $user->hasPermission('hrms.holidays.manage');
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $user->hasPermission('hrms.holidays.manage');
    }
}
