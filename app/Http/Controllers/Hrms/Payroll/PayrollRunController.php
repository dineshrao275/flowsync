<?php

namespace App\Http\Controllers\Hrms\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\PayrollRunRequest;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Services\Hrms\Payroll\PayrollLifecycle;
use App\Services\Hrms\Payroll\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Payroll/HRMS — the run lifecycle surface.
 *
 * Thin: it authorizes every step against the run policy (one permission
 * answers all of them — a run in review shows every payslip, so anyone who
 * may see the grid may move it), hands the transition to the service or the
 * lifecycle, and shapes the envelope. The state rules live there, not here.
 */
class PayrollRunController extends Controller
{
    public function __construct(
        private readonly PayrollService $runs,
        private readonly PayrollLifecycle $lifecycle,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        return response()->json([
            'runs' => PayrollRun::query()->orderByDesc('period_year')->orderByDesc('period_month')
                ->get()->map(fn (PayrollRun $run): array => $this->present($run))->all(),
        ]);
    }

    public function show(PayrollRun $run): JsonResponse
    {
        $this->authorize('view', $run);

        return response()->json(['run' => $this->present($run)]);
    }

    public function store(PayrollRunRequest $request): JsonResponse
    {
        $this->authorize('create', PayrollRun::class);

        $run = $this->runs->openRun($request->validated(), $request->user());

        return response()->json([
            'message' => 'Payroll run opened.',
            'run' => $this->present($run),
        ], Response::HTTP_CREATED);
    }

    public function calculate(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorize('calculate', $run);

        $summary = $this->runs->calculate($run, $request->user());

        return response()->json([
            'message' => 'Payroll run calculated.',
            'run' => $this->present($run->refresh()),
            'summary' => $summary,
        ]);
    }

    public function approve(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorize('approve', $run);

        return response()->json([
            'message' => 'Payroll run approved.',
            'run' => $this->present($this->lifecycle->approve($run, $request->user())),
        ]);
    }

    public function publish(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorize('publish', $run);

        return response()->json([
            'message' => 'Payslips published.',
            'run' => $this->present($this->lifecycle->publish($run, $request->user())),
        ]);
    }

    public function markPaid(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorize('markPaid', $run);

        return response()->json([
            'message' => 'Payroll run marked paid.',
            'run' => $this->present($this->lifecycle->markPaid($run, $request->user())),
        ]);
    }

    public function lock(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorize('lock', $run);

        return response()->json([
            'message' => 'Payroll run locked.',
            'run' => $this->present($this->lifecycle->lock($run, $request->user())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'period_year' => $run->period_year,
            'period_month' => $run->period_month,
            'pay_period_start' => $run->pay_period_start->toDateString(),
            'pay_period_end' => $run->pay_period_end->toDateString(),
            'pay_date' => $run->pay_date->toDateString(),
            'status' => $run->status->value,
            'employee_count' => $run->employee_count,
            'totals' => $run->totals ?? [],
            'approved_at' => $run->approved_at?->toIso8601String(),
            'processed_at' => $run->processed_at?->toIso8601String(),
            'locked_at' => $run->locked_at?->toIso8601String(),
            'notes' => $run->notes,
        ];
    }
}
