<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DependencyController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\ProjectRoleController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ResetPasswordController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskMoveController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantSubscriptionController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkLogController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceMemberController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('auth/forgot-password', [ForgotPasswordController::class, 'create'])->middleware('throttle:6,1');
    Route::post('auth/reset-password', [ResetPasswordController::class, 'update'])->middleware('throttle:6,1');

    Route::middleware(['switch_tenant', 'auth', 'tenant'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('theme', [ThemeController::class, 'show']);
        Route::put('theme', [ThemeController::class, 'update'])->middleware('permission:settings.theme');

        // Personal notifications (self-scoped by user_id; no tenant_context needed).
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread', [NotificationController::class, 'unread']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllRead']);

        // Tenant-facing profile (self-scoped via TenantContext; no tenant_context
        // needed because a tenant user resolves their own central tenant row).
        Route::get('tenant/profile', [TenantController::class, 'selfProfile']);

        // Phase 9: global multi-entity search. Lives OUTSIDE tenant_context so a
        // non-impersonating super admin can search across all tenants (the
        // controller fans out over the central tenancy index via TenantDatabaseManager).
        Route::get('search/global', GlobalSearchController::class)->middleware('permission:workspaces.view');

        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:users.manage');
        Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])->middleware('permission:users.manage');

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.manage');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.manage');

        Route::middleware('super_admin')->group(function () {
            // Phase 13: tenancy platform (central DB index across tenant DBs).
            Route::get('tenants', [TenantController::class, 'index']);
            Route::post('tenants', [TenantController::class, 'store']);
            Route::get('tenants/{tenant}', [TenantController::class, 'show']);
            Route::put('tenants/{tenant}', [TenantController::class, 'update']);
            Route::get('tenants/{tenant}/profile', [TenantController::class, 'getProfile']);
            Route::put('tenants/{tenant}/profile', [TenantController::class, 'updateProfile']);
            Route::get('tenants/{tenant}/users', [TenantController::class, 'users']);

            // Phase 14: subscription catalog + per-tenant subscription lifecycle.
            Route::get('plans', [PlanController::class, 'index']);
            Route::post('plans', [PlanController::class, 'store']);
            Route::put('plans/{plan}', [PlanController::class, 'update']);
            Route::delete('plans/{plan}', [PlanController::class, 'destroy']);

            Route::get('tenants/{tenant}/subscription', [TenantSubscriptionController::class, 'show']);
            Route::post('tenants/{tenant}/subscription', [TenantSubscriptionController::class, 'assign']);
            Route::post('tenants/{tenant}/subscription/trial', [TenantSubscriptionController::class, 'startTrial']);
            Route::post('tenants/{tenant}/subscription/cancel', [TenantSubscriptionController::class, 'cancel']);
            Route::post('tenants/{tenant}/subscription/renew', [TenantSubscriptionController::class, 'renew']);
            Route::post('tenants/{tenant}/subscription/suspend', [TenantSubscriptionController::class, 'suspend']);
            Route::get('tenants/{tenant}/subscription/events', [TenantSubscriptionController::class, 'events']);

            Route::post('impersonate', [ImpersonationController::class, 'start']);
        });

        Route::post('impersonate/stop', [ImpersonationController::class, 'stop']);
    });

    Route::middleware(['switch_tenant', 'auth', 'tenant', 'tenant_context'])->group(function () {
        // Phase 1+: workspace management. Object-level authorization is
        // enforced by WorkspacePolicy (membership roles owner/admin/member);
        // 'tenant_context' rejects non-impersonating super admins.
        Route::middleware('permission:workspaces.view')->group(function () {
            Route::get('workspaces', [WorkspaceController::class, 'index']);
            Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show']);
            Route::put('workspaces/{workspace}', [WorkspaceController::class, 'update']);
            Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy']);
            Route::post('workspaces/{workspace}/archive', [WorkspaceController::class, 'archive']);
            Route::post('workspaces/{workspace}/restore', [WorkspaceController::class, 'restore']);

            Route::get('workspaces/{workspace}/members', [WorkspaceMemberController::class, 'index']);
            Route::post('workspaces/{workspace}/members', [WorkspaceMemberController::class, 'store']);
            Route::put('workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'update']);
            Route::delete('workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'destroy']);

            Route::get('workspaces/{workspace}/labels', [LabelController::class, 'index']);
            Route::post('workspaces/{workspace}/labels', [LabelController::class, 'store']);
            Route::put('labels/{label}', [LabelController::class, 'update']);
            Route::delete('labels/{label}', [LabelController::class, 'destroy']);

            // Phase 2: projects. Index/store are scoped to a workspace
            // (object auth via WorkspacePolicy); the rest are project-scoped
            // and gated by ProjectPolicy (project-role permissions).
            Route::get('workspaces/{workspace}/projects', [ProjectController::class, 'index']);
            Route::post('workspaces/{workspace}/projects', [ProjectController::class, 'store']);

            Route::get('projects', [ProjectController::class, 'indexAll']);

            Route::get('projects/{project}', [ProjectController::class, 'show']);
            Route::put('projects/{project}', [ProjectController::class, 'update']);
            Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
            Route::post('projects/{project}/archive', [ProjectController::class, 'archive']);
            Route::post('projects/{project}/restore', [ProjectController::class, 'restore']);

            Route::get('projects/{project}/members', [ProjectMemberController::class, 'index']);
            Route::post('projects/{project}/members', [ProjectMemberController::class, 'store']);
            Route::put('projects/{project}/members/{user}', [ProjectMemberController::class, 'update']);
            Route::delete('projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy']);

            Route::get('projects/{project}/statuses', [StatusController::class, 'index']);
            Route::post('projects/{project}/statuses', [StatusController::class, 'store']);
            Route::put('projects/{project}/statuses/{status}', [StatusController::class, 'update']);
            Route::delete('projects/{project}/statuses/{status}', [StatusController::class, 'destroy']);

            // Phase 3: tasks. Board/list at the project index (view=board|list,
            // filters as query params); mutations gated by TaskPolicy (project-role
            // tasks.* permissions) + ProjectPolicy::createTask.
            Route::get('projects/{project}/tasks', [TaskController::class, 'index']);
            Route::post('projects/{project}/tasks', [TaskController::class, 'store']);
            Route::get('projects/{project}/tasks/{task}', [TaskController::class, 'show']);
            Route::put('projects/{project}/tasks/{task}', [TaskController::class, 'update']);
            Route::delete('projects/{project}/tasks/{task}', [TaskController::class, 'destroy']);
            Route::post('projects/{project}/tasks/{task}/move', [TaskMoveController::class, 'move']);

            // Phase 4: collaboration. Comments (thread + replies, ownership/role
            // policy, CommentSynced broadcast), dependencies (cycle-checked), and
            // attachments (upload/list/delete). All gated by per-task policies.
            Route::get('projects/{project}/tasks/{task}/comments', [CommentController::class, 'index']);
            Route::post('projects/{project}/tasks/{task}/comments', [CommentController::class, 'store']);
            Route::put('projects/{project}/tasks/{task}/comments/{comment}', [CommentController::class, 'update']);
            Route::delete('projects/{project}/tasks/{task}/comments/{comment}', [CommentController::class, 'destroy']);

            Route::get('projects/{project}/tasks/{task}/dependencies', [DependencyController::class, 'index']);
            Route::post('projects/{project}/tasks/{task}/dependencies', [DependencyController::class, 'store']);
            Route::delete('projects/{project}/tasks/{task}/dependencies/{dependency}', [DependencyController::class, 'destroy']);

            Route::get('projects/{project}/tasks/{task}/attachments', [AttachmentController::class, 'index']);
            Route::post('projects/{project}/tasks/{task}/attachments', [AttachmentController::class, 'store']);
            Route::delete('projects/{project}/tasks/{task}/attachments/{attachment}', [AttachmentController::class, 'destroy']);

            // Activity timeline (task + project feeds).
            Route::get('projects/{project}/tasks/{task}/activities', [ActivityController::class, 'task']);
            Route::get('projects/{project}/activities', [ActivityController::class, 'project']);

            // Phase 6: time tracking. Work logs per task (work_logs.* project-role
            // perms + own-log rules), plus project/workspace time summaries.
            Route::get('projects/{project}/tasks/{task}/work-logs', [WorkLogController::class, 'index']);
            Route::post('projects/{project}/tasks/{task}/work-logs', [WorkLogController::class, 'store']);
            Route::put('projects/{project}/tasks/{task}/work-logs/{workLog}', [WorkLogController::class, 'update']);
            Route::delete('projects/{project}/tasks/{task}/work-logs/{workLog}', [WorkLogController::class, 'destroy']);

            Route::get('projects/{project}/time-summary', [WorkLogController::class, 'projectTime']);
            Route::get('workspaces/{workspace}/time-summary', [WorkLogController::class, 'workspaceTime']);

            // Project-role catalog (tenant-level). Reading is open within the
            // domain so project member managers can populate a role picker.
            Route::get('project-roles', [ProjectRoleController::class, 'index']);
        });

        // Phase 7: discovery + reporting on the same tenant_context scope.
        // Global task search rides the domain `workspaces.view` permission;
        // dashboard and reports are gated by their own tenant permissions.
        Route::get('search/tasks', [SearchController::class, 'tasks'])->middleware('permission:workspaces.view');

        Route::get('dashboard', [DashboardController::class, '__invoke'])->middleware('permission:dashboard.view');

        Route::get('reports/overview', [ReportsController::class, 'overview'])->middleware('permission:reports.view');

        Route::post('workspaces', [WorkspaceController::class, 'store'])->middleware('permission:workspaces.create');
        Route::post('project-roles', [ProjectRoleController::class, 'store'])->middleware('permission:roles.manage');
        Route::put('project-roles/{role}', [ProjectRoleController::class, 'update'])->middleware('permission:roles.manage');
        Route::delete('project-roles/{role}', [ProjectRoleController::class, 'destroy'])->middleware('permission:roles.manage');
    });

    // Signed temporary download link for task attachments. Intentionally OUTSIDE
    // the auth/tenant groups so a fresh-browser-tab GET works; access is granted
    // by the signed URL itself (task/attachment bindings carry tenant scoping
    // when a session exists, and the signature is mandatory).
    Route::get('tasks/{task}/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->middleware('signed')
        ->name('attachments.download');
});

Route::get('/{path?}', function () {
    return view('app');
})->where('path', '^(?!api($|/)|up$).*');
