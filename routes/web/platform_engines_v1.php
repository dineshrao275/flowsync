<?php

use App\Http\Controllers\ApiV1Controller;
use App\Http\Controllers\Hrms\EmployeeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

// Bearer-token API (P2.7). Required from routes/web.php at the top level: these routes do NOT use
// the session group — `api_token` connects the tenant the token names and sets the owner as the
// request user. The SPA never calls them; it keeps the session routes.
//
// Each route needs the token to carry the ability (`token_can`) AND the owner to pass the same
// policy/row scope the session route applies (the controllers are the session controllers).

Route::prefix('api/v1')
    ->middleware(['api_token', 'tenant_context', 'onboarding_complete', 'ensure_module:api', 'throttle:api-token'])
    ->group(function () {
        Route::get('me', [ApiV1Controller::class, 'me']);

        Route::middleware('token_can:workspaces.view')->group(function () {
            Route::get('projects', [ProjectController::class, 'indexAll'])->middleware('ensure_product:tms');
            Route::get('projects/{project}/tasks', [TaskController::class, 'index'])->middleware('ensure_product:tms');
            Route::post('projects/{project}/tasks', [TaskController::class, 'store'])->middleware(['ensure_product:tms', 'idempotent']);
            Route::get('search/tasks', [SearchController::class, 'tasks'])->middleware('ensure_module:global_search');
        });

        Route::get('hrms/employees', [EmployeeController::class, 'index'])->middleware(['ensure_module:hrms.core', 'token_can:hrms.view']);
    });
