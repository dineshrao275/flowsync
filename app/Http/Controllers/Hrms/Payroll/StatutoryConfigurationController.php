<?php

namespace App\Http\Controllers\Hrms\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\StatutoryConfigurationRequest;
use App\Models\Hrms\Statutory\StatutoryConfiguration;
use App\Services\HrmsAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Statutory/HRMS — the jurisdiction rulebook surface.
 *
 * Thin and manage-gated throughout: rates, ceilings and slabs are
 * expert-owned, and there is no self-service reader of a rulebook. Every
 * mutation audits identifiers only — the config JSON itself never enters
 * the ledger, because thresholds are the payroll's source of truth, not
 * its diary. Deleting a rulebook is safe for history: locked payslips
 * price from their snapshots, never by re-reading.
 */
class StatutoryConfigurationController extends Controller
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', StatutoryConfiguration::class);

        return response()->json([
            'configurations' => StatutoryConfiguration::query()->orderBy('country')->orderBy('region')
                ->get()->map(fn (StatutoryConfiguration $row): array => $this->present($row))->all(),
        ]);
    }

    public function show(StatutoryConfiguration $configuration): JsonResponse
    {
        $this->authorize('view', $configuration);

        return response()->json(['configuration' => $this->present($configuration)]);
    }

    public function store(StatutoryConfigurationRequest $request): JsonResponse
    {
        $this->authorize('create', StatutoryConfiguration::class);

        $configuration = StatutoryConfiguration::create($request->validated());

        $this->audit->log($configuration, 'statutory.configuration_created', null, [
            'code' => $configuration->code,
        ], $request->user());

        return response()->json([
            'message' => 'Configuration created.',
            'configuration' => $this->present($configuration),
        ], Response::HTTP_CREATED);
    }

    public function update(StatutoryConfigurationRequest $request, StatutoryConfiguration $configuration): JsonResponse
    {
        $this->authorize('update', $configuration);

        $before = ['name' => $configuration->name, 'is_active' => $configuration->is_active];
        $configuration->update($request->validated());

        $this->audit->log($configuration->refresh(), 'statutory.configuration_updated', $before, [
            'name' => $configuration->name,
            'is_active' => $configuration->is_active,
        ], $request->user());

        return response()->json([
            'message' => 'Configuration updated.',
            'configuration' => $this->present($configuration->refresh()),
        ]);
    }

    public function destroy(Request $request, StatutoryConfiguration $configuration): JsonResponse
    {
        $this->authorize('delete', $configuration);

        $this->audit->log($configuration, 'statutory.configuration_deleted', [
            'code' => $configuration->code,
        ], null, $request->user());

        $configuration->delete();

        return response()->json(['message' => 'Configuration deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StatutoryConfiguration $configuration): array
    {
        return [
            'id' => $configuration->id,
            'country' => $configuration->country,
            'region' => $configuration->region,
            'name' => $configuration->name,
            'code' => $configuration->code,
            'is_active' => $configuration->is_active,
            'config' => $configuration->config ?? [],
        ];
    }
}
