<?php

namespace App\Policies\Hrms\Performance;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\User;

/**
 * Performance/HRMS — who may read or change a goal.
 *
 * Reads take self, the owner's manager, or the view permission: a goal is
 * personal, and the two people in the reporting line around it may always
 * see it. Updates take self while the goal is still a draft, or manage —
 * a submitted goal is a commitment, and commitments change through HR,
 * not through the author quietly rewriting them.
 */
class PerformanceGoalPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasEmployee($user) || $this->canView($user);
    }

    public function view(User $user, PerformanceGoal $goal): bool
    {
        return $this->isSelf($user, $goal)
            || $this->isManagerOf($user, $goal)
            || $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->hasEmployee($user)
            || $user->hasPermission('hrms.performance.manage');
    }

    /**
     * File a goal for someone: the person themselves, or manage filing for
     * a report. Creation opens the door; this names whose record it lands
     * in.
     */
    public function file(User $user, Employee $employee): bool
    {
        return $this->isSelfEmployee($user, $employee)
            || $user->hasPermission('hrms.performance.manage');
    }

    public function update(User $user, PerformanceGoal $goal): bool
    {
        if ($user->hasPermission('hrms.performance.manage')) {
            return true;
        }

        return $this->isSelf($user, $goal) && $goal->status->value === 'draft';
    }

    public function refresh(User $user, PerformanceGoal $goal): bool
    {
        return $this->isSelf($user, $goal)
            || $this->isManagerOf($user, $goal)
            || $user->hasPermission('hrms.performance.manage');
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.performance.view')
            || $user->hasPermission('hrms.performance.manage');
    }

    private function hasEmployee(User $user): bool
    {
        return Employee::where('user_id', $user->id)->exists();
    }

    private function isSelf(User $user, PerformanceGoal $goal): bool
    {
        $employee = $goal->employee ?? Employee::find($goal->employee_id);

        return $this->isSelfEmployee($user, $employee);
    }

    private function isManagerOf(User $user, PerformanceGoal $goal): bool
    {
        $employee = $goal->employee ?? Employee::find($goal->employee_id);

        return $employee !== null
            && $employee->manager !== null
            && $this->isSelfEmployee($user, $employee->manager);
    }

    private function isSelfEmployee(User $user, ?Employee $employee): bool
    {
        return $employee !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === (int) $user->id;
    }
}
