<?php

namespace App\Policies;

use App\Enums\WorkspaceMemberRole;
use App\Models\Label;
use App\Models\User;

class LabelPolicy
{
    public function view(User $user, Label $label): bool
    {
        $workspace = $label->workspace;

        return $user->hasPermission('workspaces.manage') || $workspace->isMember($user);
    }

    public function create(User $user, Label $label): bool
    {
        return $this->canManage($user, $label);
    }

    public function update(User $user, Label $label): bool
    {
        return $this->canManage($user, $label);
    }

    public function delete(User $user, Label $label): bool
    {
        return $this->canManage($user, $label);
    }

    private function canManage(User $user, Label $label): bool
    {
        $workspace = $label->workspace;

        if ($user->hasPermission('workspaces.manage')) {
            return true;
        }

        return in_array($workspace->memberRole($user), [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
        ], true);
    }
}
