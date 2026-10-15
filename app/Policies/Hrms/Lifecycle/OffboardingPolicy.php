<?php

namespace App\Policies\Hrms\Lifecycle;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\Hrms\Lifecycle\OffboardingCaseTask;
use App\Models\User;

/**
 * Lifecycle/HRMS — who may do what to an offboarding case or task.
 *
 * The onboarding shape with one stricter answer: `clear` is the sign-off on
 * an exit, so it takes `hrms.offboarding.manage` and nothing else — not the
 * task owner, not a directory reader. A clearance signed by the person
 * leaving would be a signature on their own hope.
 */
class OffboardingPolicy
{
    /**
     * Anyone who may reach the surface: a directory reader, or a person with
     * their own employment record to exit under.
     */
    public function viewAny(User $user): bool
    {
        return $this->canView($user) || $this->hasEmployee($user);
    }

    /**
     * Read one case: the person leaving, or anyone with the view permission.
     */
    public function view(User $user, OffboardingCase $case): bool
    {
        return $this->isSelf($user, $case->employee) || $this->canView($user);
    }

    /**
     * Open an exit run: HR’s job, not the leaver’s.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.offboarding.manage');
    }

    /**
     * Work an item: its owner, or someone with the manage permission.
     */
    public function completeTask(User $user, OffboardingCaseTask $task): bool
    {
        return $this->isOwner($user, $task) || $user->hasPermission('hrms.offboarding.manage');
    }

    public function complete(User $user, OffboardingCase $case): bool
    {
        return $user->hasPermission('hrms.offboarding.manage');
    }

    public function cancel(User $user, OffboardingCase $case): bool
    {
        return $user->hasPermission('hrms.offboarding.manage');
    }

    /**
     * Sign the exit off. Manage and nothing else: see the class docblock for
     * why the leaver’s own signature would be worthless here.
     */
    public function clear(User $user, OffboardingCase $case): bool
    {
        return $user->hasPermission('hrms.offboarding.manage');
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.offboarding.view')
            || $user->hasPermission('hrms.offboarding.manage');
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

    private function isOwner(User $user, OffboardingCaseTask $task): bool
    {
        if ($task->owner_employee_id === null) {
            return false;
        }

        $owner = $task->owner ?? Employee::find($task->owner_employee_id);

        return $this->isSelf($user, $owner);
    }
}
