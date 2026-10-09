<?php

use App\Http\Controllers\Hrms\Employee\EmployeeBulkController;
use App\Http\Controllers\Hrms\Shift\RosterController;
use App\Http\Controllers\Hrms\Shift\RotationController;
use App\Http\Controllers\Hrms\Shift\ShiftController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HRMS Phase 5 — completion slices
|--------------------------------------------------------------------------
|
| Required from inside the tenant HRMS group in routes/web.php
| (`switch_tenant → auth → tenant → tenant_context → onboarding_complete`), so
| every route here inherits that stack. Each slice adds its own module gate on
| top; policies decide per record.
*/

// Shifts catalogue (P5.1). Literals (`default/clear`) are declared before any
// `{shift}` sibling so they never bind as an id (the P3.3 lesson).
Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.shifts', 'permission:hrms.view']], function () {
    Route::get('hrms/shifts', [ShiftController::class, 'index']);
    Route::post('hrms/shifts', [ShiftController::class, 'store']);
    Route::post('hrms/shifts/default/clear', [ShiftController::class, 'clear']);
    Route::put('hrms/shifts/{shift}', [ShiftController::class, 'update']);
    Route::delete('hrms/shifts/{shift}', [ShiftController::class, 'destroy']);
    Route::post('hrms/shifts/{shift}/assign', [ShiftController::class, 'assign']);

    // Rosters and rotations (P5.2). `mine` is declared before `{roster}`.
    Route::get('hrms/shifts/rosters', [RosterController::class, 'index']);
    Route::get('hrms/shifts/rosters/mine', [RosterController::class, 'mine']);
    Route::post('hrms/shifts/rosters', [RosterController::class, 'store']);
    Route::delete('hrms/shifts/rosters/{roster}', [RosterController::class, 'destroy']);

    Route::get('hrms/shifts/rotations', [RotationController::class, 'index']);
    Route::post('hrms/shifts/rotations', [RotationController::class, 'store']);
    Route::put('hrms/shifts/rotations/{rotation}', [RotationController::class, 'update']);
    Route::delete('hrms/shifts/rotations/{rotation}', [RotationController::class, 'destroy']);
    Route::post('hrms/shifts/rotations/{rotation}/apply', [RotationController::class, 'apply']);
});

// Employee bulk operations (P5.5): CSV hire (sample/preview/commit) and bulk
// status change. Gated on the core module; policies decide (employees.manage).
Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
    Route::get('hrms/employees/import/sample', [EmployeeBulkController::class, 'sample']);
    Route::post('hrms/employees/import/preview', [EmployeeBulkController::class, 'preview'])->middleware('throttle:20,1');
    Route::post('hrms/employees/import', [EmployeeBulkController::class, 'import'])->middleware('throttle:10,1');
    Route::post('hrms/employees/bulk-status', [EmployeeBulkController::class, 'status'])->middleware('throttle:20,1');
});
