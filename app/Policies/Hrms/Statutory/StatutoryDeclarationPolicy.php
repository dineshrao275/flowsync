<?php

namespace App\Policies\Hrms\Statutory;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Statutory\StatutoryDeclaration;
use App\Models\User;

/**
 * Statutory/HRMS — who may file or decide an exemption claim.
 *
 * Filing and submitting are self-or-manage (a claim is the employee's own
 * paperwork); verifying and rejecting are manage-alone (a claim becomes a
 * tax fact only through HR). Reads follow the same split, so the queue
 * stays an HR screen while a person still sees their own claims.
 */
class StatutoryDeclarationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hrms.payroll.statutory.manage');
    }

    public function view(User $user, StatutoryDeclaration $declaration): bool
    {
        return $this->isSelf($user, $declaration) || $this->canDecide($user);
    }

    public function file(User $user, Employee $employee): bool
    {
        return $this->isSelfEmployee($user, $employee) || $this->canDecide($user);
    }

    public function submit(User $user, StatutoryDeclaration $declaration): bool
    {
        return $this->isSelf($user, $declaration) || $this->canDecide($user);
    }

    public function decide(User $user, StatutoryDeclaration $declaration): bool
    {
        return $this->canDecide($user);
    }

    private function canDecide(User $user): bool
    {
        return $user->hasPermission('hrms.payroll.statutory.manage');
    }

    private function isSelf(User $user, StatutoryDeclaration $declaration): bool
    {
        return $declaration->employee !== null && $this->isSelfEmployee($user, $declaration->employee);
    }

    private function isSelfEmployee(User $user, ?Employee $employee): bool
    {
        return $employee !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === (int) $user->id;
    }
}
