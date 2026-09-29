<?php

namespace App\Http\Controllers\Hrms\Leave;

use App\Enums\Hrms\LeaveRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Leave\LeaveDecisionRequest;
use App\Http\Requests\Hrms\Leave\LeaveRequestRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Services\Hrms\Leave\LeavePresenter;
use App\Services\Hrms\Leave\LeaveRequestDecisions;
use App\Services\Hrms\Leave\LeaveRequestDirectory;
use App\Services\Hrms\Leave\LeaveRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Leave/HRMS — leave asks over HTTP.
 *
 * Thin by design: resolve whose ask (an explicit id for managers, the
 * caller's own record otherwise), hand the payload to the service, shape
 * the answer. Listing and deciding are policy-gated, never
 * route-permission-gated — filing and reading one's own asks is
 * self-service, and deciding belongs to the step's approver.
 */
class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $requests,
        private readonly LeaveRequestDecisions $decisions,
        private readonly LeaveRequestDirectory $directory,
        private readonly LeavePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(LeaveRequestStatus::class)],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
        ]);

        $rows = $this->directory->listFor($request->user(), $this->clean($filters));

        return response()->json([
            'requests' => $rows->map(fn (LeaveRequest $row): array => $this->presenter->request($row))->all(),
        ]);
    }

    public function store(LeaveRequestRequest $request): JsonResponse
    {
        $employee = $this->targetEmployee($request, $request->validated()['employee_id'] ?? null);

        $this->authorize('create', [LeaveRequest::class, $employee]);

        $row = $this->requests->request($employee, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Leave requested.',
            'request' => $this->presenter->request($row),
        ], Response::HTTP_CREATED);
    }

    public function show(LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('view', $leaveRequest);

        return response()->json([
            'request' => $this->presenter->request($leaveRequest),
        ]);
    }

    public function update(LeaveRequestRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('update', $leaveRequest);

        $row = $this->requests->updateAsk($leaveRequest, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Leave request updated.',
            'request' => $this->presenter->request($row),
        ]);
    }

    public function destroy(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('delete', $leaveRequest);

        $row = $this->decisions->cancelRequest($leaveRequest, $request->user());

        return response()->json([
            'message' => 'Leave request withdrawn.',
            'request' => $this->presenter->request($row),
        ]);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('delete', $leaveRequest);

        $filters = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        $row = $this->decisions->cancelRequest($leaveRequest, $request->user(), $filters['reason'] ?? null);

        return response()->json([
            'message' => 'Leave request cancelled.',
            'request' => $this->presenter->request($row),
        ]);
    }

    public function approve(LeaveDecisionRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('approve', $leaveRequest);

        $row = $this->decisions->approve($leaveRequest, $request->user(), $request->validated()['note'] ?? null);

        return response()->json([
            'message' => $row->status === LeaveRequestStatus::Approved ? 'Leave request approved.' : 'Approval recorded.',
            'request' => $this->presenter->request($row),
        ]);
    }

    public function reject(LeaveDecisionRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('reject', $leaveRequest);

        $row = $this->decisions->reject($leaveRequest, $request->user(), (string) ($request->validated()['note'] ?? ''));

        return response()->json([
            'message' => 'Leave request rejected.',
            'request' => $this->presenter->request($row),
        ]);
    }

    /**
     * Whose ask is being filed: the named employee for a manager, the
     * caller's own record otherwise. A login with no employment record
     * gets a 404 naming the missing record (the P5.3 punch lesson).
     */
    private function targetEmployee(LeaveRequestRequest $request, ?int $employeeId): Employee
    {
        $employee = $employeeId === null
            ? Employee::where('user_id', $request->user()->id)->first()
            : Employee::find($employeeId);

        abort_if($employee === null, 404, 'There is no employment record to file leave for.');

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function clean(array $filters): array
    {
        return array_filter($filters, fn (mixed $value): bool => $value !== null);
    }
}
