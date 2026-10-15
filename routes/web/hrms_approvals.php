<?php

use App\Http\Controllers\Hrms\Approval\ApprovalChainController;
use App\Http\Controllers\Hrms\Approval\ApprovalDelegationController;
use Illuminate\Support\Facades\Route;

// HRMS approvals v2 (P2.4): tenant-editable approval chains and delegation.
// Required from routes/web.php inside the HRMS tenant stack. Chains answer to
// `hrms.approvals.manage` (policy); delegation is self-service with the same
// permission widening it to other people's seats.
Route::group(['middleware' => ['ensure_module:hrms.core', 'permission:hrms.view']], function () {
    Route::get('hrms/approvals/chains', [ApprovalChainController::class, 'index']);
    Route::put('hrms/approvals/chains/{domain}', [ApprovalChainController::class, 'update']);
    Route::post('hrms/approvals/chains/{domain}/reset', [ApprovalChainController::class, 'reset']);

    Route::get('hrms/approvals/delegation-targets', [ApprovalDelegationController::class, 'targets']);
    Route::get('hrms/approvals/delegations', [ApprovalDelegationController::class, 'index']);
    Route::post('hrms/approvals/delegations', [ApprovalDelegationController::class, 'store']);
    Route::delete('hrms/approvals/delegations/{delegation}', [ApprovalDelegationController::class, 'destroy']);
});
