<?php

namespace App\Http\Controllers\Hrms\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\SalaryAssignmentRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\EmployeeSalaryStructure;
use App\Models\Hrms\Payroll\SalaryRevision;
use App\Models\Hrms\Payroll\SalaryStructure;
use App\Models\User;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Compensation\SalaryRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Compensation/HRMS — one person's pay basis and its history.
 *
 * No dedicated policy (the plan names none): reading takes
 * `hrms.compensation.view` or self — pay is sensitive, and the person in
 * the record may see their own basis — while pricing, revising and applying
 * take `hrms.compensation.manage` alone. Nested revision routes declare both
 * models and verify belonging: a revision id from another record 404s rather
 * than applying the wrong raise.
 */
class EmployeeSalaryController extends Controller
{
    public function __construct(
        private readonly CompensationService $compensation,
        private readonly SalaryRevisionService $revisions,
    ) {}

    /**
     * The live pay basis with its resolved monthly heads, or null when the
     * person was never priced — a record without a basis is unpriced, not
     * zero-paid, and the response says so instead of inventing money.
     */
    public function salary(Request $request, Employee $employee): JsonResponse
    {
        $this->requireReader($request->user(), $employee);

        $assignment = $this->currentAssignment($employee);

        return response()->json([
            'assignment' => $assignment === null ? null : $this->presentAssignment($assignment),
        ]);
    }

    public function assign(SalaryAssignmentRequest $request, Employee $employee): JsonResponse
    {
        $this->requireManager($request->user());

        $data = $request->validated();
        $structure = SalaryStructure::findOrFail((int) $data['structure_id']);

        $assignment = $this->compensation->assign(
            $employee,
            $structure,
            (string) $data['ctc_annual'],
            (string) $data['effective_from'],
            $data['reason'] ?? null,
            $request->user(),
        );

        return response()->json([
            'message' => 'Salary assigned.',
            'assignment' => $this->presentAssignment($assignment),
        ], Response::HTTP_CREATED);
    }

    public function revisions(Request $request, Employee $employee): JsonResponse
    {
        $this->requireReader($request->user(), $employee);

        return response()->json([
            'revisions' => SalaryRevision::query()->where('employee_id', $employee->id)
                ->orderByDesc('id')->get()
                ->map(fn (SalaryRevision $revision): array => $this->presentRevision($revision))->all(),
        ]);
    }

    public function revise(Request $request, Employee $employee): JsonResponse
    {
        $this->requireManager($request->user());

        $validated = $request->validate([
            'to_ctc' => ['required', 'numeric'],
            'effective_from' => ['required', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $revision = $this->revisions->revise(
            $employee,
            (string) $validated['to_ctc'],
            (string) $validated['effective_from'],
            $validated['reason'] ?? null,
            $request->user(),
        );

        return response()->json([
            'message' => 'Revision raised.',
            'revision' => $this->presentRevision($revision),
        ], Response::HTTP_CREATED);
    }

    public function applyRevision(Request $request, Employee $employee, SalaryRevision $revision): JsonResponse
    {
        $this->requireManager($request->user());
        abort_if($revision->employee_id !== $employee->id, 404);

        $applied = $this->revisions->apply($revision, $request->user());

        return response()->json([
            'message' => 'Revision applied.',
            'revision' => $this->presentRevision($applied),
        ]);
    }

    private function currentAssignment(Employee $employee): ?EmployeeSalaryStructure
    {
        return EmployeeSalaryStructure::query()
            ->where('employee_id', $employee->id)
            ->where('is_current', true)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAssignment(EmployeeSalaryStructure $assignment): array
    {
        $resolved = $this->compensation->ctcToComponents(
            $assignment->structure()->with('components')->firstOrFail(),
            (string) $assignment->ctc_annual,
        );

        return [
            'id' => $assignment->id,
            'structure' => [
                'id' => $assignment->structure->id,
                'name' => $assignment->structure->name,
            ],
            'ctc_annual' => (string) $assignment->ctc_annual,
            'monthly_ctc' => (string) $assignment->monthly_ctc,
            'gross_monthly' => (string) $assignment->gross_monthly,
            'effective_from' => $assignment->effective_from->toDateString(),
            'effective_to' => $assignment->effective_to?->toDateString(),
            'is_current' => $assignment->is_current,
            'reason' => $assignment->reason,
            'heads' => $resolved['components'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRevision(SalaryRevision $revision): array
    {
        return [
            'id' => $revision->id,
            'from_ctc' => (string) $revision->from_ctc,
            'to_ctc' => (string) $revision->to_ctc,
            'change_percent' => (string) $revision->change_percent,
            'effective_from' => $revision->effective_from->toDateString(),
            'reason' => $revision->reason,
            'status' => $revision->status->value,
            'approval_id' => $revision->approval_id,
            'letter_document_id' => $revision->letter_document_id,
        ];
    }

    private function requireReader(User $user, Employee $employee): void
    {
        $isSelf = $employee->user_id !== null && (int) $employee->user_id === (int) $user->id;

        abort_unless($isSelf || $user->hasPermission('hrms.compensation.view'), 403);
    }

    private function requireManager(User $user): void
    {
        abort_unless($user->hasPermission('hrms.compensation.manage'), 403);
    }
}
