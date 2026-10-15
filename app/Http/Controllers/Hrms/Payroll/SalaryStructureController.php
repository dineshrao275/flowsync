<?php

namespace App\Http\Controllers\Hrms\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\SalaryStructureRequest;
use App\Models\Hrms\Payroll\SalaryStructure;
use App\Services\Hrms\Compensation\CompensationCatalogService;
use App\Services\Hrms\Compensation\CompensationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Compensation/HRMS — the salary-template surface.
 *
 * Creating a template (with its heads) stays on {@see CompensationService},
 * because pricing needs the same path the assignment flow uses; everything
 * after creation — rename, deactivate, delete, and the head-list sync —
 * lives on the catalog service. The sync replaces the whole list (the
 * OrgNaming::order lesson), so there are no attach/detach halves to drift.
 */
class SalaryStructureController extends Controller
{
    public function __construct(
        private readonly CompensationService $compensation,
        private readonly CompensationCatalogService $catalog,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', SalaryStructure::class);

        return response()->json([
            'structures' => SalaryStructure::query()->with('components')->orderBy('name')
                ->get()->map(fn (SalaryStructure $structure): array => $this->present($structure))->all(),
        ]);
    }

    public function show(SalaryStructure $structure): JsonResponse
    {
        $this->authorize('view', $structure);

        return response()->json(['structure' => $this->present($structure->load('components'))]);
    }

    public function store(SalaryStructureRequest $request): JsonResponse
    {
        $this->authorize('create', SalaryStructure::class);

        $data = $request->validated();
        $links = $data['components'] ?? [];
        unset($data['components']);

        $structure = $this->compensation->createStructure(
            [...$data, 'created_by' => $request->user()->id],
            $links,
        );

        return response()->json([
            'message' => 'Structure created.',
            'structure' => $this->present($structure->load('components')),
        ], Response::HTTP_CREATED);
    }

    public function update(SalaryStructureRequest $request, SalaryStructure $structure): JsonResponse
    {
        $this->authorize('update', $structure);

        $data = $request->validated();
        unset($data['components']);

        $updated = $this->catalog->updateStructure($structure, $data, $request->user());

        return response()->json([
            'message' => 'Structure updated.',
            'structure' => $this->present($updated->load('components')),
        ]);
    }

    public function destroy(Request $request, SalaryStructure $structure): JsonResponse
    {
        $this->authorize('delete', $structure);

        $this->catalog->deleteStructure($structure, $request->user());

        return response()->json(['message' => 'Structure deleted.']);
    }

    /**
     * Replace the template's head list wholesale.
     */
    public function setComponents(Request $request, SalaryStructure $structure): JsonResponse
    {
        $this->authorize('update', $structure);

        $validated = $request->validate([
            'components' => ['required', 'array', 'min:1'],
            'components.*.component_id' => ['required', 'integer', 'exists:salary_components,id'],
            'components.*.value' => ['sometimes', 'numeric'],
            'components.*.sequence' => ['sometimes', 'integer', 'min:0'],
        ], [
            'components.*.component_id.exists' => 'A linked head does not exist.',
        ]);

        $updated = $this->catalog->setStructureComponents($structure, $validated['components'], $request->user());

        return response()->json([
            'message' => 'Structure heads updated.',
            'structure' => $this->present($updated->load('components')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SalaryStructure $structure): array
    {
        return [
            'id' => $structure->id,
            'name' => $structure->name,
            'slug' => $structure->slug,
            'currency' => $structure->currency,
            'effective_from' => $structure->effective_from->toDateString(),
            'description' => $structure->description,
            'is_default' => $structure->is_default,
            'is_active' => $structure->is_active,
            'components' => $structure->components->map(fn ($component): array => [
                'id' => $component->id,
                'code' => $component->code,
                'name' => $component->name,
                'type' => $component->type->value,
                'value' => (string) $component->pivot->value,
                'sequence' => $component->pivot->sequence,
            ])->all(),
        ];
    }
}
