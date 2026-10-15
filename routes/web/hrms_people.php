<?php

use App\Http\Controllers\Hrms\Attendance\AttendanceController;
use App\Http\Controllers\Hrms\Attendance\AttendanceRecordsController;
use App\Http\Controllers\Hrms\Attendance\RegularizationController;
use App\Http\Controllers\Hrms\DocumentController;
use App\Http\Controllers\Hrms\DocumentTypeController;
use App\Http\Controllers\Hrms\EmployeeController;
use App\Http\Controllers\Hrms\Lifecycle\DocumentRequestController;
use App\Http\Controllers\Hrms\Lifecycle\OffboardingCaseController;
use App\Http\Controllers\Hrms\Lifecycle\OnboardingCaseController;
use App\Http\Controllers\Hrms\Lifecycle\OnboardingTemplateController;
use App\Http\Controllers\Hrms\MyTeamController;
use App\Http\Controllers\Hrms\Org\DepartmentController;
use App\Http\Controllers\Hrms\Org\DesignationController;
use App\Http\Controllers\Hrms\Org\LocationController;
use App\Http\Controllers\Hrms\Org\OrgController;
use Illuminate\Support\Facades\Route;

// HRMS people: org structure, employees, documents, onboarding/offboarding, attendance settings.
// Required from routes/web.php inside the same middleware group the block lived in,
// at the same position, so route order (and `route:list`) is unchanged.

// HRMS org structure (Phase 15 P3.3). The same two gates as the employee
// surface: `hrms.core` decides whether the module exists for this tenant
// and `hrms.view` whether it can be reached, and the org policies then
// split reads (`hrms.org.view`) from writes (`hrms.org.manage`) per record.
//
// Each `reorder` is declared before its own `{resource}` sibling, because
// otherwise "reorder" binds as a department/designation/location id and
// comes back as a 404 on a perfectly valid drag-and-drop.
Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
    Route::get('hrms/org', [OrgController::class, 'index']);

    Route::get('hrms/departments', [DepartmentController::class, 'index']);
    Route::post('hrms/departments', [DepartmentController::class, 'store']);
    Route::post('hrms/departments/reorder', [DepartmentController::class, 'reorder']);
    Route::get('hrms/departments/{department}', [DepartmentController::class, 'show']);
    Route::put('hrms/departments/{department}', [DepartmentController::class, 'update']);
    Route::delete('hrms/departments/{department}', [DepartmentController::class, 'destroy']);
    Route::post('hrms/departments/{department}/deactivate', [DepartmentController::class, 'deactivate']);

    Route::get('hrms/designations', [DesignationController::class, 'index']);
    Route::post('hrms/designations', [DesignationController::class, 'store']);
    Route::post('hrms/designations/reorder', [DesignationController::class, 'reorder']);
    Route::get('hrms/designations/{designation}', [DesignationController::class, 'show']);
    Route::put('hrms/designations/{designation}', [DesignationController::class, 'update']);
    Route::delete('hrms/designations/{designation}', [DesignationController::class, 'destroy']);
    Route::post('hrms/designations/{designation}/deactivate', [DesignationController::class, 'deactivate']);

    Route::get('hrms/locations', [LocationController::class, 'index']);
    Route::post('hrms/locations', [LocationController::class, 'store']);
    Route::post('hrms/locations/reorder', [LocationController::class, 'reorder']);
    Route::get('hrms/locations/{location}', [LocationController::class, 'show']);
    Route::put('hrms/locations/{location}', [LocationController::class, 'update']);
    Route::delete('hrms/locations/{location}', [LocationController::class, 'destroy']);
    Route::post('hrms/locations/{location}/deactivate', [LocationController::class, 'deactivate']);
});

// HRMS employee records (Phase 15 P2.3). The module gate and `hrms.view`
// decide whether the surface exists for this tenant at all;
// EmployeePolicy decides what the caller may do with each record inside it
// — including reading their own, which no tenant-level permission grants.
Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
    Route::get('hrms/employees', [EmployeeController::class, 'index']);
    Route::post('hrms/employees', [EmployeeController::class, 'store']);
    Route::get('hrms/employees/{employee}', [EmployeeController::class, 'show']);
    Route::get('hrms/employees/{employee}/summary', [MyTeamController::class, 'summary']);
    Route::put('hrms/employees/{employee}', [EmployeeController::class, 'update']);
    Route::delete('hrms/employees/{employee}', [EmployeeController::class, 'destroy']);
    Route::post('hrms/employees/{employee}/status', [EmployeeController::class, 'changeStatus']);
    Route::post('hrms/employees/{employee}/manager', [EmployeeController::class, 'assignManager']);
    Route::post('hrms/employees/{employee}/terminate', [EmployeeController::class, 'terminate']);
});

// HRMS employee documents (Phase 15 P13.3). Same two gates as the
// employee surface; EmployeeDocumentPolicy decides per record — including
// reading and filing one’s own, which no tenant permission grants.
//
// `expiring` is declared before `{document}`, because otherwise the word
// binds as a document id and a valid warning query 404s.
Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.documents', 'permission:hrms.view']], function () {
    Route::get('hrms/documents/types', [DocumentTypeController::class, 'index']);
    Route::get('hrms/documents/types/{type}', [DocumentTypeController::class, 'show']);

    Route::get('hrms/documents/expiring', [DocumentController::class, 'expiring']);
    Route::get('hrms/documents', [DocumentController::class, 'index']);
    Route::post('hrms/documents', [DocumentController::class, 'store']);
    Route::get('hrms/documents/{document}', [DocumentController::class, 'show']);
    Route::delete('hrms/documents/{document}', [DocumentController::class, 'destroy']);
    Route::post('hrms/documents/{document}/verify', [DocumentController::class, 'verify']);
    Route::post('hrms/documents/{document}/reject', [DocumentController::class, 'reject']);
    Route::get('hrms/my/documents', [DocumentController::class, 'mine']);
});

// HRMS onboarding & offboarding (Phase 15 P4.3). Same two gates as the
// rest of the HRMS surface; the lifecycle policies decide per record —
// including a hire reading their own checklist, which no tenant
// permission grants.
//
// Every nested route declares both models (`cases/{case}/tasks/{task}`),
// and the controller verifies the task belongs to the case: a task id
// from another run 404s here rather than completing the wrong checklist.
Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.onboarding', 'permission:hrms.view']], function () {
    Route::get('hrms/onboarding/templates', [OnboardingTemplateController::class, 'index']);
    Route::post('hrms/onboarding/templates', [OnboardingTemplateController::class, 'store']);
    Route::get('hrms/onboarding/templates/{template}', [OnboardingTemplateController::class, 'show']);
    Route::put('hrms/onboarding/templates/{template}', [OnboardingTemplateController::class, 'update']);
    Route::delete('hrms/onboarding/templates/{template}', [OnboardingTemplateController::class, 'destroy']);
    Route::post('hrms/onboarding/templates/{template}/tasks', [OnboardingTemplateController::class, 'storeTask']);
    Route::put('hrms/onboarding/templates/{template}/tasks/{task}', [OnboardingTemplateController::class, 'updateTask']);
    Route::delete('hrms/onboarding/templates/{template}/tasks/{task}', [OnboardingTemplateController::class, 'destroyTask']);
    Route::post('hrms/onboarding/templates/{template}/tasks/reorder', [OnboardingTemplateController::class, 'reorderTasks']);

    Route::get('hrms/onboarding/cases', [OnboardingCaseController::class, 'index']);
    Route::post('hrms/onboarding/cases', [OnboardingCaseController::class, 'store']);
    Route::get('hrms/onboarding/cases/{case}', [OnboardingCaseController::class, 'show']);
    Route::post('hrms/onboarding/cases/{case}/tasks/{task}/complete', [OnboardingCaseController::class, 'completeTask']);
    Route::post('hrms/onboarding/cases/{case}/tasks/{task}/waive', [OnboardingCaseController::class, 'waiveTask']);
    Route::post('hrms/onboarding/cases/{case}/tasks/{task}/convert', [OnboardingCaseController::class, 'convertTask']);
    Route::post('hrms/onboarding/cases/{case}/tasks/{task}/sync', [OnboardingCaseController::class, 'syncTask']);
    Route::post('hrms/onboarding/cases/{case}/complete', [OnboardingCaseController::class, 'complete']);
    Route::post('hrms/onboarding/cases/{case}/cancel', [OnboardingCaseController::class, 'cancel']);

});

Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.offboarding', 'permission:hrms.view']], function () {
    Route::get('hrms/offboarding/cases', [OffboardingCaseController::class, 'index']);
    Route::post('hrms/offboarding/cases', [OffboardingCaseController::class, 'store']);
    Route::get('hrms/offboarding/cases/{case}', [OffboardingCaseController::class, 'show']);
    Route::post('hrms/offboarding/cases/{case}/tasks/{task}/complete', [OffboardingCaseController::class, 'completeTask']);
    Route::post('hrms/offboarding/cases/{case}/tasks/{task}/convert', [OffboardingCaseController::class, 'convertTask']);
    Route::post('hrms/offboarding/cases/{case}/tasks/{task}/sync', [OffboardingCaseController::class, 'syncTask']);
    Route::post('hrms/offboarding/cases/{case}/clear', [OffboardingCaseController::class, 'clear']);
    Route::post('hrms/offboarding/cases/{case}/complete', [OffboardingCaseController::class, 'complete']);
    Route::post('hrms/offboarding/cases/{case}/cancel', [OffboardingCaseController::class, 'cancel']);

});

// Document asks are raised by both lifecycle flows and fulfilled through the
// document store, so they ride the documents module.
Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.documents', 'permission:hrms.view']], function () {
    Route::get('hrms/document-requests', [DocumentRequestController::class, 'index']);
    Route::post('hrms/document-requests', [DocumentRequestController::class, 'store']);
    Route::get('hrms/document-requests/{documentRequest}', [DocumentRequestController::class, 'show']);
    Route::post('hrms/document-requests/{documentRequest}/submit', [DocumentRequestController::class, 'submit']);
    Route::post('hrms/document-requests/{documentRequest}/accept', [DocumentRequestController::class, 'accept']);
    Route::post('hrms/document-requests/{documentRequest}/waive', [DocumentRequestController::class, 'waive']);
    Route::post('hrms/document-requests/{documentRequest}/reject', [DocumentRequestController::class, 'reject']);
});

// HRMS attendance settings (Phase 15 P5.3). The module gate decides
// whether attendance exists for this tenant; `hrms.attendance.settings`
// decides who may rewrite its policy. Partial sections merge — a PUT
// that blanked unmentioned sections would be a reset disguised as edit.
Route::group(['middleware' => ['ensure_module:hrms.attendance']], function () {
    Route::get('hrms/attendance/settings', [AttendanceController::class, 'getSettings'])
        ->middleware('permission:hrms.attendance.settings');
    Route::put('hrms/attendance/settings', [AttendanceController::class, 'settings'])
        ->middleware('permission:hrms.attendance.settings');

    // HRMS regularization (Phase 15 P5.4). Deliberately NO route-level
    // permission, like the punch endpoint: requesting and reading one's
    // own asks is self-service, and deciding belongs to the approval
    // step's approver — a manager who may hold no attendance permission
    // at all. The policy answers both from the record and the chain.
    Route::get('hrms/attendance/regularizations', [RegularizationController::class, 'index']);
    Route::post('hrms/attendance/regularizations', [RegularizationController::class, 'store']);
    Route::get('hrms/attendance/regularizations/{regularization}', [RegularizationController::class, 'show']);
    Route::post('hrms/attendance/regularizations/{regularization}/approve', [RegularizationController::class, 'approve']);
    Route::post('hrms/attendance/regularizations/{regularization}/reject', [RegularizationController::class, 'reject']);

    // HRMS attendance reads (Phase 15 P5.6a). Same gate shape as the
    // regularizations above: module-gated, policy-authorized per
    // employee (self-service included via AttendanceDayPolicy::view).
    // Declared before any `{record}` sibling so a literal segment can
    // never bind as a model id (the P3.3 `reorder` lesson).
    Route::get('hrms/attendance/month', [AttendanceRecordsController::class, 'month']);
    Route::get('hrms/attendance/today', [AttendanceRecordsController::class, 'today']);
    // Exports are the heaviest reads on this surface (up to 93 days of
    // rows per pull), so the one bulk endpoint rides the throttle while
    // the month grid and widget stay unthrottled — and unlike those
    // self-service reads, exports answer to tenant admins only.
    Route::get('hrms/attendance/export', [AttendanceRecordsController::class, 'export'])->middleware(['throttle:30,1', 'permission:workspaces.manage']);
});
