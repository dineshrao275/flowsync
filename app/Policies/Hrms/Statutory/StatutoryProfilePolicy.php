<?php

namespace App\Policies\Hrms\Statutory;

use App\Models\Hrms\Employee\Employee;
use App\Models\User;

/**
 * Statutory/HRMS — who may read or write one person's identifiers.
 *
 * Reads take self or manage: the person in the record may see their own
 * (masked) identifiers, and nobody else's without the manage permission.
 * Writes — and the cleartext reveal — take manage alone. Being the person
 * in the record never grants cleartext: the masked read is the self
 * surface, and anything stronger is an HR decision with an access row.
 */
class StatutoryProfilePolicy
{
    public function view(User $user, Employee $employee): bool
    {
        return $this->isSelf($user, $employee)
            || $user->hasPermission('hrms.payroll.statutory.manage');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasPermission('hrms.payroll.statutory.manage');
    }

    public function reveal(User $user, Employee $employee): bool
    {
        return $user->hasPermission('hrms.payroll.statutory.manage');
    }

    private function isSelf(User $user, Employee $employee): bool
    {
        return $employee->user_id !== null
            && (int) $employee->user_id === (int) $user->id;
    }
}
