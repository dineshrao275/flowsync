<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuditLogsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CmsController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DependencyController;
use App\Http\Controllers\FeatureManagementController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\MySubscriptionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\ProjectRoleController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ResetPasswordController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\SystemAnalyticsController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\SystemUsersController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskMoveController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantSubscriptionController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkLogController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceMemberController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('auth/forgot-password', [ForgotPasswordController::class, 'create'])->middleware('throttle:6,1');
    Route::post('auth/reset-password', [ResetPasswordController::class, 'update'])->middleware('throttle:6,1');

    // Phase 4 (onboarding): public self-registration — 403 unless
    // onboarding.enabled (config ONBOARDING_ENABLED, default off).
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:10,1');

    Route::middleware(['switch_tenant', 'auth', 'tenant'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('theme', [ThemeController::class, 'show'])->middleware('ensure_module:branding');
        Route::put('theme', [ThemeController::class, 'update'])->middleware(['permission:settings.theme', 'ensure_module:branding']);

        // Personal notifications (self-scoped by user_id; no tenant_context needed).
        // A platform super admin has no tenant database, so the controller
        // short-circuits these to empty payloads instead of querying them.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread', [NotificationController::class, 'unread']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllRead']);

        // Tenant-facing profile (self-scoped via TenantContext; no tenant_context
        // needed because a tenant user resolves their own central tenant row).
        Route::get('tenant/profile', [TenantController::class, 'selfProfile']);

        // Onboarding wizard (self-scoped via TenantContext; deliberately OUTSIDE
        // the onboarding_complete-gated domain group so an in-progress tenant can
        // finish the wizard).
        Route::get('onboarding', [OnboardingController::class, 'show']);
        Route::put('onboarding/step', [OnboardingController::class, 'updateStep']);
        Route::post('onboarding/complete', [OnboardingController::class, 'complete']);

        // Phase 14: tenant subscription self-service (self-scoped via TenantContext;
        // mutations gated to tenant admins in the controller). Also OUTSIDE the
        // onboarding gate so the wizard's subscription step can read plan data.
        Route::get('my-subscription', [MySubscriptionController::class, 'show']);
        Route::get('my-usage', [MySubscriptionController::class, 'usage']);
        Route::post('my-subscription/switch', [MySubscriptionController::class, 'switch']);
        Route::post('my-subscription/cancel', [MySubscriptionController::class, 'cancel']);
        Route::post('my-subscription/renew', [MySubscriptionController::class, 'renew']);
        Route::get('plans', [PlanController::class, 'index']);

        // Phase 9: global multi-entity search. Lives OUTSIDE tenant_context so a
        // non-impersonating super admin can search across all tenants (the
        // controller fans out over the central tenancy index via TenantDatabaseManager).
        Route::get('search/global', GlobalSearchController::class)->middleware(['permission:workspaces.view', 'ensure_module:global_search']);

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
            Route::get('tenants/{tenant}/stats', [TenantController::class, 'stats']);
            Route::delete('tenants/{tenant}', [TenantController::class, 'destroy']);
            Route::post('tenants/{tenant}/restore', [TenantController::class, 'restore'])->withTrashed();
            Route::post('tenants/{tenant}/suspend', [TenantController::class, 'suspend']);
            Route::post('tenants/{tenant}/activate', [TenantController::class, 'activate']);

            // Onboarding state per tenant (super-admin view + repair).
            Route::get('tenants/{tenant}/onboarding', [OnboardingController::class, 'showFor']);
            Route::put('tenants/{tenant}/onboarding', [OnboardingController::class, 'updateFor']);

            // Phase 14: subscription catalog + per-tenant subscription lifecycle.
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

            // Platform management (item 10): settings, accounts, audit feed,
            // cross-tenant analytics, and the module × plan feature grid.
            Route::get('system/settings', [SystemSettingsController::class, 'index']);
            Route::put('system/settings', [SystemSettingsController::class, 'update']);
            Route::apiResource('system/users', SystemUsersController::class)->only(['index', 'store']);
            Route::get('system/audit-logs', [AuditLogsController::class, 'index']);
            Route::get('system/analytics', [SystemAnalyticsController::class, 'index']);
            Route::get('system/features', [FeatureManagementController::class, 'index']);
            Route::put('system/features/{subscriptionPlan}', [FeatureManagementController::class, 'update']);
            Route::get('system/pages', [CmsController::class, 'index']);
            Route::post('system/pages', [CmsController::class, 'store']);
            Route::get('system/pages/{websitePage}', [CmsController::class, 'show']);
            Route::put('system/pages/{websitePage}', [CmsController::class, 'update']);
            Route::delete('system/pages/{websitePage}', [CmsController::class, 'destroy']);
            Route::post('system/pages/{websitePage}/publish', [CmsController::class, 'publish']);
            Route::post('system/pages/{websitePage}/unpublish', [CmsController::class, 'unpublish']);

            Route::post('impersonate', [ImpersonationController::class, 'start']);
        });

        Route::post('impersonate/stop', [ImpersonationController::class, 'stop']);
    });

    Route::middleware(['switch_tenant', 'auth', 'tenant', 'tenant_context', 'onboarding_complete'])->group(function () {
        // Tenant user administration. Lives in the tenant_context group because
        // the users table lives in the tenant's own database: a non-impersonating
        // super admin has nothing to route to and is rejected by the middleware
        // (an impersonating super admin is the impersonated tenant user, so the
        // tenant-context + `users.manage` checks both apply to them).
        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:users.manage');
        Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])->middleware('permission:users.manage');
        // The tenant's protected default user: shiftable by a tenant admin or a
        // super admin (onto another admin), and never deletable.
        Route::put('users/{user}/default', [UserController::class, 'makeDefault'])->middleware('permission:users.manage');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.manage');

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
            // perms + own-log rules), plus project/workspace time summaries. The
            // whole block is gated on the `time_tracking` subscription module.
            Route::group(['middleware' => ['ensure_module:time_tracking']], function () {
                Route::get('projects/{project}/tasks/{task}/work-logs', [WorkLogController::class, 'index']);
                Route::post('projects/{project}/tasks/{task}/work-logs', [WorkLogController::class, 'store']);
                Route::put('projects/{project}/tasks/{task}/work-logs/{workLog}', [WorkLogController::class, 'update']);
                Route::delete('projects/{project}/tasks/{task}/work-logs/{workLog}', [WorkLogController::class, 'destroy']);

                Route::get('projects/{project}/time-summary', [WorkLogController::class, 'projectTime']);
                Route::get('workspaces/{workspace}/time-summary', [WorkLogController::class, 'workspaceTime']);
            });

            // Project-role catalog (tenant-level). Reading is open within the
            // domain so project member managers can populate a role picker.
            Route::get('project-roles', [ProjectRoleController::class, 'index']);
        });

        // Phase 7: discovery + reporting on the same tenant_context scope.
        // Global task search rides the domain `workspaces.view` permission;
        // dashboard and reports are gated by their own tenant permissions.
        Route::get('search/tasks', [SearchController::class, 'tasks'])->middleware(['permission:workspaces.view', 'ensure_module:global_search']);

        Route::get('dashboard', [DashboardController::class, '__invoke'])->middleware('permission:dashboard.view');

        Route::get('analytics/overview', [AnalyticsController::class, '__invoke'])->middleware('permission:dashboard.view');

        Route::get('reports/overview', [ReportsController::class, 'overview'])->middleware(['permission:reports.view', 'ensure_module:reports']);

        Route::post('workspaces', [WorkspaceController::class, 'store'])->middleware('permission:workspaces.create');
        Route::post('project-roles', [ProjectRoleController::class, 'store'])->middleware('permission:roles.manage');
        Route::put('project-roles/{role}', [ProjectRoleController::class, 'update'])->middleware('permission:roles.manage');
        Route::delete('project-roles/{role}', [ProjectRoleController::class, 'destroy'])->middleware('permission:roles.manage');
    });

    // Signed temporary download link for task attachments. Intentionally OUTSIDE
    // the auth/tenant groups so a fresh-browser-tab GET works; access is granted
    // by the signed URL itself. Because no SwitchTenant has run, the central
    // tenant id is carried as a signed `tenant` query param and the controller
    // resolves the attachment inside TenantDatabaseManager::using() — the
    // task/attachment ids alone are tenant-local and resolve to nothing on the
    // central connection.
    Route::get('tasks/{task}/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->middleware('signed')
        ->name('attachments.download');
});

// Public marketing site (server-rendered from the DB-backed CMS pages).
Route::get('/', [PublicSiteController::class, 'home']);
Route::get('page/{slug}', [PublicSiteController::class, 'page']);
Route::get('sitemap.xml', [PublicSiteController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [PublicSiteController::class, 'robots'])->name('robots');

// SPA app at /app (the root path belongs to the public site).
Route::view('/app', 'app');
Route::view('/app/{any}', 'app')->where('any', '.*');

// Realtime channel authorization (Echo/Pusher protocol). Registered here rather
// than through withRouting(channels:) so `switch_tenant` runs first: the
// authenticated user is a tenant-local row, so it must be resolved on the
// tenant connection — on the default (system) connection every tenant user
// resolves to null and the channel callbacks in routes/channels.php return
// false (HTTP 403). `auth` keeps unauthenticated socket clients from hitting
// the callbacks at all.
Broadcast::routes(['middleware' => ['switch_tenant', 'auth']]);

require __DIR__.'/../routes/channels.php';
