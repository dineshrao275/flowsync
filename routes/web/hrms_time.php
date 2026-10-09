<?php

use App\Http\Controllers\Hrms\CompOff\CompOffCreditController;
use App\Http\Controllers\Hrms\CompOff\CompOffRequestController;
use App\Http\Controllers\Hrms\CompOff\CompOffSettingsController;
use App\Http\Controllers\Hrms\Holiday\HolidayAssignmentController;
use App\Http\Controllers\Hrms\Holiday\HolidayCalendarController;
use App\Http\Controllers\Hrms\Holiday\HolidayController;
use App\Http\Controllers\Hrms\Holiday\HolidayOptionalController;
use App\Http\Controllers\Hrms\Leave\LeaveBalanceController;
use App\Http\Controllers\Hrms\Leave\LeaveExemptionController;
use App\Http\Controllers\Hrms\Leave\LeavePolicyController;
use App\Http\Controllers\Hrms\Leave\LeaveRequestController;
use App\Http\Controllers\Hrms\Leave\LeaveTypeController;
use Illuminate\Support\Facades\Route;

// HRMS time: leave, exemptions, comp-off and holidays.
// Required from routes/web.php inside the same middleware group the block lived in,
// at the same position, so route order (and `route:list`) is unchanged.

// HRMS leave catalogue (Phase 15 P6.4a). Module-gated on `hrms.leave`
// with no route-level permission: reads are employee-open (filing needs
// the catalogue) and writes take `hrms.leave.manage`, both answered by
// the policy — the same self-service shape as the attendance reads.
Route::group(['middleware' => ['ensure_module:hrms.leave']], function () {
    Route::get('hrms/leave/types', [LeaveTypeController::class, 'index']);
    Route::post('hrms/leave/types', [LeaveTypeController::class, 'store']);
    Route::put('hrms/leave/types/{leaveType}', [LeaveTypeController::class, 'update']);
    Route::delete('hrms/leave/types/{leaveType}', [LeaveTypeController::class, 'destroy']);

    Route::get('hrms/leave/policies', [LeavePolicyController::class, 'index']);
    Route::post('hrms/leave/policies', [LeavePolicyController::class, 'store']);
    Route::put('hrms/leave/policies/{leavePolicy}', [LeavePolicyController::class, 'update']);
    Route::delete('hrms/leave/policies/{leavePolicy}', [LeavePolicyController::class, 'destroy']);

    // HRMS leave balances + accrual runs (Phase 15 P6.4b). Reads ride
    // the same employee-open policy as the catalogue; the run itself
    // takes `hrms.leave.manage` at the route and audits as a bulk.
    Route::get('hrms/leave/balances', [LeaveBalanceController::class, 'index']);
    Route::post('hrms/leave/accrue', [LeaveBalanceController::class, 'accrue'])
        ->middleware('permission:hrms.leave.manage');

    // HRMS leave asks (Phase 15 P6.4c). Same gate shape: module-gated,
    // policy-authorized per record (self-service included, deciding
    // belongs to the step's approver). `cancel` carries an optional
    // reason where DELETE stays reason-free, per the plan's routes.
    Route::get('hrms/leave/requests', [LeaveRequestController::class, 'index']);
    Route::post('hrms/leave/requests', [LeaveRequestController::class, 'store']);
    Route::get('hrms/leave/requests/availability', [LeaveRequestController::class, 'availability']);
    Route::get('hrms/leave/requests/calendar', [LeaveRequestController::class, 'calendar']);
    Route::get('hrms/leave/requests/{leaveRequest}', [LeaveRequestController::class, 'show']);
    Route::put('hrms/leave/requests/{leaveRequest}', [LeaveRequestController::class, 'update']);
    Route::delete('hrms/leave/requests/{leaveRequest}', [LeaveRequestController::class, 'destroy']);
    Route::post('hrms/leave/requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);
    Route::post('hrms/leave/requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
    Route::post('hrms/leave/requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);
});

// HRMS statutory exemptions (Phase 15 P6.4c). Its own module gate per
// the 3.5 mapping — an exemption is jurisdiction trail, not leave
// administration, and the two entitlements move separately.
Route::group(['middleware' => ['ensure_module:hrms.leave.exemption']], function () {
    Route::get('hrms/leave/exemptions', [LeaveExemptionController::class, 'index']);
    Route::post('hrms/leave/exemptions', [LeaveExemptionController::class, 'store']);
    Route::get('hrms/leave/exemptions/{exemption}', [LeaveExemptionController::class, 'show']);
    Route::post('hrms/leave/exemptions/{exemption}/decide', [LeaveExemptionController::class, 'decide']);
});

// HRMS comp-off (Phase 15 P7.3). Module-gated on `hrms.comp_off` with
// no route-level permission except the manage-only writes: reads and
// asks are policy-gated per record (self-service included, deciding
// belongs to the step's approver), while manual grants, settings and
// runs take `hrms.comp_off.manage` at the route.
Route::group(['middleware' => ['ensure_module:hrms.comp_off']], function () {
    Route::get('hrms/comp-off/credits', [CompOffCreditController::class, 'index']);
    Route::post('hrms/comp-off/credits', [CompOffCreditController::class, 'store'])
        ->middleware('permission:hrms.comp_off.manage');

    Route::get('hrms/comp-off/requests', [CompOffRequestController::class, 'index']);
    Route::post('hrms/comp-off/requests', [CompOffRequestController::class, 'store']);
    Route::get('hrms/comp-off/requests/{compOffRequest}', [CompOffRequestController::class, 'show']);
    Route::delete('hrms/comp-off/requests/{compOffRequest}', [CompOffRequestController::class, 'destroy']);
    Route::post('hrms/comp-off/requests/{compOffRequest}/cancel', [CompOffRequestController::class, 'cancel']);
    Route::post('hrms/comp-off/requests/{compOffRequest}/approve', [CompOffRequestController::class, 'approve']);
    Route::post('hrms/comp-off/requests/{compOffRequest}/reject', [CompOffRequestController::class, 'reject']);

    Route::get('hrms/comp-off/settings', [CompOffSettingsController::class, 'show'])
        ->middleware('permission:hrms.comp_off.manage');
    Route::put('hrms/comp-off/settings', [CompOffSettingsController::class, 'update'])
        ->middleware('permission:hrms.comp_off.manage');
    Route::post('hrms/comp-off/accrue', [CompOffSettingsController::class, 'accrue'])
        ->middleware('permission:hrms.comp_off.manage');
});

// HRMS holidays (Phase 15 P8.4a). Module-gated on `hrms.holidays` with
// no route-level permission except the seed-year run: reads and writes
// answer per record through the policies. Nested holiday creation
// authorizes against the parent calendar, and `seed-year` is declared
// before any `{calendar}` sibling so a literal never binds as an id
// (the P3.3 `reorder` lesson).
Route::group(['middleware' => ['ensure_module:hrms.holidays']], function () {
    Route::get('hrms/holidays/calendars', [HolidayCalendarController::class, 'index']);
    Route::post('hrms/holidays/calendars', [HolidayCalendarController::class, 'store']);
    Route::post('hrms/holidays/seed-year', [HolidayCalendarController::class, 'seedYear'])
        ->middleware('permission:hrms.holidays.manage');
    Route::put('hrms/holidays/calendars/{calendar}', [HolidayCalendarController::class, 'update']);
    Route::delete('hrms/holidays/calendars/{calendar}', [HolidayCalendarController::class, 'destroy']);

    Route::get('hrms/holidays/calendars/{calendar}/holidays', [HolidayCalendarController::class, 'holidays']);
    Route::post('hrms/holidays/calendars/{calendar}/holidays', [HolidayCalendarController::class, 'storeHoliday']);
    Route::put('hrms/holidays/{holiday}', [HolidayController::class, 'update']);
    Route::delete('hrms/holidays/{holiday}', [HolidayController::class, 'destroy']);

    // HRMS assignments, the resolved view, and optional answers (Phase
    // 15 P8.4b). `calendar` is declared before any `{assignment}`
    // sibling so the literal never binds as an id (the P3.3 lesson);
    // the resolved view authorizes per employee, with self-service.
    Route::get('hrms/holidays/assignments', [HolidayAssignmentController::class, 'index']);
    Route::post('hrms/holidays/assignments', [HolidayAssignmentController::class, 'store']);
    Route::get('hrms/holidays/calendar', [HolidayAssignmentController::class, 'resolved']);
    Route::delete('hrms/holidays/assignments/{assignment}', [HolidayAssignmentController::class, 'destroy']);

    Route::get('hrms/holidays/optional', [HolidayOptionalController::class, 'index']);
    Route::post('hrms/holidays/optional', [HolidayOptionalController::class, 'store']);
});
