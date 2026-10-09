<?php

use App\Http\Controllers\FeatureFlagController;
use Illuminate\Support\Facades\Route;

/*
 | Security track routes (P8.x), kept out of web.php on purpose. Required from
 | routes/web.php with one line. Same stacks as the main file:
 | platform = switch_tenant -> auth -> tenant -> super_admin.
 */
Route::prefix('api')->middleware(['switch_tenant', 'auth', 'tenant'])->group(function () {
    Route::middleware('super_admin')->group(function () {
        // P8.7 runtime feature flags + tenant overrides.
        Route::get('system/feature-flags', [FeatureFlagController::class, 'index']);
        Route::post('system/feature-flags', [FeatureFlagController::class, 'store'])->middleware('throttle:30,1');
        Route::put('system/feature-flags/{flag}', [FeatureFlagController::class, 'update']);
        Route::delete('system/feature-flags/{flag}', [FeatureFlagController::class, 'destroy']);
        Route::put('system/feature-flags/{flag}/overrides/{tenant}', [FeatureFlagController::class, 'setOverride']);
        Route::delete('system/feature-flags/{flag}/overrides/{tenant}', [FeatureFlagController::class, 'clearOverride']);
    });
});
