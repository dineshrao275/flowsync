<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $this->isTenantAdmin($user) || $project->isMember($user);
    }

    public function edit(User $user, Project $project): bool
    {
        return $this->can($user, $project, 'projects.edit');
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->can($user, $project, 'projects.delete');
    }

    public function settings(User $user, Project $project): bool
    {
        return $this->can($user, $project, 'projects.settings');
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $this->can($user, $project, 'members.manage');
    }

    public function manageWorkflow(User $user, Project $project): bool
    {
        return $this->can($user, $project, 'projects.settings');
    }

    public function createTask(User $user, Project $project): bool
    {
        return $this->can($user, $project, 'tasks.create');
    }

    public function archive(User $user, Project $project): bool
    {
        return $this->edit($user, $project);
    }

    public function restore(User $user, Project $project): bool
    {
        return $this->edit($user, $project);
    }

    private function can(User $user, Project $project, string $permission): bool
    {
        if ($this->isTenantAdmin($user)) {
            return true;
        }

        $role = $project->memberRole($user);

        return $role !== null && $role->hasPermission($permission);
    }

    private function isTenantAdmin(User $user): bool
    {
        return $user->hasPermission('workspaces.manage');
    }
}
