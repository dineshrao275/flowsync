<?php

use App\Http\Controllers\GitConnectionController;
use Illuminate\Support\Facades\Route;

/*
 | Git integration (GitHub App). Kept out of web.php like the other feature route files.
 | Tenant routes: switch_tenant -> auth -> tenant -> tenant_context, then the TMS product and
 | the `git` module (a plan without it answers 403 before any controller runs).
 */
Route::prefix('api')->group(function () {
    // GitHub's post-install redirect: public (the encrypted one-time state is the proof), throttled.
    Route::get('integrations/git/github/setup', [GitConnectionController::class, 'setup'])->middleware('throttle:30,1');

    Route::middleware(['switch_tenant', 'auth', 'tenant', 'tenant_context', 'ensure_subscribed', 'onboarding_complete', 'ensure_product:tms', 'ensure_module:git'])->group(function () {
        Route::get('integrations/git/connections', [GitConnectionController::class, 'index'])->middleware('permission:git.view');
        Route::get('integrations/git/github/install', [GitConnectionController::class, 'install'])->middleware(['permission:git.manage', 'throttle:20,1']);
        Route::delete('integrations/git/connections/{uuid}', [GitConnectionController::class, 'destroy'])->whereUuid('uuid')->middleware('permission:git.manage');
    });
});
