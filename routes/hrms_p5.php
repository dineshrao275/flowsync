<?php

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
});
