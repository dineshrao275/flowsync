<?php

namespace App\Http\Controllers\Hrms\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\PayslipAdjustmentRequest;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Payroll\PayslipAdjustment;
use App\Services\Hrms\Payroll\PayrollService;
use App\Services\Hrms\Payroll\PayslipReading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Payroll/HRMS — hand-added lines on a review payslip.
 *
 * A runner tool (`adjust` on the payslip policy): bonuses, recoveries and
 * reimbursements the structure never priced. Both routes declare both
 * models and verify belonging — an adjustment id from another payslip 404s
 * rather than editing the wrong person's pay. The review-only rule and the
 * totals recomputation live in the service.
 */
class PayslipAdjustmentController extends Controller
{
    public function __construct(
        private readonly PayrollService $runs,
        private readonly PayslipReading $reading,
    ) {}

    public function store(PayslipAdjustmentRequest $request, Payslip $payslip): JsonResponse
    {
        $this->authorize('adjust', $payslip);

        $updated = $this->runs->addAdjustment($payslip, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Adjustment added.',
            'payslip' => $this->reading->present($updated, $request->user()),
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, Payslip $payslip, PayslipAdjustment $adjustment): JsonResponse
    {
        $this->authorize('adjust', $payslip);
        abort_if($adjustment->payslip_id !== $payslip->id, 404);

        $updated = $this->runs->removeAdjustment($adjustment, $request->user());

        return response()->json([
            'message' => 'Adjustment removed.',
            'payslip' => $this->reading->present($updated, $request->user()),
        ]);
    }
}
