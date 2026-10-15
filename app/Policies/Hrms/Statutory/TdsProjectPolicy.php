<?php

namespace App\Policies\Hrms\Statutory;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Statutory\TdsProject;
use App\Models\User;

/**
 * Statutory/HRMS — who may read or move a TDS projection.
 *
 * Projections run on manage alone (an annual tax picture is HR work);
 * reading one row is self-or-manage, so a person sees their own quarters
 * without seeing anyone else's. Surrendering deposits money against a
 * challan — manage alone, like every other movement of funds.
 */
class TdsProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    public function view(User $user, TdsProject $project): bool
    {
        return $this->isSelf($user, $project) || $this->canManage($user);
    }

    public function project(User $user): bool
    {
        return $this->canManage($user);
    }

    public function surrender(User $user, TdsProject $project): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return $user->hasPermission('hrms.payroll.statutory.manage');
    }

    private function isSelf(User $user, TdsProject $project): bool
    {
        $employee = $project->employee ?? Employee::find($project->employee_id);

        return $employee !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === (int) $user->id;
    }
}
