<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\Hrms\Asset\AssetController;
use App\Http\Controllers\Hrms\DocumentDownloadController;
use App\Http\Controllers\Hrms\EmployeeController;
use App\Http\Controllers\Hrms\InboxController;
use App\Http\Controllers\Hrms\MyHrController;
use App\Http\Controllers\Hrms\MyTeamController;
use App\Http\Controllers\Hrms\Payroll\PayslipDownloadController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\IssueTypeController;
use App\Http\Controllers\MySubscriptionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlatformHealthController;
use App\Http\Controllers\ProjectRoleController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\RegisterCardController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ResetPasswordController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\UserAccessController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserImportController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WebhookEndpointController;
use App\Http\Controllers\WorkspaceController;
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
    Route::post('register/card', [RegisterCardController::class, 'card'])->middleware('throttle:10,1');
    Route::post('register/complete', [RegisterCardController::class, 'complete'])->middleware('throttle:10,1');
    Route::get('register/options', [RegisterController::class, 'options'])->middleware('throttle:30,1');

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

        // Support desk (FB-7): tenant admins raise tickets with the platform team.
        Route::get('support/tickets', [SupportTicketController::class, 'index'])->middleware('permission:support.manage');
        Route::post('support/tickets', [SupportTicketController::class, 'store'])->middleware(['permission:support.manage', 'throttle:10,1']);
        Route::get('support/tickets/{ticket}', [SupportTicketController::class, 'show'])->middleware('permission:support.manage');
        Route::post('support/tickets/{ticket}/messages', [SupportTicketController::class, 'reply'])->middleware(['permission:support.manage', 'throttle:30,1']);
        Route::post('support/tickets/{ticket}/close', [SupportTicketController::class, 'close'])->middleware('permission:support.manage');

        // Outbound webhooks: tenant admins point signed event delivery at their own systems.
        Route::middleware(['ensure_module:webhooks', 'tenant_context'])->group(function () {
            Route::get('webhooks', [WebhookEndpointController::class, 'index'])->middleware('permission:webhooks.manage');
            Route::post('webhooks', [WebhookEndpointController::class, 'store'])->middleware(['permission:webhooks.manage', 'throttle:20,1']);
            Route::put('webhooks/{webhook}', [WebhookEndpointController::class, 'update'])->middleware('permission:webhooks.manage');
            Route::delete('webhooks/{webhook}', [WebhookEndpointController::class, 'destroy'])->middleware('permission:webhooks.manage');
            Route::post('webhooks/{webhook}/rotate-secret', [WebhookEndpointController::class, 'rotateSecret'])->middleware(['permission:webhooks.manage', 'throttle:10,1']);
            Route::post('webhooks/{webhook}/test', [WebhookEndpointController::class, 'test'])->middleware(['permission:webhooks.manage', 'throttle:20,1']);
            Route::get('webhooks/{webhook}/deliveries', [WebhookEndpointController::class, 'deliveries'])->middleware('permission:webhooks.manage');
            Route::post('webhook-deliveries/{delivery}/redeliver', [WebhookEndpointController::class, 'redeliver'])->middleware(['permission:webhooks.manage', 'throttle:20,1']);
        });

        // Phase 6: tenant payments & billing
        Route::post('billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1');
        Route::post('billing/portal', [BillingController::class, 'portal'])->middleware('throttle:10,1');
        Route::post('billing/verify', [BillingController::class, 'verify'])->middleware('throttle:10,1');
        Route::get('billing/history', [BillingController::class, 'history']);
        Route::get('billing/invoices', [InvoiceController::class, 'index']);
        Route::get('billing/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('invoice');
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

        require __DIR__.'/web/platform.php';

        Route::post('impersonate/stop', [ImpersonationController::class, 'stop'])->middleware('throttle:30,1');
    });

    Route::middleware(['switch_tenant', 'auth', 'tenant', 'tenant_context', 'onboarding_complete'])->group(function () {
        // Tenant user administration. Lives in the tenant_context group because
        // the users table lives in the tenant's own database: a non-impersonating
        // super admin has nothing to route to and is rejected by the middleware
        // (an impersonating super admin is the impersonated tenant user, so the
        // tenant-context + `users.manage` checks both apply to them).
        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::get('users/import/sample', [UserImportController::class, 'sample'])->middleware('permission:users.manage');
        Route::post('users/import/preview', [UserImportController::class, 'preview'])->middleware(['permission:users.manage', 'throttle:20,1']);
        Route::post('users/import', [UserImportController::class, 'store'])->middleware(['permission:users.manage', 'throttle:10,1']);
        Route::post('users', [UserController::class, 'store'])->middleware(['permission:users.manage', 'throttle:30,1']);
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.manage');
        Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])->middleware('permission:users.manage');
        // "Why can't they?": which role/scope/plan-module link grants or blocks one permission.
        Route::get('users/{user}/access', [UserAccessController::class, 'show'])->middleware('permission:roles.view');
        // The tenant's protected default user: shiftable by a tenant admin or a
        // super admin (onto another admin), and never deletable.
        Route::put('users/{user}/default', [UserController::class, 'makeDefault'])->middleware('permission:users.manage');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.manage');

        require __DIR__.'/web/tms.php';

        // Phase 7: discovery + reporting on the same tenant_context scope.
        // Global task search rides the domain `workspaces.view` permission;
        // dashboard and reports are gated by their own tenant permissions.
        Route::get('search/tasks', [SearchController::class, 'tasks'])->middleware(['permission:workspaces.view', 'ensure_module:global_search']);

        Route::get('dashboard', [DashboardController::class, '__invoke'])->middleware(['permission:dashboard.view', 'ensure_product:tms']);

        Route::get('analytics/overview', [AnalyticsController::class, '__invoke'])->middleware(['permission:dashboard.view', 'ensure_product:tms']);

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

        require __DIR__.'/web/hrms_people.php';

        require __DIR__.'/web/hrms_time.php';

        require __DIR__.'/web/hrms_payroll.php';

        require __DIR__.'/web/hrms_talent.php';

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
