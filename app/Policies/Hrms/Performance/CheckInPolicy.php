<?php

namespace App\Policies\Hrms\Performance;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\CheckIn;
use App\Models\User;

/**
 * Performance/HRMS — who may read or file a check-in.
 *
 * Self, the owner's manager, or the view permission to read; self or
 * manage to file. A check-in is a dated note from the person (or about
 * them, from HR) — never from a peer, which is what the feedback system
 * is for.
 */
class CheckInPolicy
{
    public function viewAny(User $user): bool
    {
        return Employee::where('user_id', $user->id)->exists() || $this->canView($user);
    }

    public function view(User $user, CheckIn $checkIn): bool
    {
        return $this->isSelf($user, $checkIn)
            || $this->isManagerOf($user, $checkIn)
            || $this->canView($user);
    }

    public function create(User $user, Employee $employee): bool
    {
        return $this->isSelfEmployee($user, $employee)
            || $user->hasPermission('hrms.performance.manage');
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.performance.view')
            || $user->hasPermission('hrms.performance.manage');
    }

    private function isSelf(User $user, CheckIn $checkIn): bool
    {
        $employee = $checkIn->employee ?? Employee::find($checkIn->employee_id);

        return $this->isSelfEmployee($user, $employee);
    }

    private function isManagerOf(User $user, CheckIn $checkIn): bool
    {
        $employee = $checkIn->employee ?? Employee::find($checkIn->employee_id);

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
