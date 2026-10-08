<?php

namespace App\Http\Controllers\Hrms\Attendance;

use App\Enums\Hrms\RegularizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Attendance\RegularizationDecideRequest;
use App\Http\Requests\Hrms\Attendance\RegularizationStoreRequest;
use App\Models\Hrms\Attendance\AttendanceRegularizationRequest;
use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\Attendance\RegularizationDirectory;
use App\Services\Hrms\Attendance\RegularizationPresenter;
use App\Services\Hrms\Attendance\RegularizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Attendance/HRMS — correction asks over HTTP.
 *
 * Thin by design: resolve the caller's employment record, hand the payload
 * to the service, shape the answer. Listing and deciding are policy-gated,
 * never route-permission-gated — requesting and reading one's own asks is
 * self-service (D2.12), and deciding belongs to the step's approver, who
 * may hold no attendance permission at all.
 */
class RegularizationController extends Controller
{
    public function __construct(
        private readonly RegularizationService $regularizations,
        private readonly RegularizationDirectory $directory,
        private readonly RegularizationPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRegularizationRequest::class);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(RegularizationStatus::class)],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
        ]);

        $rows = $this->directory->listFor($request->user(), $this->clean($filters));

        return response()->json([
            'requests' => $rows->map(fn (AttendanceRegularizationRequest $row): array => $this->presenter->present($row))->all(),
        ]);
    }

    public function store(RegularizationStoreRequest $request): JsonResponse
    {
        $this->authorize('create', AttendanceRegularizationRequest::class);

        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();

        $row = $this->regularizations->request($employee, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Correction requested.',
            'request' => $this->presenter->present($row),
        ], Response::HTTP_CREATED);
    }

    public function show(AttendanceRegularizationRequest $regularization): JsonResponse
    {
        $this->authorize('view', $regularization);

        return response()->json([
            'request' => $this->presenter->present($regularization),
        ]);
    }

    public function approve(RegularizationDecideRequest $request, AttendanceRegularizationRequest $regularization): JsonResponse
    {
        $this->authorize('approve', $regularization);

        $row = $this->regularizations->approve($regularization, $request->user(), $request->validated()['note'] ?? null);

        return response()->json([
            'message' => 'Correction approved and applied.',
            'request' => $this->presenter->present($row),
        ]);
    }

    public function reject(RegularizationDecideRequest $request, AttendanceRegularizationRequest $regularization): JsonResponse
    {
        $this->authorize('reject', $regularization);

        $row = $this->regularizations->reject($regularization, $request->user(), (string) ($request->validated()['note'] ?? ''));

        return response()->json([
            'message' => 'Correction rejected.',
            'request' => $this->presenter->present($row),
        ]);
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
