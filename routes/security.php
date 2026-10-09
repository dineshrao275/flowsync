<?php

use App\Http\Controllers\FeatureFlagController;
use App\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
 | Security track routes (P8.x), kept out of web.php on purpose. Required from
 | routes/web.php with one line. Same stacks as the main file:
 | platform = switch_tenant -> auth -> tenant -> super_admin.
 */
Route::prefix('api')->group(function () {
    // P8.1 sign-in step two: unauthenticated (the session holds the pending login).
    Route::post('auth/2fa/challenge', [TwoFactorController::class, 'challenge'])->middleware('throttle:10,1');
});

Route::prefix('api')->middleware(['switch_tenant', 'auth', 'tenant'])->group(function () {
    // P8.1 self-service enrolment for any signed-in account (tenant user or platform admin).
    Route::get('auth/2fa', [TwoFactorController::class, 'status']);
    Route::post('auth/2fa/setup', [TwoFactorController::class, 'setup'])->middleware('throttle:10,1');
    Route::post('auth/2fa/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1');
    Route::post('auth/2fa/disable', [TwoFactorController::class, 'disable'])->middleware('throttle:6,1');
    Route::post('auth/2fa/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->middleware('throttle:6,1');

    // P8.1 enforce-per-role: a tenant admin picks the roles that must use 2FA.
    Route::middleware(['tenant_context', 'permission:roles.manage'])->group(function () {
        Route::get('security/two-factor-policy', [TwoFactorController::class, 'showTenantPolicy']);
        Route::put('security/two-factor-policy', [TwoFactorController::class, 'updateTenantPolicy']);
    });

    Route::middleware('super_admin')->group(function () {
        Route::get('system/two-factor-policy', [TwoFactorController::class, 'showPlatformPolicy']);
        Route::put('system/two-factor-policy', [TwoFactorController::class, 'updatePlatformPolicy']);

        // P8.7 runtime feature flags + tenant overrides.
        Route::get('system/feature-flags', [FeatureFlagController::class, 'index']);
        Route::post('system/feature-flags', [FeatureFlagController::class, 'store'])->middleware('throttle:30,1');
        Route::put('system/feature-flags/{flag}', [FeatureFlagController::class, 'update']);
        Route::delete('system/feature-flags/{flag}', [FeatureFlagController::class, 'destroy']);
        Route::put('system/feature-flags/{flag}/overrides/{tenant}', [FeatureFlagController::class, 'setOverride']);
        Route::delete('system/feature-flags/{flag}/overrides/{tenant}', [FeatureFlagController::class, 'clearOverride']);
    });
});
