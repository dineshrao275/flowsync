<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class CommentPolicy
{
    public function create(User $user, Task $task): bool
    {
        return $this->projectCan($user, $task->project, 'comments.create');
    }

    public function update(User $user, Comment $comment): bool
    {
        return $this->ownsOrCan($user, $comment, 'comments.edit');
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $this->ownsOrCan($user, $comment, 'comments.delete');
    }

    private function ownsOrCan(User $user, Comment $comment, string $permission): bool
    {
        if ($user->id === $comment->user_id || $this->isTenantAdmin($user)) {
            return true;
        }

        return $this->projectCan($user, $comment->task->project, $permission);
    }

    private function projectCan(User $user, Project $project, string $permission): bool
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
