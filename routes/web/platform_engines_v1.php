<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ApiV1Controller;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\Hrms\EmployeeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SprintController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskMoveController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\WorkLogController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

// Bearer-token API (P2.7). Required from routes/web.php at the top level: these routes do NOT use
// the session group — `api_token` connects the tenant the token names and sets the owner as the
// request user. The SPA never calls them; it keeps the session routes.
//
// Each route needs the token to carry the ability (`token_can`) AND the owner to pass the same
// policy/row scope the session route applies (the controllers are the session controllers).

Route::prefix('api/v1')
    ->middleware(['api_token', 'tenant_context', 'locale', 'onboarding_complete', 'ensure_module:api', 'throttle:api-token'])
    ->group(function () {
        Route::get('me', [ApiV1Controller::class, 'me']);

        // TMS Read Surface
        Route::middleware(['token_can:tms.read', 'ensure_product:tms'])->group(function () {
            Route::get('workspaces', [WorkspaceController::class, 'index']);
            Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show']);
            Route::get('projects', [ProjectController::class, 'indexAll']);
            Route::get('projects/{project}', [ProjectController::class, 'show']);
            Route::get('projects/{project}/tasks', [TaskController::class, 'index']);
            Route::get('projects/{project}/tasks/key/{key}', [TaskController::class, 'showByKey'])->where('key', '[A-Za-z0-9-]+');
            Route::get('projects/{project}/tasks/{task}', [TaskController::class, 'show']);
            Route::get('projects/{project}/tasks/{task}/comments', [CommentController::class, 'index']);
            Route::get('projects/{project}/tasks/{task}/work-logs', [WorkLogController::class, 'index'])->middleware('ensure_module:time_tracking');
            Route::get('projects/{project}/tasks/{task}/transitions', [WorkflowController::class, 'transitions']);
            Route::get('projects/{project}/tasks/{task}/activities', [ActivityController::class, 'task']);
            Route::get('projects/{project}/sprints', [SprintController::class, 'index'])->middleware('ensure_module:sprints');
            Route::get('search/tasks', [SearchController::class, 'tasks'])->middleware('ensure_module:global_search');
        });

        // TMS Write Surface (can_write enforced in AuthenticateApiToken, idempotent writes, throttled).
        // `token_can` stays first in each group so a refused request never reaches idempotency. A legacy
        // `workspaces.view` write token could only ever create a task, so only that route also accepts it;
        // edit/move/comment/log-time need `tms.write`.
        Route::middleware(['token_can:tms.write,workspaces.view', 'ensure_product:tms', 'idempotent', 'throttle:30,1', 'api_markdown'])->group(function () {
            Route::post('projects/{project}/tasks', [TaskController::class, 'store']);
        });

        Route::middleware(['token_can:tms.write', 'ensure_product:tms', 'idempotent', 'throttle:30,1'])->group(function () {
            Route::put('projects/{project}/tasks/{task}', [TaskController::class, 'update'])->middleware('api_markdown');
            Route::post('projects/{project}/tasks/{task}/move', [TaskMoveController::class, 'move']);
            Route::post('projects/{project}/tasks/{task}/comments', [CommentController::class, 'store']);
            Route::post('projects/{project}/tasks/{task}/work-logs', [WorkLogController::class, 'store'])->middleware('ensure_module:time_tracking');
        });

        Route::get('hrms/employees', [EmployeeController::class, 'index'])->middleware(['ensure_module:hrms.core', 'token_can:hrms.view']);
    });
