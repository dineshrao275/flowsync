<?php

namespace App\Policies;

use App\Enums\WorkspaceMemberRole;
use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $this->isTenantAdmin($user) || $workspace->isMember($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('workspaces.create');
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->isTenantAdmin($user) || $this->isManager($workspace, $user);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $this->isTenantAdmin($user) || $this->isManager($workspace, $user);
    }

    public function createProject(User $user, Workspace $workspace): bool
    {
        return $this->isTenantAdmin($user) || $this->isManager($workspace, $user);
    }

    public function archive(User $user, Workspace $workspace): bool
    {
        return $this->isTenantAdmin($user) || $this->isOwner($workspace, $user);
    }

    public function restore(User $user, Workspace $workspace): bool
    {
        return $this->archive($user, $workspace);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->archive($user, $workspace);
    }

    private function isTenantAdmin(User $user): bool
    {
        return $user->hasPermission('workspaces.manage');
    }

    private function isManager(Workspace $workspace, User $user): bool
    {
        return in_array($workspace->memberRole($user), [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
        ], true);
    }

    private function isOwner(Workspace $workspace, User $user): bool
    {
        return $workspace->memberRole($user) === WorkspaceMemberRole::Owner;
    }
}
