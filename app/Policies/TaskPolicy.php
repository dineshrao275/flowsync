<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Support\TaskScope;

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

    /**
     * Scope-aware task gate: hold a grant the check's row scope satisfies,
     * AND have the row inside it (own = assignee/reporter is the caller;
     * assigned = caller or a direct report).
     */
    private function can(User $user, Task $task, string $base): bool
    {
        if ($this->isTenantAdmin($user)) {
            return true;
        }

        $role = $task->project->memberRole($user);

        if ($role === null) {
            return false;
        }

        $scope = TaskScope::widestFor($role, $base);

        return $scope !== null && TaskScope::rowMatches($task, $user, $scope);
    }

    private function isTenantAdmin(User $user): bool
    {
        return $user->hasPermission('workspaces.manage');
    }
}
