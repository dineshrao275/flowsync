<?php

namespace App\Policies\Hrms\Performance;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\ReviewSummary;
use App\Models\User;

/**
 * Performance/HRMS — who may read, file, or seal a review write-up.
 *
 * The viewer depends on visibility and anonymity, not just identity: a
 * manager sees their reports' drafts; the owner sees shared write-ups
 * (their own words always — the presenter, not this policy, withholds the
 * manager's half of a hidden one); talent managers see everything for
 * calibration; talent viewers see shared write-ups; a peer never sees
 * another person's rating. Filing and sealing take the manager or manage.
 */
class ReviewSummaryPolicy
{
    public function viewAny(User $user): bool
    {
        return Employee::where('user_id', $user->id)->exists()
            || $user->hasPermission('hrms.talent.view')
            || $this->canManage($user);
    }

    public function view(User $user, ReviewSummary $review): bool
    {
        if ($this->isManagerOf($user, $review) || $this->canManage($user)) {
            return true;
        }

        if ($this->isSelf($user, $review)) {
            return true;
        }

        return $user->hasPermission('hrms.talent.view')
            && $review->visibility_to_employee->value === 'shared';
    }

    public function file(User $user, Employee $employee): bool
    {
        return $this->isManagerOfEmployee($user, $employee) || $this->canManage($user);
    }

    public function update(User $user, ReviewSummary $review): bool
    {
        return $this->isManagerOf($user, $review) || $this->canManage($user);
    }

    public function acknowledge(User $user, ReviewSummary $review): bool
    {
        return $this->isManagerOf($user, $review) || $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return $user->hasPermission('hrms.talent.manage')
            || $user->hasPermission('hrms.performance.manage');
    }

    private function isSelf(User $user, ReviewSummary $review): bool
    {
        $employee = $review->employee ?? Employee::find($review->employee_id);

        return $employee !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === (int) $user->id;
    }

    private function isManagerOf(User $user, ReviewSummary $review): bool
    {
        $employee = $review->employee ?? Employee::find($review->employee_id);

        return $employee !== null && $this->isManagerOfEmployee($user, $employee);
    }

    private function isManagerOfEmployee(User $user, ?Employee $employee): bool
    {
        return $employee !== null
            && $employee->manager !== null
            && $employee->manager->user_id !== null
            && (int) $employee->manager->user_id === (int) $user->id;
    }
}
