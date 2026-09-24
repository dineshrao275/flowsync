<?php

use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id ? ['id' => $user->id, 'name' => $user->name] : false;
});

Broadcast::channel('workspace.{id}', function (User $user, $workspaceId) {
    return WorkspaceMember::where('workspace_id', (int) $workspaceId)
        ->where('user_id', (int) $user->id)
        ->exists()
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});

Broadcast::channel('project.{id}', function (User $user, $projectId) {
    return ProjectMember::where('project_id', (int) $projectId)
        ->where('user_id', (int) $user->id)
        ->exists()
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});
