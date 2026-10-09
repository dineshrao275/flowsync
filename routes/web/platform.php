<?php

use App\Http\Controllers\AuditLogsController;
use App\Http\Controllers\CmsController;
use App\Http\Controllers\FeatureManagementController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlatformHealthController;
use App\Http\Controllers\SystemAnalyticsController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\SystemSupportController;
use App\Http\Controllers\SystemUsersController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantHrmsController;
use App\Http\Controllers\TenantIntakeController;
use App\Http\Controllers\TenantSubscriptionController;
use App\Http\Controllers\TenantSummaryController;
use Illuminate\Support\Facades\Route;

// Platform administration (super admin): tenants, plans, system settings, CMS, support desk staff side, impersonation start.
// Required from routes/web.php inside the same middleware group the block lived in,
// at the same position, so route order (and `route:list`) is unchanged.

Route::middleware('super_admin')->group(function () {
    // Phase 13: tenancy platform (central DB index across tenant DBs).
    Route::get('tenants', [TenantController::class, 'index']);
    Route::post('tenants', [TenantIntakeController::class, 'store'])->middleware('throttle:10,1');
    Route::get('tenants/{tenant}/intake', [TenantIntakeController::class, 'show']);
    Route::put('tenants/{tenant}/intake', [TenantIntakeController::class, 'update']);
    Route::post('tenants/{tenant}/intake/submit', [TenantIntakeController::class, 'submit'])->middleware('throttle:10,1');
    Route::get('tenants/summary', TenantSummaryController::class);
    Route::get('system/support/tickets', [SystemSupportController::class, 'index']);
    Route::get('system/support/tickets/{ticket}', [SystemSupportController::class, 'show']);
    Route::post('system/support/tickets/{ticket}/messages', [SystemSupportController::class, 'reply'])->middleware('throttle:60,1');
    Route::put('system/support/tickets/{ticket}', [SystemSupportController::class, 'update']);
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
    Route::post('tenants/{tenant}/subscription/split', [TenantSubscriptionController::class, 'split']);
    Route::put('tenants/{tenant}/products', [TenantSubscriptionController::class, 'products']);
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
