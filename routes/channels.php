<?php

use App\Models\ProjectMember;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Broadcast;

$isTenantActive = function (User $user): bool {
    if ($user->is_super_admin && ! app(TenantContext::class)->impersonating()) {
        return true;
    }

    $tenantId = app(TenantContext::class)->currentId()
        ?? (request()->hasSession() ? (request()->session()->get('impersonate.tenant_id') ?? request()->session()->get('login.tenant_id')) : null);

    if ($tenantId !== null) {
        $tenant = Tenant::find($tenantId);

        return $tenant && $tenant->isServiceable();
    }

    return true;
};

Broadcast::channel('user.{id}', function (User $user, $id) use ($isTenantActive) {
    if (! $isTenantActive($user)) {
        return false;
    }

    return (int) $user->id === (int) $id ? ['id' => $user->id, 'name' => $user->name] : false;
});

Broadcast::channel('workspace.{id}', function (User $user, $workspaceId) use ($isTenantActive) {
    if (! $isTenantActive($user)) {
        return false;
    }

    return WorkspaceMember::where('workspace_id', (int) $workspaceId)
        ->where('user_id', (int) $user->id)
        ->exists()
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});

Broadcast::channel('project.{id}', function (User $user, $projectId) use ($isTenantActive) {
    if (! $isTenantActive($user)) {
        return false;
    }

    return ProjectMember::where('project_id', (int) $projectId)
        ->where('user_id', (int) $user->id)
        ->exists()
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});
