<?php

namespace App\Http\Controllers\Hrms\Payroll;

use App\Enums\Hrms\DataAccessAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\StatutoryDeclarationRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Statutory\StatutoryDeclaration;
use App\Services\Hrms\Statutory\StatutoryDeclarationService;
use App\Services\HrmsAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Statutory/HRMS — exemption claims and their small lifecycle.
 *
 * Filing and submitting are self-or-manage; verifying and rejecting are
 * manage-alone, because a claim becomes a tax fact only through HR. Amounts
 * travel in the payload (a claim without a figure is not a claim) but never
 * into the audit trail — the service logs identifiers and states only.
 */
class StatutoryDeclarationController extends Controller
{
    public function __construct(
        private readonly StatutoryDeclarationService $declarations,
        private readonly HrmsAuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StatutoryDeclaration::class);

        $filters = $request->validate([
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'fiscal_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $query = StatutoryDeclaration::query()->with('employee:id,employee_code,name')->orderByDesc('id');

        foreach (array_filter($filters, fn ($value) => $value !== null) as $field => $value) {
            $query->where($field, $value);
        }

        $rows = $query->get()->map(fn (StatutoryDeclaration $row): array => $this->present($row))->all();

        // Declared amounts travel in the payload by design, so the read
        // names its fields in the ledger (record 0 — the list has no
        // single subject).
        $this->audit->accessed(
            (new StatutoryDeclaration)->getMorphClass(),
            0,
            DataAccessAction::View,
            ['declared_amount', 'section', 'status'],
            $request->user(),
            $request->ip(),
        );

        return response()->json(['declarations' => $rows]);
    }

    public function show(Request $request, StatutoryDeclaration $declaration): JsonResponse
    {
        $this->authorize('view', $declaration);

        $this->audit->accessed(
            $declaration->getMorphClass(),
            $declaration->id,
            DataAccessAction::View,
            ['declared_amount', 'section', 'status'],
            $request->user(),
            $request->ip(),
        );

        return response()->json(['declaration' => $this->present($declaration)]);
    }

    public function store(StatutoryDeclarationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $employee = Employee::findOrFail((int) $data['employee_id']);

        $this->authorize('file', [StatutoryDeclaration::class, $employee]);

        $declaration = $this->declarations->file($employee, $data, $request->user());

        return response()->json([
            'message' => 'Declaration filed.',
            'declaration' => $this->present($declaration),
        ], Response::HTTP_CREATED);
    }

    public function submit(Request $request, StatutoryDeclaration $declaration): JsonResponse
    {
        $this->authorize('submit', $declaration);

        return response()->json([
            'message' => 'Declaration submitted.',
            'declaration' => $this->present($this->declarations->submit($declaration, $request->user())),
        ]);
    }

    public function verify(Request $request, StatutoryDeclaration $declaration): JsonResponse
    {
        $this->authorize('decide', $declaration);

        return response()->json([
            'message' => 'Declaration verified.',
            'declaration' => $this->present($this->declarations->verify($declaration, $request->user())),
        ]);
    }

    public function reject(Request $request, StatutoryDeclaration $declaration): JsonResponse
    {
        $this->authorize('decide', $declaration);

        return response()->json([
            'message' => 'Declaration rejected.',
            'declaration' => $this->present($this->declarations->reject($declaration, $request->user())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StatutoryDeclaration $declaration): array
    {
        $declaration->loadMissing('employee:id,employee_code,name');

        return [
            'id' => $declaration->id,
            'employee_id' => $declaration->employee_id,
            'employee' => $declaration->employee ? [
                'id' => $declaration->employee->id,
                'employee_code' => $declaration->employee->employee_code,
                'name' => $declaration->employee->displayName(),
            ] : null,
            'fiscal_year' => $declaration->fiscal_year,
            'section' => $declaration->section,
            'declared_amount' => (string) $declaration->declared_amount,
            'proof_document_id' => $declaration->proof_document_id,
            'status' => $declaration->status,
            'submitted_at' => $declaration->submitted_at?->toIso8601String(),
            'verified_at' => $declaration->verified_at?->toIso8601String(),
        ];
    }
}
