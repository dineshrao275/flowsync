<?php

namespace App\Policies\Hrms\Holiday;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Holiday\HolidayOptionalHoliday;
use App\Models\User;

/**
 * Holiday/HRMS — who may declare and read optional answers.
 *
 * Declaring is self-service (any employee answers for themselves, managers
 * file for others with `manage`); reading the queue takes `manage`, while
 * anyone reads their own answers. The resolved per-employee view answers
 * separately, where the grid — not the answer rows — is the subject.
 */
class HolidayOptionalHolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasEmployee($user) || $this->canManage($user);
    }

    public function view(User $user, HolidayOptionalHoliday $answer): bool
    {
        return $this->isSelf($user, $answer->employee) || $this->canManage($user);
    }

    public function create(User $user, Employee $employee): bool
    {
        return $this->isSelf($user, $employee) || $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return $user->hasPermission('hrms.holidays.manage');
    }

    private function hasEmployee(User $user): bool
    {
        return Employee::where('user_id', $user->id)->exists();
    }

    private function isSelf(User $user, ?Employee $employee): bool
    {
        return $employee !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === (int) $user->id;
    }
}
