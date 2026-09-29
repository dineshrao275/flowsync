<?php

namespace App\Http\Controllers\Hrms\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\TdsProjectionRequest;
use App\Http\Requests\Hrms\TdsSurrenderRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Statutory\TdsProject;
use App\Services\Hrms\Payroll\PayrollService;
use App\Services\Hrms\Statutory\StatutoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Statutory/HRMS — the annual TDS picture over HTTP.
 *
 * Projections and surrenders run on manage alone; reading one quarter is
 * self-or-manage. The `recompute` endpoint is the artisan command's twin
 * for a single run (review-only, `--force` becomes a `force` flag) — the
 * command stays for operators, this stays for the run-detail screen that
 * will surface the warnings beside the payslips.
 */
class TdsProjectController extends Controller
{
    public function __construct(
        private readonly StatutoryService $statutory,
        private readonly PayrollService $runs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TdsProject::class);

        $filters = $request->validate([
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'fiscal_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
        ]);

        $query = TdsProject::query()->with('employee:id,employee_code,name')->orderByDesc('fiscal_year')->orderBy('quarter');

        foreach (array_filter($filters, fn ($value) => $value !== null) as $field => $value) {
            $query->where($field, $value);
        }

        return response()->json([
            'projects' => $query->get()->map(fn (TdsProject $project): array => $this->present($project))->all(),
        ]);
    }

    public function show(TdsProject $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['project' => $this->present($project)]);
    }

    public function project(TdsProjectionRequest $request): JsonResponse
    {
        $this->authorize('project', TdsProject::class);

        $data = $request->validated();
        $employee = Employee::findOrFail((int) $data['employee_id']);

        $result = $this->statutory->projectTds($employee, (int) $data['fiscal_year'], $request->user());

        return response()->json([
            'message' => 'TDS projected.',
            'projects' => collect($result['projects'])->map(fn (TdsProject $project): array => $this->present($project))->all(),
            'warnings' => $result['warnings'],
        ]);
    }

    public function surrender(TdsSurrenderRequest $request, TdsProject $project): JsonResponse
    {
        $this->authorize('surrender', $project);

        $updated = $this->statutory->surrender($project, (string) $request->validated()['challan_ref'], $request->user());

        return response()->json([
            'message' => 'Shortfall surrendered.',
            'project' => $this->present($updated),
        ]);
    }

    public function recompute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'exists:payroll_runs,id'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $run = PayrollRun::findOrFail((int) $validated['run_id']);

        $this->authorize('calculate', $run);

        $summary = $this->runs->recomputeStatutory($run, $request->user(), (bool) ($validated['force'] ?? false));

        return response()->json([
            'message' => 'Statutory lines recomputed.',
            'summary' => $summary,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TdsProject $project): array
    {
        $project->loadMissing('employee:id,employee_code,name');

        return [
            'id' => $project->id,
            'employee_id' => $project->employee_id,
            'employee' => $project->employee ? [
                'id' => $project->employee->id,
                'employee_code' => $project->employee->employee_code,
                'name' => $project->employee->displayName(),
            ] : null,
            'fiscal_year' => $project->fiscal_year,
            'quarter' => $project->quarter,
            'declared_income' => (string) $project->declared_income,
            'exempt_income' => (string) $project->exempt_income,
            'projected_income' => (string) $project->projected_income,
            'tax_liability' => (string) $project->tax_liability,
            'tds_deducted' => (string) $project->tds_deducted,
            'tds_surrendered' => (string) $project->tds_surrendered,
            'challan_ref' => $project->challan_ref,
            'shortfall' => $project->shortfall(),
        ];
    }
}
