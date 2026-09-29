<?php

namespace App\Policies\Hrms\Leave;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\User;

/**
 * Leave/HRMS — who may read balances.
 *
 * Resolved through the array form (`authorize('view', [LeaveBalance::class,
 * $employee])`, the AttendanceDayPolicy precedent) because the thing being
 * authorized is a person's curve, not one row. Self-service included —
 * anyone else needs `hrms.leave.view` or `manage`. Accruals are manage-only
 * at the route, so this policy answers reads alone.
 */
class LeaveBalancePolicy
{
    public function view(User $viewer, Employee $employee): bool
    {
        return $this->isSelf($viewer, $employee)
            || $viewer->hasPermission('hrms.leave.view')
            || $viewer->hasPermission('hrms.leave.manage');
    }

    private function isSelf(User $viewer, Employee $employee): bool
    {
        return $employee->user_id !== null
            && (int) $employee->user_id === (int) $viewer->id;
    }
}
