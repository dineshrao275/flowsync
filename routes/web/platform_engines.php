<?php

use App\Http\Controllers\ApiTokenController;
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
