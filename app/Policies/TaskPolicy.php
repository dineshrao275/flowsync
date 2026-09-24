<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return $this->can($user, $task, 'tasks.view');
    }

    public function edit(User $user, Task $task): bool
    {
        return $this->can($user, $task, 'tasks.edit');
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->can($user, $task, 'tasks.delete');
    }

    public function assign(User $user, Task $task): bool
    {
        return $this->can($user, $task, 'tasks.assign');
    }

    public function move(User $user, Task $task): bool
    {
        return $this->can($user, $task, 'tasks.move');
    }

    private function can(User $user, Task $task, string $permission): bool
    {
        if ($this->isTenantAdmin($user)) {
            return true;
        }

        $role = $task->project->memberRole($user);

        return $role !== null && $role->hasPermission($permission);
    }

    private function isTenantAdmin(User $user): bool
    {
        return $user->hasPermission('workspaces.manage');
    }
}
