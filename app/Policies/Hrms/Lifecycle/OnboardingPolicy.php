<?php

namespace App\Policies\Hrms\Lifecycle;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OnboardingCase;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Hrms\Lifecycle\OnboardingTemplate;
use App\Models\User;

/**
 * Lifecycle/HRMS — who may do what to an onboarding template, case, or task.
 *
 * One base for the three concrete policies because the answers are the same
 * shape and differ only in the model — the Org precedent (OrgRecordPolicy +
 * markers), since Laravel resolves a model’s policy by convention and a
 * single OnboardingPolicy would be discoverable by none of the three models.
 *
 * Three questions, not one role check: `view` answers whether the caller may
 * see the row (self-service included — a hire reading their own checklist
 * needs no permission from anyone); task actions answer owner-or-manage
 * (mirroring what the service enforces, so the 403 arrives before the 422);
 * and template/case lifecycle answers are manage-only.
 */
class OnboardingPolicy
{
    /**
     * Anyone who may reach the surface: a directory reader, or a person with
     * their own employment record to be onboarded under.
     */
    public function viewAny(User $user): bool
    {
        return $this->canView($user) || $this->hasEmployee($user);
    }

    /**
     * Read one case: the person it belongs to, or anyone with the view
     * permission. Templates are catalogue rows — view permission only.
     */
    public function view(User $user, OnboardingCase|OnboardingTemplate $record): bool
    {
        if ($record instanceof OnboardingTemplate) {
            return $this->canView($user);
        }

        return $this->isSelf($user, $record->employee) || $this->canView($user);
    }

    /**
     * Start a case or change the catalogue: HR’s job, not the hire’s.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.onboarding.manage');
    }

    public function update(User $user, OnboardingTemplate $template): bool
    {
        return $user->hasPermission('hrms.onboarding.manage');
    }

    public function delete(User $user, OnboardingTemplate $template): bool
    {
        return $user->hasPermission('hrms.onboarding.manage');
    }

    /**
     * Work an item: its owner, or someone with the manage permission. The
     * service re-checks this itself (and adds the mandatory-waive rule), so
     * this 403 is the early answer and the service 422 is the precise one.
     */
    public function completeTask(User $user, OnboardingCaseTask $task): bool
    {
        return $this->isOwner($user, $task) || $user->hasPermission('hrms.onboarding.manage');
    }

    public function waiveTask(User $user, OnboardingCaseTask $task): bool
    {
        return $this->isOwner($user, $task) || $user->hasPermission('hrms.onboarding.manage');
    }

    /**
     * Close or abandon a case: a decision about someone’s onboarding, so
     * manage-only even for the hire themselves.
     */
    public function complete(User $user, OnboardingCase $case): bool
    {
        return $user->hasPermission('hrms.onboarding.manage');
    }

    public function cancel(User $user, OnboardingCase $case): bool
    {
        return $user->hasPermission('hrms.onboarding.manage');
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.onboarding.view')
            || $user->hasPermission('hrms.onboarding.manage');
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

    private function isOwner(User $user, OnboardingCaseTask $task): bool
    {
        if ($task->owner_employee_id === null) {
            return false;
        }

        $owner = $task->owner ?? Employee::find($task->owner_employee_id);

        return $this->isSelf($user, $owner);
    }
}
