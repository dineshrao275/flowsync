<?php

namespace App\Http\Controllers\Hrms\CompOff;

use App\Enums\Hrms\CompOffRequestStatus;
use App\Http\Controllers\Concerns\NormalizesFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\CompOff\CompOffDecisionRequest;
use App\Http\Requests\Hrms\CompOff\CompOffRequestRequest;
use App\Models\Hrms\CompOff\CompOffRequest;
use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\CompOff\CompOffPresenter;
use App\Services\Hrms\CompOff\CompOffRequestDirectory;
use App\Services\Hrms\CompOff\CompOffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * CompOff/HRMS — redemption asks over HTTP.
 *
 * Thin by design: resolve whose ask (an explicit id for managers, the
 * caller's own record otherwise), hand the payload to the service, shape
 * the answer. Listing and deciding are policy-gated, never
 * route-permission-gated — filing and reading one's own asks is
 * self-service, and deciding belongs to the step's approver.
 */
class CompOffRequestController extends Controller
{
    use NormalizesFilters;

    public function __construct(
        private readonly CompOffService $compOff,
        private readonly CompOffRequestDirectory $directory,
        private readonly CompOffPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CompOffRequest::class);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(CompOffRequestStatus::class)],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
        ]);

        $rows = $this->directory->listFor($request->user(), $this->clean($filters));

        return response()->json([
            'requests' => $rows->map(fn (CompOffRequest $row): array => $this->presenter->request($row))->all(),
        ]);
    }

    public function store(CompOffRequestRequest $request): JsonResponse
    {
        $employee = $this->targetEmployee($request, $request->validated()['employee_id'] ?? null);

        $this->authorize('create', [CompOffRequest::class, $employee]);

        $row = $this->compOff->request($employee, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Comp-off requested.',
            'request' => $this->presenter->request($row),
        ], Response::HTTP_CREATED);
    }

    public function show(CompOffRequest $compOffRequest): JsonResponse
    {
        $this->authorize('view', $compOffRequest);

        return response()->json([
            'request' => $this->presenter->request($compOffRequest),
        ]);
    }

    public function destroy(Request $request, CompOffRequest $compOffRequest): JsonResponse
    {
        $this->authorize('delete', $compOffRequest);

        $row = $this->compOff->cancelRequest($compOffRequest, $request->user());

        return response()->json([
            'message' => 'Comp-off request withdrawn.',
            'request' => $this->presenter->request($row),
        ]);
    }

    public function cancel(Request $request, CompOffRequest $compOffRequest): JsonResponse
    {
        $this->authorize('delete', $compOffRequest);

        $row = $this->compOff->cancelRequest($compOffRequest, $request->user());

        return response()->json([
            'message' => 'Comp-off request cancelled.',
            'request' => $this->presenter->request($row),
        ]);
    }

    public function approve(CompOffDecisionRequest $request, CompOffRequest $compOffRequest): JsonResponse
    {
        $this->authorize('approve', $compOffRequest);

        $row = $this->compOff->approve($compOffRequest, $request->user(), $request->validated()['note'] ?? null);

        return response()->json([
            'message' => 'Comp-off request approved.',
            'request' => $this->presenter->request($row),
        ]);
    }

    public function reject(CompOffDecisionRequest $request, CompOffRequest $compOffRequest): JsonResponse
    {
        $this->authorize('reject', $compOffRequest);

        $row = $this->compOff->reject($compOffRequest, $request->user(), (string) ($request->validated()['note'] ?? ''));

        return response()->json([
            'message' => 'Comp-off request rejected.',
            'request' => $this->presenter->request($row),
        ]);
    }

    /**
     * Whose ask is being filed: the named employee for a manager, the
     * caller's own record otherwise. A login with no employment record
     * gets a 404 naming the missing record (the P5.3 punch lesson).
     */
    private function targetEmployee(Request $request, ?int $employeeId): Employee
    {
        $employee = $employeeId === null
            ? Employee::where('user_id', $request->user()->id)->first()
            : Employee::find($employeeId);

        abort_if($employee === null, 404, 'There is no employment record to file comp-off for.');

        return $employee;
    }
}
