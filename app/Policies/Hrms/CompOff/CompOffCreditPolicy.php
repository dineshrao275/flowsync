<?php

namespace App\Policies\Hrms\CompOff;

use App\Models\Hrms\CompOff\CompOffCredit;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;

/**
 * CompOff/HRMS — who may read banked time and grant it by hand.
 *
 * Resolved through the array form (`authorize('view', [CompOffCredit::class,
 * $employee])`, the AttendanceDayPolicy precedent) because the thing being
 * authorized is a person's bank, not one row. Self-service reads; manual
 * grants are `hrms.comp_off.manage` — nobody banks their own time.
 */
class CompOffCreditPolicy
{
    public function view(User $viewer, Employee $employee): bool
    {
        return $this->isSelf($viewer, $employee)
            || $viewer->hasPermission('hrms.comp_off.view')
            || $viewer->hasPermission('hrms.comp_off.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.comp_off.manage');
    }

    private function isSelf(User $viewer, Employee $employee): bool
    {
        return $employee->user_id !== null
            && (int) $employee->user_id === (int) $viewer->id;
    }
}
