<?php

namespace App\Http\Controllers\Hrms\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\Payslip;
use App\Services\Hrms\Payroll\PayslipReading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payroll/HRMS — the authenticated payslip read surface.
 *
 * Thin by design: it authorizes (the run grid is a runner tool, one payslip
 * is an all-viewer or self-with-view read — see the policy), hands the read
 * to {@see PayslipReading}, and shapes the envelope. Every read writes its
 * access rows inside the service, so the controller cannot forget them.
 */
class PayslipController extends Controller
{
    public function __construct(private readonly PayslipReading $reading) {}

    /**
     * The run review grid: every payslip with the run's own header, each row
     * logged as read.
     */
    public function index(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorize('viewAny', Payslip::class);

        return response()->json([
            'run' => [
                'id' => $run->id,
                'period_year' => $run->period_year,
                'period_month' => $run->period_month,
                'status' => $run->status->value,
                'employee_count' => $run->employee_count,
                'totals' => $run->totals ?? [],
            ],
            'payslips' => $this->reading->payslipsFor($request->user(), $run, $request->ip()),
        ]);
    }

    /**
     * One payslip for its reader, logged.
     */
    public function show(Request $request, Payslip $payslip): JsonResponse
    {
        $this->authorize('view', $payslip);

        return response()->json([
            'payslip' => $this->reading->show($request->user(), $payslip, $request->ip()),
        ]);
    }
}
