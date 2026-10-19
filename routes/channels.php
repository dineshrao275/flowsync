<?php

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

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
        Log::debug("WebSocket auth rejected for channel user.{$id}: tenant inactive", ['user_id' => $user->id]);

        return false;
    }

    $allowed = (int) $user->id === (int) $id;
    Log::debug("WebSocket auth for channel user.{$id}: ".($allowed ? 'authorized' : 'denied'), ['user_id' => $user->id]);

    return $allowed ? ['id' => $user->id, 'name' => $user->name] : false;
});

Broadcast::channel('workspace.{id}', function (User $user, $workspaceId) use ($isTenantActive) {
    if (! $isTenantActive($user)) {
        return false;
    }

    // Mirrors WorkspacePolicy::view: tenant admin OR member. A tenant admin sees every
    // workspace in the UI without a membership row, so they must be able to listen too.
    return ($user->hasPermission('workspaces.manage') && Workspace::whereKey((int) $workspaceId)->exists())
        || WorkspaceMember::where('workspace_id', (int) $workspaceId)->where('user_id', (int) $user->id)->exists()
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});

Broadcast::channel('project.{id}', function (User $user, $projectId) use ($isTenantActive) {
    if (! $isTenantActive($user)) {
        return false;
    }

    // Mirrors ProjectPolicy::view: tenant admin OR member. Without the admin leg, an admin
    // who opens a project they are not a member of (allowed by the API) was refused the
    // project channel with a 403 and got no live board/comment updates.
    return ($user->hasPermission('workspaces.manage') && Project::whereKey((int) $projectId)->exists())
        || ProjectMember::where('project_id', (int) $projectId)->where('user_id', (int) $user->id)->exists()
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});

Broadcast::channel('support.ticket.{id}', function (User $user, $id) use ($isTenantActive) {
    if (! $isTenantActive($user)) {
        Log::debug("WebSocket auth rejected for channel support.ticket.{$id}: tenant inactive", ['user_id' => $user->id]);

        return false;
    }

    $ticket = SupportTicket::find((int) $id);
    if (! $ticket) {
        Log::debug("WebSocket auth rejected for channel support.ticket.{$id}: ticket not found", ['user_id' => $user->id]);

        return false;
    }

    if ($user->is_super_admin) {
        Log::debug("WebSocket auth for channel support.ticket.{$id}: super admin authorized", ['user_id' => $user->id]);

        return ['id' => $user->id, 'name' => $user->name, 'type' => 'staff'];
    }

    $tenantId = app(TenantContext::class)->currentId()
        ?? (request()->hasSession() ? (request()->session()->get('impersonate.tenant_id') ?? request()->session()->get('login.tenant_id')) : null);

    if ($tenantId && (int) $ticket->tenant_id === (int) $tenantId) {
        if ($user->hasPermission('support.manage') || (int) $ticket->created_by_user_id === (int) $user->id) {
            return ['id' => $user->id, 'name' => $user->name, 'type' => 'tenant'];
        }
    }

    return false;
});

Broadcast::channel('support.ticket.{id}.staff', function (User $user, $id) {
    if (! $user->is_super_admin) {
        return false;
    }

    $ticket = SupportTicket::find((int) $id);
    if (! $ticket) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name, 'type' => 'staff'];
});
