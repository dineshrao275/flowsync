<?php

namespace App\Http\Controllers\Hrms\Leave;

use App\Enums\Hrms\LeaveRequestStatus;
use App\Http\Controllers\Concerns\NormalizesFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Leave\LeaveDecisionRequest;
use App\Http\Requests\Hrms\Leave\LeaveExemptionRequest as LeaveExemptionStoreRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveExemptionRequest;
use App\Services\Hrms\Leave\LeaveExemptionDirectory;
use App\Services\Hrms\Leave\LeaveExemptionService;
use App\Services\Hrms\Leave\LeavePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Leave/HRMS — exemption asks over HTTP.
 *
 * The statutory trail: anyone raises their own, managers read through
 * `hrms.leave.manage`, and the single `decide` endpoint carries the
 * verdict — approve flips the status, reject needs a reason, and neither
 * posts to the ledger.
 */
class LeaveExemptionController extends Controller
{
    use NormalizesFilters;

    public function __construct(
        private readonly LeaveExemptionService $exemptions,
        private readonly LeaveExemptionDirectory $directory,
        private readonly LeavePresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveExemptionRequest::class);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(LeaveRequestStatus::class)],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
        ]);

        $rows = $this->directory->listFor($request->user(), $this->clean($filters));

        return response()->json([
            'exemptions' => $rows->map(fn (LeaveExemptionRequest $row): array => $this->presenter->exemption($row))->all(),
        ]);
    }

    public function store(LeaveExemptionStoreRequest $request): JsonResponse
    {
        $employee = $this->targetEmployee($request, $request->validated()['employee_id'] ?? null);

        $this->authorize('create', [LeaveExemptionRequest::class, $employee]);

        $row = $this->exemptions->requestFor($employee, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Exemption requested.',
            'exemption' => $this->presenter->exemption($row),
        ], Response::HTTP_CREATED);
    }

    public function show(LeaveExemptionRequest $exemption): JsonResponse
    {
        $this->authorize('view', $exemption);

        return response()->json([
            'exemption' => $this->presenter->exemption($exemption),
        ]);
    }

    public function decide(LeaveDecisionRequest $request, LeaveExemptionRequest $exemption): JsonResponse
    {
        $this->authorize('decide', $exemption);

        $validated = $request->validated();

        // The shared decision request leaves the verdict optional (the
        // approve/reject endpoints do not send one); this endpoint does not
        // decide without it.
        if (! isset($validated['decision'])) {
            throw ValidationException::withMessages(['decision' => 'A verdict is approve or reject.']);
        }

        $row = $this->exemptions->decide(
            $exemption,
            $request->user(),
            (string) $validated['decision'],
            $validated['note'] ?? null,
        );

        return response()->json([
            'message' => $this->decideMessage((string) $validated['decision'], $row->status === LeaveRequestStatus::Approved),
            'exemption' => $this->presenter->exemption($row),
        ]);
    }

    /**
     * Intermediate approvals advance without deciding: the message says the
     * step was recorded, not that the ask was approved.
     */
    private function decideMessage(string $decision, bool $resolved): string
    {
        if ($decision !== 'approve') {
            return 'Exemption rejected.';
        }

        return $resolved ? 'Exemption approved.' : 'Approval recorded.';
    }

    /**
     * Whose ask is being filed: the named employee for a manager, the
     * caller's own record otherwise.
     */
    private function targetEmployee(Request $request, ?int $employeeId): Employee
    {
        $employee = $employeeId === null
            ? Employee::where('user_id', $request->user()->id)->first()
            : Employee::find($employeeId);

        abort_if($employee === null, 404, 'There is no employment record to file an exemption for.');

        return $employee;
    }
}
