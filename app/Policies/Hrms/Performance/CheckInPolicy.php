<?php

namespace App\Policies\Hrms\Performance;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\CheckIn;
use App\Models\User;
use App\Services\Hrms\HrmsScope;

/**
 * Performance/HRMS — who may read or file a check-in.
 *
 * Self, the owner's manager, the view scope (`HrmsScope`), or a talent
 * manager to read; self or manage to file. A check-in is a dated note from
 * the person (or about them, from HR) — never from a peer, which is what
 * the feedback system is for.
 */
class CheckInPolicy
{
    public function viewAny(User $user): bool
    {
        return Employee::where('user_id', $user->id)->exists() || $this->canRead($user);
    }

    public function view(User $user, CheckIn $checkIn): bool
    {
        return $this->isSelf($user, $checkIn)
            || $this->isManagerOf($user, $checkIn)
            || $this->canViewScoped($user, $checkIn);
    }

    public function create(User $user, Employee $employee): bool
    {
        return $this->isSelfEmployee($user, $employee)
            || $user->hasPermission('hrms.performance.manage');
    }

    /**
     * Scope-aware read for a specific check-in, mirroring the cycle list
     * clamp so what the list offers and a show opens never disagree.
     */
    private function canViewScoped(User $user, CheckIn $checkIn): bool
    {
        if ($user->hasPermission('hrms.talent.manage')) {
            return true;
        }

        $employee = $checkIn->employee ?? Employee::find($checkIn->employee_id);

        return $employee !== null
            && HrmsScope::coversEmployee($user, 'hrms.performance', $employee);
    }

    private function canRead(User $user): bool
    {
        return HrmsScope::canRead($user, 'hrms.performance')
            || $user->hasPermission('hrms.talent.manage');
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
