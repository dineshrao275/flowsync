<?php

namespace App\Policies\Hrms\CompOff;

use App\Models\Hrms\CompOff\CompOffCredit;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\HrmsScope;

/**
 * CompOff/HRMS — who may read banked time and grant it by hand.
 *
 * Resolved through the array form (`authorize('view', [CompOffCredit::class,
 * $employee])`, the AttendanceDayPolicy precedent) because the thing being
 * authorized is a person's bank, not one row. The answer is the shared
 * `HrmsScope::coversEmployee` — self-service included (a caller with no view
 * grant still covers their own record), and anyone holding the
 * `hrms.comp_off.view` scope variants, the legacy slug or `manage` reads what
 * that scope covers. The client-supplied `employee_id` on the My-comp-off
 * read therefore resolves against the caller's real scope. Manual grants
 * are `hrms.comp_off.manage` — nobody banks their own time.
 */
class CompOffCreditPolicy
{
    public function view(User $viewer, Employee $employee): bool
    {
        return HrmsScope::coversEmployee($viewer, 'hrms.comp_off', $employee);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.comp_off.manage');
    }
}
