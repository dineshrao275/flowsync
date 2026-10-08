<?php

namespace App\Policies\Hrms\Leave;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\User;
use App\Services\Hrms\HrmsScope;

/**
 * Leave/HRMS — who may read balances.
 *
 * Resolved through the array form (`authorize('view', [LeaveBalance::class,
 * $employee])`, the AttendanceDayPolicy precedent) because the thing being
 * authorized is a person's curve, not one row. The answer is the shared
 * `HrmsScope::coversEmployee` — self-service included (a caller with no view
 * grant still covers their own record), and anyone holding the
 * `hrms.leave.view` scope variants, the legacy slug or `manage` reads what
 * that scope covers. The client-supplied `employee_id` on the My-leave read
 * therefore resolves against the caller's real scope. Accruals are
 * manage-only at the route, so this policy answers reads alone.
 */
class LeaveBalancePolicy
{
    public function view(User $viewer, Employee $employee): bool
    {
        return HrmsScope::coversEmployee($viewer, 'hrms.leave', $employee);
    }
}
