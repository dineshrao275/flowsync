<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuditLogsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CmsController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DependencyController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FeatureManagementController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\Hrms\Asset\AssetCategoryController;
use App\Http\Controllers\Hrms\Asset\AssetController;
use App\Http\Controllers\Hrms\Attendance\AttendanceController;
use App\Http\Controllers\Hrms\Attendance\AttendanceRecordsController;
use App\Http\Controllers\Hrms\Attendance\RegularizationController;
use App\Http\Controllers\Hrms\AuditController;
use App\Http\Controllers\Hrms\CompOff\CompOffCreditController;
use App\Http\Controllers\Hrms\CompOff\CompOffRequestController;
use App\Http\Controllers\Hrms\CompOff\CompOffSettingsController;
use App\Http\Controllers\Hrms\DocumentController;
use App\Http\Controllers\Hrms\DocumentDownloadController;
use App\Http\Controllers\Hrms\DocumentTypeController;
use App\Http\Controllers\Hrms\EmployeeController;
use App\Http\Controllers\Hrms\Expense\ExpenseCategoryController;
use App\Http\Controllers\Hrms\Expense\ExpenseClaimController;
use App\Http\Controllers\Hrms\Holiday\HolidayAssignmentController;
use App\Http\Controllers\Hrms\Holiday\HolidayCalendarController;
use App\Http\Controllers\Hrms\Holiday\HolidayController;
use App\Http\Controllers\Hrms\Holiday\HolidayOptionalController;
use App\Http\Controllers\Hrms\HrmsAnalyticsController;
use App\Http\Controllers\Hrms\HrmsAnalyticsExportController;
use App\Http\Controllers\Hrms\InboxController;
use App\Http\Controllers\Hrms\Leave\LeaveBalanceController;
use App\Http\Controllers\Hrms\Leave\LeaveExemptionController;
use App\Http\Controllers\Hrms\Leave\LeavePolicyController;
use App\Http\Controllers\Hrms\Leave\LeaveRequestController;
use App\Http\Controllers\Hrms\Leave\LeaveTypeController;
use App\Http\Controllers\Hrms\Lifecycle\DocumentRequestController;
use App\Http\Controllers\Hrms\Lifecycle\OffboardingCaseController;
use App\Http\Controllers\Hrms\Lifecycle\OnboardingCaseController;
use App\Http\Controllers\Hrms\Lifecycle\OnboardingTemplateController;
use App\Http\Controllers\Hrms\MyHrController;
use App\Http\Controllers\Hrms\MyTeamController;
use App\Http\Controllers\Hrms\Org\DepartmentController;
use App\Http\Controllers\Hrms\Org\DesignationController;
use App\Http\Controllers\Hrms\Org\LocationController;
use App\Http\Controllers\Hrms\Org\OrgController;
use App\Http\Controllers\Hrms\Payroll\EmployeeSalaryController;
use App\Http\Controllers\Hrms\Payroll\PayrollRunController;
use App\Http\Controllers\Hrms\Payroll\PayslipAdjustmentController;
use App\Http\Controllers\Hrms\Payroll\PayslipController;
use App\Http\Controllers\Hrms\Payroll\PayslipDownloadController;
use App\Http\Controllers\Hrms\Payroll\SalaryComponentController;
use App\Http\Controllers\Hrms\Payroll\SalaryStructureController;
use App\Http\Controllers\Hrms\Payroll\StatutoryConfigurationController;
use App\Http\Controllers\Hrms\Payroll\StatutoryDeclarationController;
use App\Http\Controllers\Hrms\Payroll\StatutoryProfileController;
use App\Http\Controllers\Hrms\Payroll\TdsProjectController;
use App\Http\Controllers\Hrms\Performance\CheckInController;
use App\Http\Controllers\Hrms\Performance\FeedbackRequestController;
use App\Http\Controllers\Hrms\Performance\OneOnOneController;
use App\Http\Controllers\Hrms\Performance\PerformanceCycleController;
use App\Http\Controllers\Hrms\Performance\PerformanceGoalController;
use App\Http\Controllers\Hrms\Performance\ReviewSummaryController;
use App\Http\Controllers\Hrms\Survey\SurveyCampaignController;
use App\Http\Controllers\Hrms\Survey\SurveyTemplateController;
use App\Http\Controllers\Hrms\TaskLinkController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\IssueTypeController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\MySubscriptionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlatformHealthController;
use App\Http\Controllers\ProjectComponentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\ProjectRoleController;
use App\Http\Controllers\ProjectVersionController;
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
use App\Http\Controllers\TaskWatcherController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantHrmsController;
use App\Http\Controllers\TenantSubscriptionController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WorkLogController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceMemberController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('health', [PlatformHealthController::class, 'ping']);
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

        // Per-event notification preferences (tenant tables, self-scoped like
        // the notifications family; the SA short-circuit mirrors them).
        Route::get('notification-preferences', [NotificationPreferenceController::class, 'show']);
        Route::put('notification-preferences', [NotificationPreferenceController::class, 'update']);

        // HR inbox (self-scoped by login like notifications; the service
        // short-circuits platform super admins to empty payloads).
        Route::get('hrms/inbox', [InboxController::class, 'index']);
        Route::post('hrms/inbox/read', [InboxController::class, 'markRead']);
        Route::post('hrms/inbox/read-all', [InboxController::class, 'markAllRead']);

        // My HR home (Phase 15 P17.1: self-scoped via the login, gated by
        // hrms.core like every HRMS surface, 404 for platform super admins).
        Route::get('my/hr', [MyHrController::class, 'show'])->middleware('ensure_module:hrms.core');

        // My team and preferences (P17.2–P17.4, same gates and edges).
        Route::get('my/team', [MyTeamController::class, 'team'])->middleware('ensure_module:hrms.core');
        Route::get('my/hr/preferences', [MyTeamController::class, 'preferences'])->middleware('ensure_module:hrms.core');
        Route::put('my/hr/preferences', [MyTeamController::class, 'updatePreferences'])->middleware('ensure_module:hrms.core');

        // Tenant-facing profile (self-scoped via TenantContext; no tenant_context
        // needed because a tenant user resolves their own central tenant row).
        Route::get('tenant/profile', [TenantController::class, 'selfProfile']);
        Route::put('tenant/profile', [TenantController::class, 'updateSelfProfile']);

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
        Route::post('my-subscription/switch', [MySubscriptionController::class, 'switch'])->middleware('throttle:10,1');
        Route::post('my-subscription/cancel', [MySubscriptionController::class, 'cancel'])->middleware('throttle:10,1');
        Route::post('my-subscription/renew', [MySubscriptionController::class, 'renew'])->middleware('throttle:10,1');
        Route::get('plans', [PlanController::class, 'index']);

        // Phase 6: tenant payments & billing
        Route::post('billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1');
        Route::post('billing/verify', [BillingController::class, 'verify'])->middleware('throttle:10,1');
        Route::get('billing/history', [BillingController::class, 'history']);
        Route::post('billing/payments/{payment}/refund', [BillingController::class, 'refund'])->middleware('throttle:5,1');

        // Phase 5: full tenant data export (gated by the export.full module).
        // throttle:5,1 — exports are heavy; 5 requests/minute is generous.
        Route::middleware('ensure_module:export.full')->group(function () {
            Route::get('my-export', [ExportController::class, 'index']);
            Route::post('my-export', [ExportController::class, 'store'])->middleware('throttle:5,1');
            Route::get('my-export/{run}', [ExportController::class, 'show']);
        });

        // Phase 9: global multi-entity search. Lives OUTSIDE tenant_context so a
        // non-impersonating super admin can search across all tenants (the
        // controller fans out over the central tenancy index via TenantDatabaseManager).
        Route::get('search/global', GlobalSearchController::class)->middleware(['permission:workspaces.view', 'ensure_module:global_search']);

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.manage');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.manage');
        Route::post('roles/{role}/clone', [RoleController::class, 'clone'])->middleware('permission:roles.manage');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.manage');

        Route::middleware('super_admin')->group(function () {
            // Phase 13: tenancy platform (central DB index across tenant DBs).
            Route::get('tenants', [TenantController::class, 'index']);
            Route::post('tenants', [TenantController::class, 'store'])->middleware('throttle:10,1');
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

            // Phase 15: per-tenant HRMS entitlement (additive override layer).
            Route::get('tenants/{tenant}/hrms', [TenantHrmsController::class, 'show']);
            Route::put('tenants/{tenant}/hrms', [TenantHrmsController::class, 'update']);

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
            Route::get('system/audit-logs/export', [AuditLogsController::class, 'export'])->middleware('throttle:30,1');
            Route::get('system/analytics', [SystemAnalyticsController::class, 'index']);
            Route::get('platform/health', [PlatformHealthController::class, 'show']);
            Route::get('system/health', [PlatformHealthController::class, 'show']);
            Route::get('system/features', [FeatureManagementController::class, 'index']);
            Route::put('system/features/{subscriptionPlan}', [FeatureManagementController::class, 'update']);
            Route::get('system/pages', [CmsController::class, 'index']);
            Route::post('system/pages', [CmsController::class, 'store']);
            Route::get('system/pages/{websitePage}', [CmsController::class, 'show']);
            Route::put('system/pages/{websitePage}', [CmsController::class, 'update']);
            Route::delete('system/pages/{websitePage}', [CmsController::class, 'destroy']);
            Route::post('system/pages/{websitePage}/publish', [CmsController::class, 'publish']);
            Route::post('system/pages/{websitePage}/unpublish', [CmsController::class, 'unpublish']);

            Route::post('impersonate', [ImpersonationController::class, 'start'])->middleware('throttle:10,1');
        });

        Route::post('impersonate/stop', [ImpersonationController::class, 'stop'])->middleware('throttle:30,1');
    });

    Route::middleware(['switch_tenant', 'auth', 'tenant', 'tenant_context', 'onboarding_complete'])->group(function () {
        // Tenant user administration. Lives in the tenant_context group because
        // the users table lives in the tenant's own database: a non-impersonating
        // super admin has nothing to route to and is rejected by the middleware
        // (an impersonating super admin is the impersonated tenant user, so the
        // tenant-context + `users.manage` checks both apply to them).
        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('users', [UserController::class, 'store'])->middleware(['permission:users.manage', 'throttle:30,1']);
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
            Route::get('projects/{project}/members/autocomplete', [ProjectMemberController::class, 'autocomplete']);
            Route::post('projects/{project}/members', [ProjectMemberController::class, 'store']);
            Route::put('projects/{project}/members/{user}', [ProjectMemberController::class, 'update']);
            Route::delete('projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy']);

            Route::get('projects/{project}/statuses', [StatusController::class, 'index']);
            Route::post('projects/{project}/statuses', [StatusController::class, 'store']);
            Route::put('projects/{project}/statuses/{status}', [StatusController::class, 'update']);
            Route::delete('projects/{project}/statuses/{status}', [StatusController::class, 'destroy']);

            // TMS expansion: project components & releases/versions
            Route::get('projects/{project}/components', [ProjectComponentController::class, 'index']);
            Route::post('projects/{project}/components', [ProjectComponentController::class, 'store']);
            Route::put('projects/{project}/components/{component}', [ProjectComponentController::class, 'update']);
            Route::delete('projects/{project}/components/{component}', [ProjectComponentController::class, 'destroy']);

            Route::get('projects/{project}/versions', [ProjectVersionController::class, 'index']);
            Route::post('projects/{project}/versions', [ProjectVersionController::class, 'store']);
            Route::put('projects/{project}/versions/{version}', [ProjectVersionController::class, 'update']);
            Route::delete('projects/{project}/versions/{version}', [ProjectVersionController::class, 'destroy']);

            // Phase 3: tasks. Board/list at the project index (view=board|list,
            // filters as query params); mutations gated by TaskPolicy (project-role
            // tasks.* permissions) + ProjectPolicy::createTask.
            Route::get('projects/{project}/tasks', [TaskController::class, 'index']);
            Route::post('projects/{project}/tasks', [TaskController::class, 'store']);
            Route::get('projects/{project}/tasks/key/{key}', [TaskController::class, 'showByKey'])->where('key', '[A-Za-z0-9-]+');
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

            // Task watchers
            Route::get('projects/{project}/tasks/{task}/watchers', [TaskWatcherController::class, 'index']);
            Route::post('projects/{project}/tasks/{task}/watchers', [TaskWatcherController::class, 'store']);
            Route::delete('projects/{project}/tasks/{task}/watchers/{user?}', [TaskWatcherController::class, 'destroy']);

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

            // Issue types catalog (tenant-level).
            Route::get('issue-types', [IssueTypeController::class, 'index']);
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

        Route::post('issue-types', [IssueTypeController::class, 'store'])->middleware('permission:workspaces.manage');
        Route::put('issue-types/{issueType}', [IssueTypeController::class, 'update'])->middleware('permission:workspaces.manage');
        Route::delete('issue-types/{issueType}', [IssueTypeController::class, 'destroy'])->middleware('permission:workspaces.manage');
    });

    // HRMS tenant scope. Every HRMS surface below runs the FULL domain stack
    // (`switch_tenant` → `auth` → `tenant` → `tenant_context` + the onboarding
    // gate) like the rest of the domain: without SwitchTenant, route-model
    // binding runs on whatever connection the process started with (the
    // central DB in production) and every bound HRMS route answers 500
    // instead of data. The signed file routes further below stay outside —
    // the signature is their credential.
    Route::middleware(['switch_tenant', 'auth', 'tenant', 'tenant_context', 'onboarding_complete'])->group(function () {

        // HRMS org structure (Phase 15 P3.3). The same two gates as the employee
        // surface: `hrms.core` decides whether the module exists for this tenant
        // and `hrms.view` whether it can be reached, and the org policies then
        // split reads (`hrms.org.view`) from writes (`hrms.org.manage`) per record.
        //
        // Each `reorder` is declared before its own `{resource}` sibling, because
        // otherwise "reorder" binds as a department/designation/location id and
        // comes back as a 404 on a perfectly valid drag-and-drop.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
            Route::get('hrms/org', [OrgController::class, 'index']);

            Route::get('hrms/departments', [DepartmentController::class, 'index']);
            Route::post('hrms/departments', [DepartmentController::class, 'store']);
            Route::post('hrms/departments/reorder', [DepartmentController::class, 'reorder']);
            Route::get('hrms/departments/{department}', [DepartmentController::class, 'show']);
            Route::put('hrms/departments/{department}', [DepartmentController::class, 'update']);
            Route::delete('hrms/departments/{department}', [DepartmentController::class, 'destroy']);
            Route::post('hrms/departments/{department}/deactivate', [DepartmentController::class, 'deactivate']);

            Route::get('hrms/designations', [DesignationController::class, 'index']);
            Route::post('hrms/designations', [DesignationController::class, 'store']);
            Route::post('hrms/designations/reorder', [DesignationController::class, 'reorder']);
            Route::get('hrms/designations/{designation}', [DesignationController::class, 'show']);
            Route::put('hrms/designations/{designation}', [DesignationController::class, 'update']);
            Route::delete('hrms/designations/{designation}', [DesignationController::class, 'destroy']);
            Route::post('hrms/designations/{designation}/deactivate', [DesignationController::class, 'deactivate']);

            Route::get('hrms/locations', [LocationController::class, 'index']);
            Route::post('hrms/locations', [LocationController::class, 'store']);
            Route::post('hrms/locations/reorder', [LocationController::class, 'reorder']);
            Route::get('hrms/locations/{location}', [LocationController::class, 'show']);
            Route::put('hrms/locations/{location}', [LocationController::class, 'update']);
            Route::delete('hrms/locations/{location}', [LocationController::class, 'destroy']);
            Route::post('hrms/locations/{location}/deactivate', [LocationController::class, 'deactivate']);
        });

        // HRMS employee records (Phase 15 P2.3). The module gate and `hrms.view`
        // decide whether the surface exists for this tenant at all;
        // EmployeePolicy decides what the caller may do with each record inside it
        // — including reading their own, which no tenant-level permission grants.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
            Route::get('hrms/employees', [EmployeeController::class, 'index']);
            Route::post('hrms/employees', [EmployeeController::class, 'store']);
            Route::get('hrms/employees/{employee}', [EmployeeController::class, 'show']);
            Route::get('hrms/employees/{employee}/summary', [MyTeamController::class, 'summary']);
            Route::put('hrms/employees/{employee}', [EmployeeController::class, 'update']);
            Route::delete('hrms/employees/{employee}', [EmployeeController::class, 'destroy']);
            Route::post('hrms/employees/{employee}/status', [EmployeeController::class, 'changeStatus']);
            Route::post('hrms/employees/{employee}/manager', [EmployeeController::class, 'assignManager']);
            Route::post('hrms/employees/{employee}/terminate', [EmployeeController::class, 'terminate']);
        });

        // HRMS employee documents (Phase 15 P13.3). Same two gates as the
        // employee surface; EmployeeDocumentPolicy decides per record — including
        // reading and filing one’s own, which no tenant permission grants.
        //
        // `expiring` is declared before `{document}`, because otherwise the word
        // binds as a document id and a valid warning query 404s.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.documents', 'permission:hrms.view']], function () {
            Route::get('hrms/documents/types', [DocumentTypeController::class, 'index']);
            Route::get('hrms/documents/types/{type}', [DocumentTypeController::class, 'show']);

            Route::get('hrms/documents/expiring', [DocumentController::class, 'expiring']);
            Route::get('hrms/documents', [DocumentController::class, 'index']);
            Route::post('hrms/documents', [DocumentController::class, 'store']);
            Route::get('hrms/documents/{document}', [DocumentController::class, 'show']);
            Route::delete('hrms/documents/{document}', [DocumentController::class, 'destroy']);
            Route::post('hrms/documents/{document}/verify', [DocumentController::class, 'verify']);
            Route::post('hrms/documents/{document}/reject', [DocumentController::class, 'reject']);
            Route::get('hrms/my/documents', [DocumentController::class, 'mine']);
        });

        // HRMS onboarding & offboarding (Phase 15 P4.3). Same two gates as the
        // rest of the HRMS surface; the lifecycle policies decide per record —
        // including a hire reading their own checklist, which no tenant
        // permission grants.
        //
        // Every nested route declares both models (`cases/{case}/tasks/{task}`),
        // and the controller verifies the task belongs to the case: a task id
        // from another run 404s here rather than completing the wrong checklist.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.onboarding', 'permission:hrms.view']], function () {
            Route::get('hrms/onboarding/templates', [OnboardingTemplateController::class, 'index']);
            Route::post('hrms/onboarding/templates', [OnboardingTemplateController::class, 'store']);
            Route::get('hrms/onboarding/templates/{template}', [OnboardingTemplateController::class, 'show']);
            Route::put('hrms/onboarding/templates/{template}', [OnboardingTemplateController::class, 'update']);
            Route::delete('hrms/onboarding/templates/{template}', [OnboardingTemplateController::class, 'destroy']);
            Route::post('hrms/onboarding/templates/{template}/tasks', [OnboardingTemplateController::class, 'storeTask']);
            Route::put('hrms/onboarding/templates/{template}/tasks/{task}', [OnboardingTemplateController::class, 'updateTask']);
            Route::delete('hrms/onboarding/templates/{template}/tasks/{task}', [OnboardingTemplateController::class, 'destroyTask']);
            Route::post('hrms/onboarding/templates/{template}/tasks/reorder', [OnboardingTemplateController::class, 'reorderTasks']);

            Route::get('hrms/onboarding/cases', [OnboardingCaseController::class, 'index']);
            Route::post('hrms/onboarding/cases', [OnboardingCaseController::class, 'store']);
            Route::get('hrms/onboarding/cases/{case}', [OnboardingCaseController::class, 'show']);
            Route::post('hrms/onboarding/cases/{case}/tasks/{task}/complete', [OnboardingCaseController::class, 'completeTask']);
            Route::post('hrms/onboarding/cases/{case}/tasks/{task}/waive', [OnboardingCaseController::class, 'waiveTask']);
            Route::post('hrms/onboarding/cases/{case}/tasks/{task}/convert', [OnboardingCaseController::class, 'convertTask']);
            Route::post('hrms/onboarding/cases/{case}/tasks/{task}/sync', [OnboardingCaseController::class, 'syncTask']);
            Route::post('hrms/onboarding/cases/{case}/complete', [OnboardingCaseController::class, 'complete']);
            Route::post('hrms/onboarding/cases/{case}/cancel', [OnboardingCaseController::class, 'cancel']);

        });

        Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.offboarding', 'permission:hrms.view']], function () {
            Route::get('hrms/offboarding/cases', [OffboardingCaseController::class, 'index']);
            Route::post('hrms/offboarding/cases', [OffboardingCaseController::class, 'store']);
            Route::get('hrms/offboarding/cases/{case}', [OffboardingCaseController::class, 'show']);
            Route::post('hrms/offboarding/cases/{case}/tasks/{task}/complete', [OffboardingCaseController::class, 'completeTask']);
            Route::post('hrms/offboarding/cases/{case}/tasks/{task}/convert', [OffboardingCaseController::class, 'convertTask']);
            Route::post('hrms/offboarding/cases/{case}/tasks/{task}/sync', [OffboardingCaseController::class, 'syncTask']);
            Route::post('hrms/offboarding/cases/{case}/clear', [OffboardingCaseController::class, 'clear']);
            Route::post('hrms/offboarding/cases/{case}/complete', [OffboardingCaseController::class, 'complete']);
            Route::post('hrms/offboarding/cases/{case}/cancel', [OffboardingCaseController::class, 'cancel']);

        });

        // Document asks are raised by both lifecycle flows and fulfilled through the
        // document store, so they ride the documents module.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.documents', 'permission:hrms.view']], function () {
            Route::get('hrms/document-requests', [DocumentRequestController::class, 'index']);
            Route::post('hrms/document-requests', [DocumentRequestController::class, 'store']);
            Route::get('hrms/document-requests/{documentRequest}', [DocumentRequestController::class, 'show']);
            Route::post('hrms/document-requests/{documentRequest}/submit', [DocumentRequestController::class, 'submit']);
            Route::post('hrms/document-requests/{documentRequest}/accept', [DocumentRequestController::class, 'accept']);
            Route::post('hrms/document-requests/{documentRequest}/waive', [DocumentRequestController::class, 'waive']);
            Route::post('hrms/document-requests/{documentRequest}/reject', [DocumentRequestController::class, 'reject']);
        });

        // HRMS attendance settings (Phase 15 P5.3). The module gate decides
        // whether attendance exists for this tenant; `hrms.attendance.settings`
        // decides who may rewrite its policy. Partial sections merge — a PUT
        // that blanked unmentioned sections would be a reset disguised as edit.
        Route::group(['middleware' => ['ensure_module:hrms.attendance']], function () {
            Route::get('hrms/attendance/settings', [AttendanceController::class, 'getSettings'])
                ->middleware('permission:hrms.attendance.settings');
            Route::put('hrms/attendance/settings', [AttendanceController::class, 'settings'])
                ->middleware('permission:hrms.attendance.settings');

            // HRMS regularization (Phase 15 P5.4). Deliberately NO route-level
            // permission, like the punch endpoint: requesting and reading one's
            // own asks is self-service, and deciding belongs to the approval
            // step's approver — a manager who may hold no attendance permission
            // at all. The policy answers both from the record and the chain.
            Route::get('hrms/attendance/regularizations', [RegularizationController::class, 'index']);
            Route::post('hrms/attendance/regularizations', [RegularizationController::class, 'store']);
            Route::get('hrms/attendance/regularizations/{regularization}', [RegularizationController::class, 'show']);
            Route::post('hrms/attendance/regularizations/{regularization}/approve', [RegularizationController::class, 'approve']);
            Route::post('hrms/attendance/regularizations/{regularization}/reject', [RegularizationController::class, 'reject']);

            // HRMS attendance reads (Phase 15 P5.6a). Same gate shape as the
            // regularizations above: module-gated, policy-authorized per
            // employee (self-service included via AttendanceDayPolicy::view).
            // Declared before any `{record}` sibling so a literal segment can
            // never bind as a model id (the P3.3 `reorder` lesson).
            Route::get('hrms/attendance/month', [AttendanceRecordsController::class, 'month']);
            Route::get('hrms/attendance/today', [AttendanceRecordsController::class, 'today']);
            // Exports are the heaviest reads on this surface (up to 93 days of
            // rows per pull), so the one bulk endpoint rides the throttle while
            // the month grid and widget stay unthrottled — and unlike those
            // self-service reads, exports answer to tenant admins only.
            Route::get('hrms/attendance/export', [AttendanceRecordsController::class, 'export'])->middleware(['throttle:30,1', 'permission:workspaces.manage']);
        });

        // HRMS leave catalogue (Phase 15 P6.4a). Module-gated on `hrms.leave`
        // with no route-level permission: reads are employee-open (filing needs
        // the catalogue) and writes take `hrms.leave.manage`, both answered by
        // the policy — the same self-service shape as the attendance reads.
        Route::group(['middleware' => ['ensure_module:hrms.leave']], function () {
            Route::get('hrms/leave/types', [LeaveTypeController::class, 'index']);
            Route::post('hrms/leave/types', [LeaveTypeController::class, 'store']);
            Route::put('hrms/leave/types/{leaveType}', [LeaveTypeController::class, 'update']);
            Route::delete('hrms/leave/types/{leaveType}', [LeaveTypeController::class, 'destroy']);

            Route::get('hrms/leave/policies', [LeavePolicyController::class, 'index']);
            Route::post('hrms/leave/policies', [LeavePolicyController::class, 'store']);
            Route::put('hrms/leave/policies/{leavePolicy}', [LeavePolicyController::class, 'update']);
            Route::delete('hrms/leave/policies/{leavePolicy}', [LeavePolicyController::class, 'destroy']);

            // HRMS leave balances + accrual runs (Phase 15 P6.4b). Reads ride
            // the same employee-open policy as the catalogue; the run itself
            // takes `hrms.leave.manage` at the route and audits as a bulk.
            Route::get('hrms/leave/balances', [LeaveBalanceController::class, 'index']);
            Route::post('hrms/leave/accrue', [LeaveBalanceController::class, 'accrue'])
                ->middleware('permission:hrms.leave.manage');

            // HRMS leave asks (Phase 15 P6.4c). Same gate shape: module-gated,
            // policy-authorized per record (self-service included, deciding
            // belongs to the step's approver). `cancel` carries an optional
            // reason where DELETE stays reason-free, per the plan's routes.
            Route::get('hrms/leave/requests', [LeaveRequestController::class, 'index']);
            Route::post('hrms/leave/requests', [LeaveRequestController::class, 'store']);
            Route::get('hrms/leave/requests/availability', [LeaveRequestController::class, 'availability']);
            Route::get('hrms/leave/requests/calendar', [LeaveRequestController::class, 'calendar']);
            Route::get('hrms/leave/requests/{leaveRequest}', [LeaveRequestController::class, 'show']);
            Route::put('hrms/leave/requests/{leaveRequest}', [LeaveRequestController::class, 'update']);
            Route::delete('hrms/leave/requests/{leaveRequest}', [LeaveRequestController::class, 'destroy']);
            Route::post('hrms/leave/requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);
            Route::post('hrms/leave/requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
            Route::post('hrms/leave/requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);
        });

        // HRMS statutory exemptions (Phase 15 P6.4c). Its own module gate per
        // the 3.5 mapping — an exemption is jurisdiction trail, not leave
        // administration, and the two entitlements move separately.
        Route::group(['middleware' => ['ensure_module:hrms.leave.exemption']], function () {
            Route::get('hrms/leave/exemptions', [LeaveExemptionController::class, 'index']);
            Route::post('hrms/leave/exemptions', [LeaveExemptionController::class, 'store']);
            Route::get('hrms/leave/exemptions/{exemption}', [LeaveExemptionController::class, 'show']);
            Route::post('hrms/leave/exemptions/{exemption}/decide', [LeaveExemptionController::class, 'decide']);
        });

        // HRMS comp-off (Phase 15 P7.3). Module-gated on `hrms.comp_off` with
        // no route-level permission except the manage-only writes: reads and
        // asks are policy-gated per record (self-service included, deciding
        // belongs to the step's approver), while manual grants, settings and
        // runs take `hrms.comp_off.manage` at the route.
        Route::group(['middleware' => ['ensure_module:hrms.comp_off']], function () {
            Route::get('hrms/comp-off/credits', [CompOffCreditController::class, 'index']);
            Route::post('hrms/comp-off/credits', [CompOffCreditController::class, 'store'])
                ->middleware('permission:hrms.comp_off.manage');

            Route::get('hrms/comp-off/requests', [CompOffRequestController::class, 'index']);
            Route::post('hrms/comp-off/requests', [CompOffRequestController::class, 'store']);
            Route::get('hrms/comp-off/requests/{compOffRequest}', [CompOffRequestController::class, 'show']);
            Route::delete('hrms/comp-off/requests/{compOffRequest}', [CompOffRequestController::class, 'destroy']);
            Route::post('hrms/comp-off/requests/{compOffRequest}/cancel', [CompOffRequestController::class, 'cancel']);
            Route::post('hrms/comp-off/requests/{compOffRequest}/approve', [CompOffRequestController::class, 'approve']);
            Route::post('hrms/comp-off/requests/{compOffRequest}/reject', [CompOffRequestController::class, 'reject']);

            Route::get('hrms/comp-off/settings', [CompOffSettingsController::class, 'show'])
                ->middleware('permission:hrms.comp_off.manage');
            Route::put('hrms/comp-off/settings', [CompOffSettingsController::class, 'update'])
                ->middleware('permission:hrms.comp_off.manage');
            Route::post('hrms/comp-off/accrue', [CompOffSettingsController::class, 'accrue'])
                ->middleware('permission:hrms.comp_off.manage');
        });

        // HRMS holidays (Phase 15 P8.4a). Module-gated on `hrms.holidays` with
        // no route-level permission except the seed-year run: reads and writes
        // answer per record through the policies. Nested holiday creation
        // authorizes against the parent calendar, and `seed-year` is declared
        // before any `{calendar}` sibling so a literal never binds as an id
        // (the P3.3 `reorder` lesson).
        Route::group(['middleware' => ['ensure_module:hrms.holidays']], function () {
            Route::get('hrms/holidays/calendars', [HolidayCalendarController::class, 'index']);
            Route::post('hrms/holidays/calendars', [HolidayCalendarController::class, 'store']);
            Route::post('hrms/holidays/seed-year', [HolidayCalendarController::class, 'seedYear'])
                ->middleware('permission:hrms.holidays.manage');
            Route::put('hrms/holidays/calendars/{calendar}', [HolidayCalendarController::class, 'update']);
            Route::delete('hrms/holidays/calendars/{calendar}', [HolidayCalendarController::class, 'destroy']);

            Route::get('hrms/holidays/calendars/{calendar}/holidays', [HolidayCalendarController::class, 'holidays']);
            Route::post('hrms/holidays/calendars/{calendar}/holidays', [HolidayCalendarController::class, 'storeHoliday']);
            Route::put('hrms/holidays/{holiday}', [HolidayController::class, 'update']);
            Route::delete('hrms/holidays/{holiday}', [HolidayController::class, 'destroy']);

            // HRMS assignments, the resolved view, and optional answers (Phase
            // 15 P8.4b). `calendar` is declared before any `{assignment}`
            // sibling so the literal never binds as an id (the P3.3 lesson);
            // the resolved view authorizes per employee, with self-service.
            Route::get('hrms/holidays/assignments', [HolidayAssignmentController::class, 'index']);
            Route::post('hrms/holidays/assignments', [HolidayAssignmentController::class, 'store']);
            Route::get('hrms/holidays/calendar', [HolidayAssignmentController::class, 'resolved']);
            Route::delete('hrms/holidays/assignments/{assignment}', [HolidayAssignmentController::class, 'destroy']);

            Route::get('hrms/holidays/optional', [HolidayOptionalController::class, 'index']);
            Route::post('hrms/holidays/optional', [HolidayOptionalController::class, 'store']);
        });

        // HRMS payroll reads (Phase 15 P9.4). Same two gates as the rest of the
        // HRMS surface; PayslipPolicy decides per record — the run grid is a
        // runner tool (`hrms.payroll.run`), one payslip is an all-viewer or
        // self-with-view read. Every read writes its access rows inside the
        // service, and the remaining payroll surface (policies, requests, write
        // routes) lands in P9.5.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
            Route::get('hrms/payroll/runs/{run}/payslips', [PayslipController::class, 'index']);
            Route::get('hrms/payroll/payslips/{payslip}', [PayslipController::class, 'show']);
        });

        // HRMS compensation writes (Phase 15 P9.5a). Same two gates; the component
        // and structure policies split reads (`hrms.compensation.view`) from
        // writes (`hrms.compensation.manage`), and the salary controller answers
        // self-service reads itself — pay is sensitive, and the person in the
        // record may see their own basis.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
            Route::get('hrms/payroll/components', [SalaryComponentController::class, 'index']);
            Route::post('hrms/payroll/components', [SalaryComponentController::class, 'store']);
            Route::get('hrms/payroll/components/{component}', [SalaryComponentController::class, 'show']);
            Route::put('hrms/payroll/components/{component}', [SalaryComponentController::class, 'update']);
            Route::delete('hrms/payroll/components/{component}', [SalaryComponentController::class, 'destroy']);

            Route::get('hrms/payroll/structures', [SalaryStructureController::class, 'index']);
            Route::post('hrms/payroll/structures', [SalaryStructureController::class, 'store']);
            Route::get('hrms/payroll/structures/{structure}', [SalaryStructureController::class, 'show']);
            Route::put('hrms/payroll/structures/{structure}', [SalaryStructureController::class, 'update']);
            Route::delete('hrms/payroll/structures/{structure}', [SalaryStructureController::class, 'destroy']);
            Route::put('hrms/payroll/structures/{structure}/components', [SalaryStructureController::class, 'setComponents']);

            Route::get('hrms/payroll/employees/{employee}/salary', [EmployeeSalaryController::class, 'salary']);
            Route::post('hrms/payroll/employees/{employee}/salary', [EmployeeSalaryController::class, 'assign']);
            Route::get('hrms/payroll/employees/{employee}/revisions', [EmployeeSalaryController::class, 'revisions']);
            Route::post('hrms/payroll/employees/{employee}/revisions', [EmployeeSalaryController::class, 'revise']);
            Route::post('hrms/payroll/employees/{employee}/revisions/{revision}/apply', [EmployeeSalaryController::class, 'applyRevision']);

            // HRMS payroll runs (Phase 15 P9.5b). Same two gates; the run policy
            // answers every step with the one runner permission, and adjustments
            // ride the payslip's `adjust` ability with both models bound.
            Route::get('hrms/payroll/runs', [PayrollRunController::class, 'index']);
            Route::post('hrms/payroll/runs', [PayrollRunController::class, 'store']);
            Route::get('hrms/payroll/runs/{run}', [PayrollRunController::class, 'show']);
            Route::post('hrms/payroll/runs/{run}/calculate', [PayrollRunController::class, 'calculate']);
            Route::post('hrms/payroll/runs/{run}/approve', [PayrollRunController::class, 'approve']);
            Route::post('hrms/payroll/runs/{run}/publish', [PayrollRunController::class, 'publish']);
            Route::post('hrms/payroll/runs/{run}/mark-paid', [PayrollRunController::class, 'markPaid']);
            Route::post('hrms/payroll/runs/{run}/lock', [PayrollRunController::class, 'lock']);

            Route::post('hrms/payroll/payslips/{payslip}/adjustments', [PayslipAdjustmentController::class, 'store']);
            Route::delete('hrms/payroll/payslips/{payslip}/adjustments/{adjustment}', [PayslipAdjustmentController::class, 'destroy']);
            Route::get('hrms/payroll/my-payslips', [PayslipController::class, 'mine']);
        });

        // HRMS statutory identifiers (Phase 15 P10.5). The whole group sits
        // behind the statutory module gate — jurisdictions a tenant never
        // enabled have no profile surface at all — with the usual `hrms.view`
        // surface gate inside it. Reads are self-or-manage, writes and the
        // cleartext reveal are manage-alone (see the policy); configurations,
        // declarations, projections and the surrender land in P10.6.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.payroll.statutory', 'permission:hrms.view']], function () {
            Route::get('hrms/payroll/statutory/profiles/{employee}', [StatutoryProfileController::class, 'show']);
            Route::put('hrms/payroll/statutory/profiles/{employee}', [StatutoryProfileController::class, 'update']);
            Route::post('hrms/payroll/statutory/profiles/{employee}/reveal', [StatutoryProfileController::class, 'reveal']);

            // HRMS statutory rulebooks and exemption claims (Phase 15 P10.6a).
            // Same gates; the configuration policy answers everything with
            // manage, the declaration policy splits filing (self-or-manage)
            // from deciding (manage-alone).
            Route::get('hrms/payroll/statutory/configurations', [StatutoryConfigurationController::class, 'index']);
            Route::post('hrms/payroll/statutory/configurations', [StatutoryConfigurationController::class, 'store']);
            Route::get('hrms/payroll/statutory/configurations/{configuration}', [StatutoryConfigurationController::class, 'show']);
            Route::put('hrms/payroll/statutory/configurations/{configuration}', [StatutoryConfigurationController::class, 'update']);
            Route::delete('hrms/payroll/statutory/configurations/{configuration}', [StatutoryConfigurationController::class, 'destroy']);

            Route::get('hrms/payroll/statutory/declarations', [StatutoryDeclarationController::class, 'index']);
            Route::post('hrms/payroll/statutory/declarations', [StatutoryDeclarationController::class, 'store']);
            Route::get('hrms/payroll/statutory/declarations/{declaration}', [StatutoryDeclarationController::class, 'show']);
            Route::post('hrms/payroll/statutory/declarations/{declaration}/submit', [StatutoryDeclarationController::class, 'submit']);
            Route::post('hrms/payroll/statutory/declarations/{declaration}/verify', [StatutoryDeclarationController::class, 'verify']);
            Route::post('hrms/payroll/statutory/declarations/{declaration}/reject', [StatutoryDeclarationController::class, 'reject']);

            // HRMS TDS projections and the run recompute twin (Phase 15
            // P10.6b). Same gates; the project policy splits reading (self or
            // manage) from moving (manage alone), and the recompute answers to
            // the run's own calculate ability.
            Route::get('hrms/payroll/statutory/tds-projects', [TdsProjectController::class, 'index']);
            Route::post('hrms/payroll/statutory/tds-projects', [TdsProjectController::class, 'project']);
            Route::get('hrms/payroll/statutory/tds-projects/{project}', [TdsProjectController::class, 'show']);
            Route::post('hrms/payroll/statutory/tds-projects/{project}/surrender', [TdsProjectController::class, 'surrender']);
            Route::post('hrms/payroll/statutory/recompute', [TdsProjectController::class, 'recompute']);
        });

        // HRMS expenses (Phase 15 P11.3a). Same two gates plus the expenses
        // module; the claim policy splits listing/reading (self-or-view),
        // filing (self-or-manage) and deciding (approve-alone), and the
        // category policy is master-data shaped (view vs manage).
        Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.expenses', 'permission:hrms.view']], function () {
            Route::get('hrms/expenses/categories', [ExpenseCategoryController::class, 'index']);
            Route::post('hrms/expenses/categories', [ExpenseCategoryController::class, 'store']);
            Route::get('hrms/expenses/categories/{category}', [ExpenseCategoryController::class, 'show']);
            Route::put('hrms/expenses/categories/{category}', [ExpenseCategoryController::class, 'update']);
            Route::delete('hrms/expenses/categories/{category}', [ExpenseCategoryController::class, 'destroy']);

            Route::get('hrms/expenses/claims', [ExpenseClaimController::class, 'index']);
            Route::post('hrms/expenses/claims', [ExpenseClaimController::class, 'store']);
            Route::get('hrms/expenses/claims/{claim}', [ExpenseClaimController::class, 'show']);
            Route::put('hrms/expenses/claims/{claim}/items', [ExpenseClaimController::class, 'setItems']);
            Route::post('hrms/expenses/claims/{claim}/submit', [ExpenseClaimController::class, 'submit']);
            Route::post('hrms/expenses/claims/{claim}/decide', [ExpenseClaimController::class, 'decide']);
        });

        // HRMS engagement surveys (Phase 15 P16.3a). Same two gates plus the
        // engagement module; templates answer to manage, campaigns to view or
        // invitation, answers to invitation alone. The self-service pair
        // (`my`) carries no answers and no aggregates — the blank form lives
        // there, the scored results behind the view gate.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.engagement', 'permission:hrms.view']], function () {
            Route::get('hrms/engagement/templates', [SurveyTemplateController::class, 'index']);
            Route::post('hrms/engagement/templates', [SurveyTemplateController::class, 'store']);
            Route::get('hrms/engagement/templates/{template}', [SurveyTemplateController::class, 'show']);
            Route::put('hrms/engagement/templates/{template}', [SurveyTemplateController::class, 'update']);
            Route::delete('hrms/engagement/templates/{template}', [SurveyTemplateController::class, 'destroy']);
            Route::put('hrms/engagement/templates/{template}/questions', [SurveyTemplateController::class, 'setQuestions']);

            Route::get('hrms/engagement/campaigns', [SurveyCampaignController::class, 'index']);
            Route::post('hrms/engagement/campaigns', [SurveyCampaignController::class, 'store']);
            Route::get('hrms/engagement/campaigns/{campaign}', [SurveyCampaignController::class, 'show']);
            Route::post('hrms/engagement/campaigns/{campaign}/open', [SurveyCampaignController::class, 'open']);
            Route::post('hrms/engagement/campaigns/{campaign}/close', [SurveyCampaignController::class, 'close']);
            Route::post('hrms/engagement/campaigns/{campaign}/invite', [SurveyCampaignController::class, 'invite']);
            Route::get('hrms/engagement/campaigns/{campaign}/results', [SurveyCampaignController::class, 'results']);
            // One answer per invitee per campaign by design, but the endpoint
            // still writes — same thirty-a-minute ceiling as the other writers.
            Route::post('hrms/engagement/campaigns/{campaign}/respond', [SurveyCampaignController::class, 'respond'])->middleware('throttle:30,1');

            Route::get('hrms/engagement/my', [SurveyCampaignController::class, 'mine']);
            Route::get('hrms/engagement/my/{campaign}', [SurveyCampaignController::class, 'mySurvey']);
        });

        // HRMS workforce analytics (Phase 18 P18.3). Same two gates plus the
        // analytics module; every domain route carries its own permission —
        // never a blanket `workspaces.view` (the Phase 7 precedent) — and the
        // tiers inside shared endpoints (ratings, liability) read the caller's
        // permissions in the controller.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.analytics', 'permission:hrms.view']], function () {
            // CSV takes the domain as a parameter, so no static route can carry
            // its gate — the controller authorizes each domain against the same
            // permission slug as the tab route below.
            Route::get('hrms/analytics/export', [HrmsAnalyticsExportController::class, 'export'])->middleware('throttle:30,1');
            Route::get('hrms/analytics/overview', [HrmsAnalyticsController::class, 'overview'])->middleware('permission:hrms.analytics.view');
            Route::get('hrms/analytics/attendance', [HrmsAnalyticsController::class, 'attendance'])->middleware('permission:hrms.attendance.view');
            Route::get('hrms/analytics/leave', [HrmsAnalyticsController::class, 'leave'])->middleware('permission:hrms.leave.manage');
            Route::get('hrms/analytics/lifecycle', [HrmsAnalyticsController::class, 'lifecycle'])->middleware('permission:hrms.analytics.view');
            Route::get('hrms/analytics/performance', [HrmsAnalyticsController::class, 'performance'])->middleware('permission:hrms.analytics.view');
            Route::get('hrms/analytics/payroll', [HrmsAnalyticsController::class, 'payroll'])->middleware('permission:hrms.payroll.run');
            Route::get('hrms/analytics/documents', [HrmsAnalyticsController::class, 'documents'])->middleware('permission:hrms.documents.view');
            Route::get('hrms/analytics/assets', [HrmsAnalyticsController::class, 'assets'])->middleware('permission:hrms.assets.view');
        });

        // HRMS audit trail (Phase 19 P19.1). The ledger is cross-cutting, so no
        // per-record policy can answer for it — `hrms.audit.view` is the whole
        // gate, on both the filtered index and the single-record trail. The
        // `{subjectType}` is the stored morph class; `{subjectId}` is numeric
        // so a mistyped type still 404s instead of querying garbage.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
            Route::get('hrms/audit', [AuditController::class, 'index'])->middleware('permission:hrms.audit.view');
            Route::get('hrms/audit/data-access', [AuditController::class, 'dataAccess'])->middleware('permission:hrms.audit.view');
            Route::get('hrms/audit/{subjectType}/{subjectId}', [AuditController::class, 'trail'])
                ->middleware('permission:hrms.audit.view')
                ->whereNumber('subjectId');
        });

        // HRMS task links (Phase 20 P20.2). The bridge rows answer to the
        // project policy on the task side (view to read, edit to file or
        // cut) and the employee policy on the person side; the employee's
        // task list additionally intersects with the caller's visible
        // tasks, so a link never widens what the login may see.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
            Route::get('hrms/tasks/{task}/links', [TaskLinkController::class, 'index']);
            Route::post('hrms/tasks/{task}/links', [TaskLinkController::class, 'store']);
            Route::delete('hrms/tasks/{task}/links/{link}', [TaskLinkController::class, 'destroy']);
            Route::get('hrms/employees/{employee}/tasks', [TaskLinkController::class, 'employeeTasks']);
        });

        // HRMS assets (Phase 15 P14.3a). Same two gates plus the assets module;
        // the asset policy splits reads (view), movements (manage) and receipts
        // (the holder alone), and the category policy is master-data shaped.
        // The invoice download sits outside like every signed file route: the
        // signature is the credential, so `asset` is an int resolved inside the
        // tenant connection.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.assets', 'permission:hrms.view']], function () {
            Route::get('hrms/assets/categories', [AssetCategoryController::class, 'index']);
            Route::post('hrms/assets/categories', [AssetCategoryController::class, 'store']);
            Route::get('hrms/assets/categories/{category}', [AssetCategoryController::class, 'show']);
            Route::put('hrms/assets/categories/{category}', [AssetCategoryController::class, 'update']);
            Route::delete('hrms/assets/categories/{category}', [AssetCategoryController::class, 'destroy']);

            Route::get('hrms/assets', [AssetController::class, 'index']);
            Route::post('hrms/assets', [AssetController::class, 'store']);
            Route::get('hrms/assets/{asset}', [AssetController::class, 'show']);
            Route::post('hrms/assets/{asset}/assign', [AssetController::class, 'assign']);
            Route::post('hrms/assets/{asset}/return', [AssetController::class, 'returnAsset']);
            Route::post('hrms/assets/{asset}/maintenance', [AssetController::class, 'maintenance']);
            Route::post('hrms/assets/assignments/{assignment}/acknowledge', [AssetController::class, 'acknowledge']);
            Route::get('hrms/my/assets', [AssetController::class, 'mine']);
        });

        // NOTE: `hrms/assets/{asset}/document` is NOT declared here even though
        // it reads like an asset route: it is a signed session-free download
        // (the signature is the credential, like every signed file route), so
        // it lives with the other signed downloads below, outside all
        // auth/tenant groups. Declaring it here would stack the group's `auth`
        // on top and 401 every fresh-tab download.

        // HRMS performance, first half (Phase 15 P12.4a): cycles, goals,
        // check-ins and 1:1s. Same two gates plus the performance module; the
        // four policies split reads (self, manager, or view) from moves
        // (manage, or self-while-draft for goals). Feedback, reviews and the
        // evidence read land in P12.4b.
        Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.performance', 'permission:hrms.view']], function () {
            Route::get('hrms/performance/cycles', [PerformanceCycleController::class, 'index']);
            Route::post('hrms/performance/cycles', [PerformanceCycleController::class, 'store']);
            Route::get('hrms/performance/cycles/{cycle}', [PerformanceCycleController::class, 'show']);
            Route::post('hrms/performance/cycles/{cycle}/open-check-in', [PerformanceCycleController::class, 'openCheckIn']);
            Route::post('hrms/performance/cycles/{cycle}/open-self-review', [PerformanceCycleController::class, 'openSelfReview']);
            Route::post('hrms/performance/cycles/{cycle}/open-manager-review', [PerformanceCycleController::class, 'openManagerReview']);
            Route::post('hrms/performance/cycles/{cycle}/open-calibration', [PerformanceCycleController::class, 'openCalibration']);
            Route::post('hrms/performance/cycles/{cycle}/complete', [PerformanceCycleController::class, 'complete']);

            Route::get('hrms/performance/cycles/{cycle}/goals', [PerformanceGoalController::class, 'index']);
            Route::post('hrms/performance/cycles/{cycle}/goals', [PerformanceGoalController::class, 'store']);
            Route::get('hrms/performance/goals/{goal}', [PerformanceGoalController::class, 'show']);
            Route::put('hrms/performance/goals/{goal}', [PerformanceGoalController::class, 'update']);
            Route::post('hrms/performance/goals/{goal}/refresh', [PerformanceGoalController::class, 'refresh']);
            Route::post('hrms/performance/goals/{goal}/tasks', [PerformanceGoalController::class, 'linkTask']);
            Route::delete('hrms/performance/goals/{goal}/tasks/{task}', [PerformanceGoalController::class, 'unlinkTask']);

            Route::get('hrms/performance/cycles/{cycle}/check-ins', [CheckInController::class, 'index']);
            Route::post('hrms/performance/cycles/{cycle}/check-ins', [CheckInController::class, 'store']);

            // HRMS feedback, reviews and evidence (Phase 15 P12.4b). Same
            // gates; the feedback policy names who may answer (the reviewer
            // alone), the review policy splits readers by visibility, and the
            // evidence read never recomputes — the refresh endpoint does.
            Route::get('hrms/performance/cycles/{cycle}/feedback-requests', [FeedbackRequestController::class, 'index']);
            Route::get('hrms/performance/feedback-requests/{feedbackRequest}', [FeedbackRequestController::class, 'show']);
            Route::post('hrms/performance/feedback-requests/{feedbackRequest}/respond', [FeedbackRequestController::class, 'respond']);

            Route::get('hrms/performance/cycles/{cycle}/reviews', [ReviewSummaryController::class, 'index']);
            Route::post('hrms/performance/cycles/{cycle}/reviews', [ReviewSummaryController::class, 'store']);
            Route::get('hrms/performance/reviews/{review}', [ReviewSummaryController::class, 'show']);
            Route::put('hrms/performance/reviews/{review}', [ReviewSummaryController::class, 'update']);
            Route::post('hrms/performance/reviews/{review}/acknowledge', [ReviewSummaryController::class, 'acknowledge']);

            Route::get('hrms/performance/cycles/{cycle}/evidence', [PerformanceCycleController::class, 'evidence']);
            Route::post('hrms/performance/cycles/{cycle}/evidence', [PerformanceCycleController::class, 'refreshEvidence']);

            Route::get('hrms/performance/one-on-ones', [OneOnOneController::class, 'index']);
            Route::post('hrms/performance/one-on-ones', [OneOnOneController::class, 'store']);
            Route::get('hrms/performance/one-on-ones/{oneOnOne}', [OneOnOneController::class, 'show']);
            Route::put('hrms/performance/one-on-ones/{oneOnOne}', [OneOnOneController::class, 'update']);
        });

        // HRMS remote punch (Phase 15 P5.3). Deliberately NO route-level
        // permission: the endpoint resolves the employment record from the
        // authenticated user, so there is nothing to authorize against and no
        // login may punch for another. The module 403 is the upgrade path, not
        // a permission 403 — the client shows `/403` for the former.
        Route::group(['middleware' => ['ensure_module:hrms.attendance.remote']], function () {
            // Punching is self-only and audited, but it still writes rows —
            // thirty a minute is generous for a shift boundary and stops a
            // stuck client from flooding the ledger.
            Route::post('hrms/attendance/punch', [AttendanceController::class, 'punch'])->middleware('throttle:30,1');
        });

    }); // tenant_context + onboarding_complete over the HRMS block

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

    // Same shape for an employee photo: outside switch_tenant, so the central
    // tenant id travels inside the signature and the controller resolves the
    // record inside TenantDatabaseManager::using(). `employee` is intentionally
    // an int, not a route-model-bound Employee — binding would query the central
    // connection, where the employees table does not exist.
    Route::get('hrms/employees/{employee}/photo', [EmployeeController::class, 'photo'])
        ->middleware('signed')
        ->name('hrms.employees.photo');

    // Same shape for an employee document: outside switch_tenant, so the
    // central tenant id travels inside the signature and the controller
    // resolves the record inside TenantDatabaseManager::using(). `document`
    // is intentionally an int, not a route-model-bound EmployeeDocument —
    // binding would query the central connection, where employee_documents
    // does not exist.
    Route::get('hrms/documents/{document}/download', DocumentDownloadController::class)
        ->middleware('signed')
        ->name('hrms.documents.download');

    // Same shape for a rendered payslip: outside switch_tenant, so the
    // central tenant id travels inside the signature and the controller
    // resolves the record inside TenantDatabaseManager::using(). `payslip`
    // is intentionally an int, not a route-model-bound Payslip — binding
    // would query the central connection, where payslips does not exist.
    Route::get('hrms/payroll/payslips/{payslip}/download', PayslipDownloadController::class)
        ->middleware('signed')
        ->name('hrms.payslips.download');

    // Same shape for an asset invoice file: outside switch_tenant and outside
    // every auth/tenant group, so a fresh-tab GET works on the signature alone.
    // `asset` is intentionally an int resolved inside the tenant connection.
    Route::get('hrms/assets/{asset}/document', [AssetController::class, 'document'])
        ->middleware('signed')
        ->name('hrms.assets.document');

    // Phase 5: full-tenant export ZIP download. Same session-free pattern as
    // every signed file route above. The central tenant id travels inside the
    // signature; the ExportRun lookup happens inside TenantDatabaseManager::using().
    // `run` is an int (not model-bound — the default connection is central here).
    Route::get('exports/{run}/download', [ExportController::class, 'download'])
        ->middleware('signed')
        ->name('exports.download');
});

// Phase 6: Public payment webhook endpoints (outside auth/tenant groups).
Route::post('api/webhooks/stripe', [WebhookController::class, 'handleStripe'])->middleware('throttle:60,1');
Route::post('api/webhooks/razorpay', [WebhookController::class, 'handleRazorpay'])->middleware('throttle:60,1');

// Public marketing site (server-rendered from the DB-backed CMS pages).
Route::get('/', [PublicSiteController::class, 'home']);
Route::get('page/{slug}', [PublicSiteController::class, 'page']);

// Named reset landing for the framework's ResetPassword mail: the notification
// builds its URL via `route('password.reset', token + email)`, and without this
// name every forgot-password request for a REAL address 500s with
// "Route [password.reset] not defined" (unknown addresses never reach the
// mail build, which is how the breakage hid from the uniform-response shape).
// It redirects straight into the SPA reset page, which owns the form.
Route::get('/reset-password/{token}', function (Request $request, string $token) {
    return redirect()->to('/app/reset-password?'.http_build_query([
        'token' => $token,
        'email' => $request->query('email'),
    ]));
})->name('password.reset');
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
Broadcast::routes(['middleware' => ['switch_tenant', 'auth', 'tenant']]);

require __DIR__.'/../routes/channels.php';
