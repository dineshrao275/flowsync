<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;

class WorkLogPolicy
{
    public function create(User $user, Task $task): bool
    {
        return $this->projectCan($user, $task->project, 'work_logs.create');
    }

    public function update(User $user, WorkLog $log): bool
    {
        return $this->ownsOrCan($user, $log, 'work_logs.edit');
    }

    public function delete(User $user, WorkLog $log): bool
    {
        return $this->ownsOrCan($user, $log, 'work_logs.delete');
    }

    private function ownsOrCan(User $user, WorkLog $log, string $permission): bool
    {
        if ($user->id !== $log->user_id && ! $this->isTenantAdmin($user)) {
            $role = $log->task->project->memberRole($user);

            return $role !== null
                && ($role->hasPermission($permission) || $role->hasPermission('work_logs.manage'));
        }

        return true;
    }

    private function projectCan(User $user, Project $project, string $permission): bool
    {
        if ($this->isTenantAdmin($user)) {
            return true;
        }

        $role = $project->memberRole($user);

        return $role !== null
            && ($role->hasPermission($permission) || $role->hasPermission('work_logs.manage'));
    }

    private function isTenantAdmin(User $user): bool
    {
        return $user->hasPermission('workspaces.manage');
    }
}
