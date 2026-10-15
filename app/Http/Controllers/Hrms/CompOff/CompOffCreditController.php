<?php

namespace App\Http\Controllers\Hrms\CompOff;

use App\Enums\Hrms\CompOffSource;
use App\Http\Controllers\Concerns\NormalizesFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\CompOff\CompOffCreditRequest;
use App\Models\Hrms\CompOff\CompOffCredit;
use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\CompOff\CompOffCreditDirectory;
use App\Services\Hrms\CompOff\CompOffCredits;
use App\Services\Hrms\CompOff\CompOffPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * CompOff/HRMS — banked time over HTTP.
 *
 * Reads are policy-gated per employee (self-service included); manual
 * grants take `hrms.comp_off.manage` at the route, because nobody banks
 * their own time. Balances ride along so the bank and its total arrive in
 * one round trip.
 */
class CompOffCreditController extends Controller
{
    use NormalizesFilters;

    public function __construct(
        private readonly CompOffCredits $credits,
        private readonly CompOffCreditDirectory $directory,
        private readonly CompOffPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
        ]);

        $employee = $this->employee($request, $filters['employee_id'] ?? null);

        $this->authorize('view', [CompOffCredit::class, $employee]);

        return response()->json([
            'employee' => ['id' => $employee->id, 'name' => $employee->name, 'employee_code' => $employee->employee_code],
            'credits' => $this->directory->listFor($employee, $this->clean($filters))
                ->map(fn (CompOffCredit $credit): array => $this->presenter->credit($credit))
                ->all(),
            'balance_minutes' => $this->credits->balance($employee),
            'expired_minutes' => $this->credits->expiredBalance($employee),
            'expiring_minutes' => $this->credits->expiringBalance($employee),
        ]);
    }

    public function store(CompOffCreditRequest $request): JsonResponse
    {
        $this->authorize('create', CompOffCredit::class);

        $validated = $request->validated();

        $credit = $this->credits->creditManual(
            Employee::findOrFail((int) $validated['employee_id']),
            (string) $validated['work_date'],
            (int) $validated['minutes'],
            CompOffSource::from((string) ($validated['source'] ?? 'manual')),
            $validated['note'] ?? null,
            $request->user(),
        );

        return response()->json([
            'message' => 'Comp-off credited.',
            'credit' => $this->presenter->credit($credit),
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

        abort_if($employee === null, 404, 'There is no employment record to read comp-off for.');

        return $employee;
    }
}
