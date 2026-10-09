<?php

use App\Http\Controllers\Hrms\Payroll\EmployeeSalaryController;
use App\Http\Controllers\Hrms\Payroll\PayrollRunController;
use App\Http\Controllers\Hrms\Payroll\PayslipAdjustmentController;
use App\Http\Controllers\Hrms\Payroll\PayslipController;
use App\Http\Controllers\Hrms\Payroll\SalaryComponentController;
use App\Http\Controllers\Hrms\Payroll\SalaryStructureController;
use App\Http\Controllers\Hrms\Payroll\StatutoryConfigurationController;
use App\Http\Controllers\Hrms\Payroll\StatutoryDeclarationController;
use App\Http\Controllers\Hrms\Payroll\StatutoryProfileController;
use App\Http\Controllers\Hrms\Payroll\TdsProjectController;
use Illuminate\Support\Facades\Route;

// HRMS pay: payroll reads, compensation writes and statutory.
// Required from routes/web.php inside the same middleware group the block lived in,
// at the same position, so route order (and `route:list`) is unchanged.

// HRMS payroll reads (Phase 15 P9.4). Same two gates as the rest of the
// HRMS surface; PayslipPolicy decides per record — the run grid is a
// runner tool (`hrms.payroll.run`), one payslip is an all-viewer or
// self-with-view read. Every read writes its access rows inside the
// service, and the remaining payroll surface (policies, requests, write
// routes) lands in P9.5.
Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
    Route::get('hrms/payroll/runs/{run}/payslips', [PayslipController::class, 'index']);
    Route::get('hrms/payroll/payslips/{payslip}', [PayslipController::class, 'show']);
});

// HRMS compensation writes (Phase 15 P9.5a). Same two gates; the component
// and structure policies split reads (`hrms.compensation.view`) from
// writes (`hrms.compensation.manage`), and the salary controller answers
// self-service reads itself — pay is sensitive, and the person in the
// record may see their own basis.
Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
    Route::get('hrms/payroll/components', [SalaryComponentController::class, 'index']);
    Route::post('hrms/payroll/components', [SalaryComponentController::class, 'store']);
    Route::get('hrms/payroll/components/{component}', [SalaryComponentController::class, 'show']);
    Route::put('hrms/payroll/components/{component}', [SalaryComponentController::class, 'update']);
    Route::delete('hrms/payroll/components/{component}', [SalaryComponentController::class, 'destroy']);

    Route::get('hrms/payroll/structures', [SalaryStructureController::class, 'index']);
    Route::post('hrms/payroll/structures', [SalaryStructureController::class, 'store']);
    Route::get('hrms/payroll/structures/{structure}', [SalaryStructureController::class, 'show']);
    Route::put('hrms/payroll/structures/{structure}', [SalaryStructureController::class, 'update']);
    Route::delete('hrms/payroll/structures/{structure}', [SalaryStructureController::class, 'destroy']);
    Route::put('hrms/payroll/structures/{structure}/components', [SalaryStructureController::class, 'setComponents']);

    Route::get('hrms/payroll/employees/{employee}/salary', [EmployeeSalaryController::class, 'salary']);
    Route::post('hrms/payroll/employees/{employee}/salary', [EmployeeSalaryController::class, 'assign']);
    Route::get('hrms/payroll/employees/{employee}/revisions', [EmployeeSalaryController::class, 'revisions']);
    Route::post('hrms/payroll/employees/{employee}/revisions', [EmployeeSalaryController::class, 'revise']);
    Route::post('hrms/payroll/employees/{employee}/revisions/{revision}/apply', [EmployeeSalaryController::class, 'applyRevision']);

    // HRMS payroll runs (Phase 15 P9.5b). Same two gates; the run policy
    // answers every step with the one runner permission, and adjustments
    // ride the payslip's `adjust` ability with both models bound.
    Route::get('hrms/payroll/runs', [PayrollRunController::class, 'index']);
    Route::post('hrms/payroll/runs', [PayrollRunController::class, 'store']);
    Route::get('hrms/payroll/runs/{run}', [PayrollRunController::class, 'show']);
    Route::post('hrms/payroll/runs/{run}/calculate', [PayrollRunController::class, 'calculate']);
    Route::post('hrms/payroll/runs/{run}/approve', [PayrollRunController::class, 'approve']);
    Route::post('hrms/payroll/runs/{run}/publish', [PayrollRunController::class, 'publish']);
    Route::post('hrms/payroll/runs/{run}/mark-paid', [PayrollRunController::class, 'markPaid']);
    Route::post('hrms/payroll/runs/{run}/lock', [PayrollRunController::class, 'lock']);

    Route::post('hrms/payroll/payslips/{payslip}/adjustments', [PayslipAdjustmentController::class, 'store']);
    Route::delete('hrms/payroll/payslips/{payslip}/adjustments/{adjustment}', [PayslipAdjustmentController::class, 'destroy']);
    Route::get('hrms/payroll/my-payslips', [PayslipController::class, 'mine']);
});

// HRMS statutory identifiers (Phase 15 P10.5). The whole group sits
// behind the statutory module gate — jurisdictions a tenant never
// enabled have no profile surface at all — with the usual `hrms.view`
// surface gate inside it. Reads are self-or-manage, writes and the
// cleartext reveal are manage-alone (see the policy); configurations,
// declarations, projections and the surrender land in P10.6.
Route::group(['middleware' => ['ensure_module:hrms.core', 'ensure_module:hrms.payroll.statutory', 'permission:hrms.view']], function () {
    Route::get('hrms/payroll/statutory/profiles/{employee}', [StatutoryProfileController::class, 'show']);
    Route::put('hrms/payroll/statutory/profiles/{employee}', [StatutoryProfileController::class, 'update']);
    Route::post('hrms/payroll/statutory/profiles/{employee}/reveal', [StatutoryProfileController::class, 'reveal']);

    // HRMS statutory rulebooks and exemption claims (Phase 15 P10.6a).
    // Same gates; the configuration policy answers everything with
    // manage, the declaration policy splits filing (self-or-manage)
    // from deciding (manage-alone).
    Route::get('hrms/payroll/statutory/configurations', [StatutoryConfigurationController::class, 'index']);
    Route::post('hrms/payroll/statutory/configurations', [StatutoryConfigurationController::class, 'store']);
    Route::get('hrms/payroll/statutory/configurations/{configuration}', [StatutoryConfigurationController::class, 'show']);
    Route::put('hrms/payroll/statutory/configurations/{configuration}', [StatutoryConfigurationController::class, 'update']);
    Route::delete('hrms/payroll/statutory/configurations/{configuration}', [StatutoryConfigurationController::class, 'destroy']);

    Route::get('hrms/payroll/statutory/declarations', [StatutoryDeclarationController::class, 'index']);
    Route::post('hrms/payroll/statutory/declarations', [StatutoryDeclarationController::class, 'store']);
    Route::get('hrms/payroll/statutory/declarations/{declaration}', [StatutoryDeclarationController::class, 'show']);
    Route::post('hrms/payroll/statutory/declarations/{declaration}/submit', [StatutoryDeclarationController::class, 'submit']);
    Route::post('hrms/payroll/statutory/declarations/{declaration}/verify', [StatutoryDeclarationController::class, 'verify']);
    Route::post('hrms/payroll/statutory/declarations/{declaration}/reject', [StatutoryDeclarationController::class, 'reject']);

    // HRMS TDS projections and the run recompute twin (Phase 15
    // P10.6b). Same gates; the project policy splits reading (self or
    // manage) from moving (manage alone), and the recompute answers to
    // the run's own calculate ability.
    Route::get('hrms/payroll/statutory/tds-projects', [TdsProjectController::class, 'index']);
    Route::post('hrms/payroll/statutory/tds-projects', [TdsProjectController::class, 'project']);
    Route::get('hrms/payroll/statutory/tds-projects/{project}', [TdsProjectController::class, 'show']);
    Route::post('hrms/payroll/statutory/tds-projects/{project}/surrender', [TdsProjectController::class, 'surrender']);
    Route::post('hrms/payroll/statutory/recompute', [TdsProjectController::class, 'recompute']);
});
