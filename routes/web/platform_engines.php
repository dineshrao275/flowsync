<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\CustomFieldController;
use Illuminate\Support\Facades\Route;

// Platform engines (session side): API token management. Required from routes/web.php inside the
// tenant domain group (switch_tenant -> auth -> tenant -> tenant_context -> onboarding_complete).
// The bearer-token side lives in routes/web/platform_engines_v1.php.

Route::middleware(['ensure_module:api', 'permission:api.manage'])->group(function () {
    Route::get('api-tokens', [ApiTokenController::class, 'index']);
    Route::post('api-tokens', [ApiTokenController::class, 'store'])->middleware('throttle:20,1');
    Route::get('api-tokens/{apiToken}/logs', [ApiTokenController::class, 'logs']);
    Route::delete('api-tokens/{apiToken}', [ApiTokenController::class, 'destroy']);
});

// Custom fields (F2.1): definitions are readable by any signed-in user (forms render from them);
// writes need custom_fields.manage. The values themselves ride on each entity's own endpoints.
Route::middleware('ensure_module:custom_fields')->group(function () {
    Route::get('custom-fields', [CustomFieldController::class, 'index']);
    Route::middleware('permission:custom_fields.manage')->group(function () {
        Route::post('custom-fields', [CustomFieldController::class, 'store'])->middleware('throttle:60,1');
        Route::put('custom-fields/{field}', [CustomFieldController::class, 'update']);
        Route::delete('custom-fields/{field}', [CustomFieldController::class, 'destroy']);
    });
});
