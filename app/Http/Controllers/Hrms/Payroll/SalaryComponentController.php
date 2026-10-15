<?php

namespace App\Http\Controllers\Hrms\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\SalaryComponentRequest;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Services\Hrms\Compensation\CompensationCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Compensation/HRMS — the pay-head catalogue surface.
 *
 * Thin: it authorizes against the component policy, hands the payload to
 * the catalog service, and shapes the response. Starter and statutory rows
 * refuse inside the service, not here.
 */
class SalaryComponentController extends Controller
{
    public function __construct(private readonly CompensationCatalogService $catalog) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', SalaryComponent::class);

        return response()->json([
            'components' => SalaryComponent::query()->orderBy('sequence')->orderBy('name')
                ->get()->map(fn (SalaryComponent $component): array => $this->present($component))->all(),
        ]);
    }

    public function show(SalaryComponent $component): JsonResponse
    {
        $this->authorize('view', $component);

        return response()->json(['component' => $this->present($component)]);
    }

    public function store(SalaryComponentRequest $request): JsonResponse
    {
        $this->authorize('create', SalaryComponent::class);

        $component = $this->catalog->createComponent($request->validated(), $request->user());

        return response()->json([
            'message' => 'Component created.',
            'component' => $this->present($component),
        ], Response::HTTP_CREATED);
    }

    public function update(SalaryComponentRequest $request, SalaryComponent $component): JsonResponse
    {
        $this->authorize('update', $component);

        $updated = $this->catalog->updateComponent($component, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Component updated.',
            'component' => $this->present($updated),
        ]);
    }

    public function destroy(Request $request, SalaryComponent $component): JsonResponse
    {
        $this->authorize('delete', $component);

        $this->catalog->deleteComponent($component, $request->user());

        return response()->json(['message' => 'Component deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SalaryComponent $component): array
    {
        return [
            'id' => $component->id,
            'name' => $component->name,
            'slug' => $component->slug,
            'code' => $component->code,
            'type' => $component->type->value,
            'calculation_type' => $component->calculation_type->value,
            'default_value' => (string) $component->default_value,
            'is_taxable' => $component->is_taxable,
            'is_prorated' => $component->is_prorated,
            'is_statutory' => $component->is_statutory,
            'is_system' => $component->is_system,
            'is_active' => $component->is_active,
            'sequence' => $component->sequence,
        ];
    }
}
