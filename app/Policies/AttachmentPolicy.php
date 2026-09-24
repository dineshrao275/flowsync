<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $this->projectCan($user, $attachment->task->project, 'tasks.view');
    }

    public function create(User $user, Task $task): bool
    {
        return $this->projectCan($user, $task->project, 'attachments.create');
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        if ($user->id === $attachment->user_id || $this->isTenantAdmin($user)) {
            return true;
        }

        return $this->projectCan($user, $attachment->task->project, 'attachments.delete');
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
