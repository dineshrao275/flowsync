<?php

namespace App\Http\Controllers\Hrms\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Leave\LeaveAccrueRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeaveType;
use App\Services\Hrms\Leave\LeaveBalanceReading;
use App\Services\Hrms\Leave\LeaveBalanceService;
use App\Services\Hrms\Leave\LeavePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Leave/HRMS — balances and accrual runs over HTTP.
 *
 * Reads are policy-gated per employee (self-service included); the accrual
 * run takes `hrms.leave.manage` at the route and delegates the whole bulk —
 * scope, idempotent loop, audit row — to one service call.
 */
class LeaveBalanceController extends Controller
{
    public function __construct(
        private readonly LeaveBalanceService $balances,
        private readonly LeaveBalanceReading $reading,
        private readonly LeavePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $employee = $this->employee($request, $filters['employee_id'] ?? null);

        return response()->json([
            'employee' => ['id' => $employee->id, 'name' => $employee->name, 'employee_code' => $employee->employee_code],
            'balances' => $this->reading->balancesFor($employee, $filters['year'] ?? null)
                ->map(fn (LeaveBalance $balance): array => $this->presenter->balance($balance))
                ->all(),
        ]);
    }

    public function accrue(LeaveAccrueRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->balances->accrueFor(
            LeaveType::findOrFail((int) $validated['leave_type_id']),
            isset($validated['employee_id']) ? Employee::findOrFail((int) $validated['employee_id']) : null,
            isset($validated['year']) ? (int) $validated['year'] : null,
            isset($validated['as_of']) ? Carbon::parse((string) $validated['as_of']) : null,
            $request->user(),
        );

        return response()->json([
            'message' => "Accrual run finished: {$result['credited']} credited, {$result['skipped']} skipped.",
            ...$result,
        ], Response::HTTP_CREATED);
    }

    /**
     * The named employee, or the caller's own record.
     *
     * A login with no employment record gets a 404 naming the missing
     * record, not a 500 inside the readers (the P5.3 punch lesson).
     */
    private function employee(Request $request, ?int $employeeId): Employee
    {
        $employee = $employeeId === null
            ? Employee::where('user_id', $request->user()->id)->first()
            : Employee::find($employeeId);

        abort_if($employee === null, 404, 'There is no employment record to read balances for.');

        $this->authorize('view', [LeaveBalance::class, $employee]);

        return $employee;
    }
}
