<?php

use App\Http\Controllers\Hrms\Asset\AssetCategoryController;
use App\Http\Controllers\Hrms\Asset\AssetController;
use App\Http\Controllers\Hrms\Attendance\AttendanceController;
use App\Http\Controllers\Hrms\AuditController;
use App\Http\Controllers\Hrms\Expense\ExpenseCategoryController;
use App\Http\Controllers\Hrms\Expense\ExpenseClaimController;
use App\Http\Controllers\Hrms\HrmsAnalyticsController;
use App\Http\Controllers\Hrms\HrmsAnalyticsExportController;
use App\Http\Controllers\Hrms\Performance\CheckInController;
use App\Http\Controllers\Hrms\Performance\FeedbackRequestController;
use App\Http\Controllers\Hrms\Performance\OneOnOneController;
use App\Http\Controllers\Hrms\Performance\PerformanceCycleController;
use App\Http\Controllers\Hrms\Performance\PerformanceGoalController;
use App\Http\Controllers\Hrms\Performance\ReviewSummaryController;
use App\Http\Controllers\Hrms\Survey\SurveyCampaignController;
use App\Http\Controllers\Hrms\Survey\SurveyTemplateController;
use App\Http\Controllers\Hrms\TaskLinkController;
use Illuminate\Support\Facades\Route;

// HRMS talent and ops: expenses, surveys, analytics, audit, task links, assets, performance, remote punch.
// Required from routes/web.php inside the same middleware group the block lived in,
// at the same position, so route order (and `route:list`) is unchanged.

// HRMS expenses (Phase 15 P11.3a). Same two gates plus the expenses
// module; the claim policy splits listing/reading (self-or-view),
// filing (self-or-manage) and deciding (approve-alone), and the
// category policy is master-data shaped (view vs manage).
Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.expenses', 'permission:hrms.view']], function () {
    Route::get('hrms/expenses/categories', [ExpenseCategoryController::class, 'index']);
    Route::post('hrms/expenses/categories', [ExpenseCategoryController::class, 'store']);
    Route::get('hrms/expenses/categories/{category}', [ExpenseCategoryController::class, 'show']);
    Route::put('hrms/expenses/categories/{category}', [ExpenseCategoryController::class, 'update']);
    Route::delete('hrms/expenses/categories/{category}', [ExpenseCategoryController::class, 'destroy']);

    Route::get('hrms/expenses/claims', [ExpenseClaimController::class, 'index']);
    Route::post('hrms/expenses/claims', [ExpenseClaimController::class, 'store']);
    Route::get('hrms/expenses/claims/{claim}', [ExpenseClaimController::class, 'show']);
    Route::put('hrms/expenses/claims/{claim}/items', [ExpenseClaimController::class, 'setItems']);
    Route::post('hrms/expenses/claims/{claim}/submit', [ExpenseClaimController::class, 'submit']);
    Route::post('hrms/expenses/claims/{claim}/decide', [ExpenseClaimController::class, 'decide']);
});

// HRMS engagement surveys (Phase 15 P16.3a). Same two gates plus the
// engagement module; templates answer to manage, campaigns to view or
// invitation, answers to invitation alone. The self-service pair
// (`my`) carries no answers and no aggregates — the blank form lives
// there, the scored results behind the view gate.
Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.engagement', 'permission:hrms.view']], function () {
    Route::get('hrms/engagement/templates', [SurveyTemplateController::class, 'index']);
    Route::post('hrms/engagement/templates', [SurveyTemplateController::class, 'store']);
    Route::get('hrms/engagement/templates/{template}', [SurveyTemplateController::class, 'show']);
    Route::put('hrms/engagement/templates/{template}', [SurveyTemplateController::class, 'update']);
    Route::delete('hrms/engagement/templates/{template}', [SurveyTemplateController::class, 'destroy']);
    Route::put('hrms/engagement/templates/{template}/questions', [SurveyTemplateController::class, 'setQuestions']);

    Route::get('hrms/engagement/campaigns', [SurveyCampaignController::class, 'index']);
    Route::post('hrms/engagement/campaigns', [SurveyCampaignController::class, 'store']);
    Route::get('hrms/engagement/campaigns/{campaign}', [SurveyCampaignController::class, 'show']);
    Route::post('hrms/engagement/campaigns/{campaign}/open', [SurveyCampaignController::class, 'open']);
    Route::post('hrms/engagement/campaigns/{campaign}/close', [SurveyCampaignController::class, 'close']);
    Route::post('hrms/engagement/campaigns/{campaign}/invite', [SurveyCampaignController::class, 'invite']);
    Route::get('hrms/engagement/campaigns/{campaign}/results', [SurveyCampaignController::class, 'results']);
    // One answer per invitee per campaign by design, but the endpoint
    // still writes — same thirty-a-minute ceiling as the other writers.
    Route::post('hrms/engagement/campaigns/{campaign}/respond', [SurveyCampaignController::class, 'respond'])->middleware('throttle:30,1');

    Route::get('hrms/engagement/my', [SurveyCampaignController::class, 'mine']);
    Route::get('hrms/engagement/my/{campaign}', [SurveyCampaignController::class, 'mySurvey']);
});

// HRMS workforce analytics (Phase 18 P18.3). Same two gates plus the
// analytics module; every domain route carries its own permission —
// never a blanket `workspaces.view` (the Phase 7 precedent) — and the
// tiers inside shared endpoints (ratings, liability) read the caller's
// permissions in the controller.
Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.analytics', 'permission:hrms.view']], function () {
    // CSV takes the domain as a parameter, so no static route can carry
    // its gate — the controller authorizes each domain against the same
    // permission slug as the tab route below.
    Route::get('hrms/analytics/export', [HrmsAnalyticsExportController::class, 'export'])->middleware('throttle:30,1');
    Route::get('hrms/analytics/overview', [HrmsAnalyticsController::class, 'overview'])->middleware('permission:hrms.analytics.view');
    Route::get('hrms/analytics/attendance', [HrmsAnalyticsController::class, 'attendance'])->middleware('permission:hrms.attendance.view');
    Route::get('hrms/analytics/leave', [HrmsAnalyticsController::class, 'leave'])->middleware('permission:hrms.leave.manage');
    Route::get('hrms/analytics/lifecycle', [HrmsAnalyticsController::class, 'lifecycle'])->middleware('permission:hrms.analytics.view');
    Route::get('hrms/analytics/performance', [HrmsAnalyticsController::class, 'performance'])->middleware('permission:hrms.analytics.view');
    Route::get('hrms/analytics/payroll', [HrmsAnalyticsController::class, 'payroll'])->middleware('permission:hrms.payroll.run');
    Route::get('hrms/analytics/documents', [HrmsAnalyticsController::class, 'documents'])->middleware('permission:hrms.documents.view');
    Route::get('hrms/analytics/assets', [HrmsAnalyticsController::class, 'assets'])->middleware('permission:hrms.assets.view');
});

// HRMS audit trail (Phase 19 P19.1). The ledger is cross-cutting, so no
// per-record policy can answer for it — `hrms.audit.view` is the whole
// gate, on both the filtered index and the single-record trail. The
// `{subjectType}` is the stored morph class; `{subjectId}` is numeric
// so a mistyped type still 404s instead of querying garbage.
Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
    Route::get('hrms/audit', [AuditController::class, 'index'])->middleware('permission:hrms.audit.view');
    Route::get('hrms/audit/data-access', [AuditController::class, 'dataAccess'])->middleware('permission:hrms.audit.view');
    Route::get('hrms/audit/{subjectType}/{subjectId}', [AuditController::class, 'trail'])
        ->middleware('permission:hrms.audit.view')
        ->whereNumber('subjectId');
});

// HRMS task links (Phase 20 P20.2). The bridge rows answer to the
// project policy on the task side (view to read, edit to file or
// cut) and the employee policy on the person side; the employee's
// task list additionally intersects with the caller's visible
// tasks, so a link never widens what the login may see.
Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
    Route::get('hrms/tasks/{task}/links', [TaskLinkController::class, 'index']);
    Route::post('hrms/tasks/{task}/links', [TaskLinkController::class, 'store']);
    Route::delete('hrms/tasks/{task}/links/{link}', [TaskLinkController::class, 'destroy']);
    Route::get('hrms/employees/{employee}/tasks', [TaskLinkController::class, 'employeeTasks']);
});

// HRMS assets (Phase 15 P14.3a). Same two gates plus the assets module;
// the asset policy splits reads (view), movements (manage) and receipts
// (the holder alone), and the category policy is master-data shaped.
// The invoice download sits outside like every signed file route: the
// signature is the credential, so `asset` is an int resolved inside the
// tenant connection.
Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.assets', 'permission:hrms.view']], function () {
    Route::get('hrms/assets/categories', [AssetCategoryController::class, 'index']);
    Route::post('hrms/assets/categories', [AssetCategoryController::class, 'store']);
    Route::get('hrms/assets/categories/{category}', [AssetCategoryController::class, 'show']);
    Route::put('hrms/assets/categories/{category}', [AssetCategoryController::class, 'update']);
    Route::delete('hrms/assets/categories/{category}', [AssetCategoryController::class, 'destroy']);

    Route::get('hrms/assets', [AssetController::class, 'index']);
    Route::post('hrms/assets', [AssetController::class, 'store']);
    Route::get('hrms/assets/{asset}', [AssetController::class, 'show']);
    Route::post('hrms/assets/{asset}/assign', [AssetController::class, 'assign']);
    Route::post('hrms/assets/{asset}/return', [AssetController::class, 'returnAsset']);
    Route::post('hrms/assets/{asset}/maintenance', [AssetController::class, 'maintenance']);
    Route::post('hrms/assets/assignments/{assignment}/acknowledge', [AssetController::class, 'acknowledge']);
    Route::get('hrms/my/assets', [AssetController::class, 'mine']);
});

// NOTE: `hrms/assets/{asset}/document` is NOT declared here even though
// it reads like an asset route: it is a signed session-free download
// (the signature is the credential, like every signed file route), so
// it lives with the other signed downloads below, outside all
// auth/tenant groups. Declaring it here would stack the group's `auth`
// on top and 401 every fresh-tab download.

// HRMS performance, first half (Phase 15 P12.4a): cycles, goals,
// check-ins and 1:1s. Same two gates plus the performance module; the
// four policies split reads (self, manager, or view) from moves
// (manage, or self-while-draft for goals). Feedback, reviews and the
// evidence read land in P12.4b.
Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.performance', 'permission:hrms.view']], function () {
    Route::get('hrms/performance/cycles', [PerformanceCycleController::class, 'index']);
    Route::post('hrms/performance/cycles', [PerformanceCycleController::class, 'store']);
    Route::get('hrms/performance/cycles/{cycle}', [PerformanceCycleController::class, 'show']);
    Route::post('hrms/performance/cycles/{cycle}/open-check-in', [PerformanceCycleController::class, 'openCheckIn']);
    Route::post('hrms/performance/cycles/{cycle}/open-self-review', [PerformanceCycleController::class, 'openSelfReview']);
    Route::post('hrms/performance/cycles/{cycle}/open-manager-review', [PerformanceCycleController::class, 'openManagerReview']);
    Route::post('hrms/performance/cycles/{cycle}/open-calibration', [PerformanceCycleController::class, 'openCalibration']);
    Route::post('hrms/performance/cycles/{cycle}/complete', [PerformanceCycleController::class, 'complete']);

    Route::get('hrms/performance/cycles/{cycle}/goals', [PerformanceGoalController::class, 'index']);
    Route::post('hrms/performance/cycles/{cycle}/goals', [PerformanceGoalController::class, 'store']);
    Route::get('hrms/performance/goals/{goal}', [PerformanceGoalController::class, 'show']);
    Route::put('hrms/performance/goals/{goal}', [PerformanceGoalController::class, 'update']);
    Route::post('hrms/performance/goals/{goal}/refresh', [PerformanceGoalController::class, 'refresh']);
    Route::post('hrms/performance/goals/{goal}/tasks', [PerformanceGoalController::class, 'linkTask']);
    Route::delete('hrms/performance/goals/{goal}/tasks/{task}', [PerformanceGoalController::class, 'unlinkTask']);

    Route::get('hrms/performance/cycles/{cycle}/check-ins', [CheckInController::class, 'index']);
    Route::post('hrms/performance/cycles/{cycle}/check-ins', [CheckInController::class, 'store']);

    // HRMS feedback, reviews and evidence (Phase 15 P12.4b). Same
    // gates; the feedback policy names who may answer (the reviewer
    // alone), the review policy splits readers by visibility, and the
    // evidence read never recomputes — the refresh endpoint does.
    Route::get('hrms/performance/cycles/{cycle}/feedback-requests', [FeedbackRequestController::class, 'index']);
    Route::get('hrms/performance/feedback-requests/{feedbackRequest}', [FeedbackRequestController::class, 'show']);
    Route::post('hrms/performance/feedback-requests/{feedbackRequest}/respond', [FeedbackRequestController::class, 'respond']);

    Route::get('hrms/performance/cycles/{cycle}/reviews', [ReviewSummaryController::class, 'index']);
    Route::post('hrms/performance/cycles/{cycle}/reviews', [ReviewSummaryController::class, 'store']);
    Route::get('hrms/performance/reviews/{review}', [ReviewSummaryController::class, 'show']);
    Route::put('hrms/performance/reviews/{review}', [ReviewSummaryController::class, 'update']);
    Route::post('hrms/performance/reviews/{review}/acknowledge', [ReviewSummaryController::class, 'acknowledge']);

    Route::get('hrms/performance/cycles/{cycle}/evidence', [PerformanceCycleController::class, 'evidence']);
    Route::post('hrms/performance/cycles/{cycle}/evidence', [PerformanceCycleController::class, 'refreshEvidence']);

    Route::get('hrms/performance/one-on-ones', [OneOnOneController::class, 'index']);
    Route::post('hrms/performance/one-on-ones', [OneOnOneController::class, 'store']);
    Route::get('hrms/performance/one-on-ones/{oneOnOne}', [OneOnOneController::class, 'show']);
    Route::put('hrms/performance/one-on-ones/{oneOnOne}', [OneOnOneController::class, 'update']);
});

// HRMS remote punch (Phase 15 P5.3). Deliberately NO route-level
// permission: the endpoint resolves the employment record from the
// authenticated user, so there is nothing to authorize against and no
// login may punch for another. The module 403 is the upgrade path, not
// a permission 403 — the client shows `/403` for the former.
Route::group(['middleware' => ['ensure_module:hrms.attendance.remote']], function () {
    // Punching is self-only and audited, but it still writes rows —
    // thirty a minute is generous for a shift boundary and stops a
    // stuck client from flooding the ledger.
    Route::post('hrms/attendance/punch', [AttendanceController::class, 'punch'])->middleware('throttle:30,1');
});
