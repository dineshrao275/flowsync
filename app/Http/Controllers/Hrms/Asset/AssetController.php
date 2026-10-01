<?php

namespace App\Http\Controllers\Hrms\Asset;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\AssetAssignRequest;
use App\Http\Requests\Hrms\AssetMaintenanceRequest;
use App\Http\Requests\Hrms\AssetRequest;
use App\Http\Requests\Hrms\AssetReturnRequest;
use App\Models\Hrms\Asset\Asset;
use App\Models\Hrms\Asset\AssetAssignment;
use App\Models\Hrms\Asset\AssetMaintenance;
use App\Models\Hrms\Employee\Employee;
use App\Models\Tenant;
use App\Services\Hrms\Asset\AssetAssignmentService;
use App\Services\Hrms\Asset\AssetService;
use App\Services\Hrms\DocumentService;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asset/HRMS — the register and its handovers over HTTP.
 *
 * Thin: it authorizes (reads on view, every movement on manage, receipts
 * on the holder alone), hands the payload to the two services, and shapes
 * the envelope. The invoice download delegates to the document download —
 * the file belongs to the document store with its own signed-link rules,
 * and a second download implementation would drift from them.
 */
class AssetController extends Controller
{
    public function __construct(
        private readonly AssetService $assets,
        private readonly AssetAssignmentService $handovers,
        private readonly DocumentService $documents,
        private readonly TenantDatabaseManager $tenants,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:asset_categories,id'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $query = Asset::query()
            ->with(['category:id,name,slug', 'assignee:id,employee_code,name'])
            ->orderBy('id');

        foreach (array_filter($filters, fn ($value) => $value !== null && $value !== '') as $field => $value) {
            if ($field === 'q') {
                $query->where(fn ($nested) => $nested
                    ->where('name', 'like', "%{$value}%")
                    ->orWhere('asset_code', 'like', "%{$value}%")
                    ->orWhere('serial_number', 'like', "%{$value}%"));

                continue;
            }

            $query->where($field, $value);
        }

        return response()->json([
            'assets' => $query->get()->map(fn (Asset $asset): array => $this->present($asset))->all(),
        ]);
    }

    public function show(Asset $asset): JsonResponse
    {
        $this->authorize('view', $asset);

        return response()->json(['asset' => $this->present($asset->load([
            'category:id,name,slug',
            'assignee:id,employee_code,name',
            'assignments.employee:id,employee_code,name',
            'maintenanceRecords',
        ]))]);
    }

    public function store(AssetRequest $request): JsonResponse
    {
        $this->authorize('create', Asset::class);

        $asset = $this->assets->create($request->validated(), $request->user());

        return response()->json([
            'message' => 'Asset registered.',
            'asset' => $this->present($asset),
        ], Response::HTTP_CREATED);
    }

    public function assign(AssetAssignRequest $request, Asset $asset): JsonResponse
    {
        $this->authorize('assign', $asset);

        $data = $request->validated();
        $employee = Employee::findOrFail((int) $data['employee_id']);

        $assignment = $this->handovers->assign($asset, $employee, (string) ($data['condition_out'] ?? 'good'), $request->user());

        return response()->json([
            'message' => 'Asset assigned.',
            'assignment' => $this->presentAssignment($assignment),
            'asset' => $this->present($asset->refresh()),
        ]);
    }

    public function acknowledge(Request $request, AssetAssignment $assignment): JsonResponse
    {
        $this->authorize('acknowledgeAssignment', [Asset::class, $assignment]);

        $acknowledged = $this->handovers->acknowledge($assignment, $request->user());

        return response()->json([
            'message' => 'Handover acknowledged.',
            'assignment' => $this->presentAssignment($acknowledged),
        ]);
    }

    public function returnAsset(AssetReturnRequest $request, Asset $asset): JsonResponse
    {
        $this->authorize('returnAsset', $asset);

        $assignment = $asset->assignments()->where('status', 'active')->orderByDesc('id')->firstOrFail();
        $data = $request->validated();

        $returned = $this->handovers->returnAsset(
            $assignment,
            (string) $data['condition_in'],
            $data['return_note'] ?? null,
            $request->user(),
        );

        return response()->json([
            'message' => 'Asset returned.',
            'assignment' => $this->presentAssignment($returned),
            'asset' => $this->present($asset->refresh()),
        ]);
    }

    public function maintenance(AssetMaintenanceRequest $request, Asset $asset): JsonResponse
    {
        $this->authorize('maintain', $asset);

        $record = $this->assets->maintenance($asset, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Maintenance opened.',
            'maintenance' => $this->presentMaintenance($record),
            'asset' => $this->present($asset->refresh()),
        ], Response::HTTP_CREATED);
    }

    /**
     * The asset's invoice through the document store's signed download.
     *
     * Outside the auth groups like every other signed file route: the
     * signature is the credential, so `asset` is an int (binding it would
     * query the central connection, where assets does not exist) and the
     * record resolves inside the tenant connection. The stream itself
     * comes from the document service, which owns file rules and re-enters
     * the tenant connection safely.
     */
    public function document(Request $request, int $asset): StreamedResponse
    {
        $tenant = Tenant::find((int) $request->query('tenant'));
        abort_if($tenant === null, 404);

        $invoiceId = $this->tenants->using($tenant, function () use ($asset): ?int {
            return Asset::query()->find($asset)?->invoice_document_id;
        });
        abort_if($invoiceId === null, 404);

        return $this->documents->download(
            $invoiceId,
            (int) $request->query('tenant'),
            $request->query('actor') === null ? null : (int) $request->query('actor'),
            $request->ip(),
        );
    }

    /**
     * The caller's open handovers with their assets: self-scoped like
     * notifications — no employment record means an empty list, because a
     * login without a record must not inherit anyone's hardware.
     */
    public function mine(Request $request): JsonResponse
    {
        $employeeId = Employee::where('user_id', $request->user()->id)->value('id');

        if ($employeeId === null) {
            return response()->json(['assignments' => [], 'employee_id' => null]);
        }

        $employee = Employee::findOrFail($employeeId);

        return response()->json([
            'assignments' => $this->handovers->byEmployee($employee)
                ->map(fn (AssetAssignment $assignment): array => $this->presentAssignment($assignment))->all(),
            'employee_id' => $employee->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Asset $asset): array
    {
        $asset->loadMissing(['category:id,name,slug', 'assignee:id,employee_code,name']);

        return [
            'id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            'category_id' => $asset->category_id,
            'category' => $asset->category ? ['id' => $asset->category->id, 'name' => $asset->category->name, 'slug' => $asset->category->slug] : null,
            'brand' => $asset->brand,
            'model' => $asset->model,
            'serial_number' => $asset->serial_number,
            'purchase_date' => $asset->purchase_date?->toDateString(),
            'purchase_value' => $asset->purchase_value === null ? null : (string) $asset->purchase_value,
            'vendor' => $asset->vendor,
            'invoice_document_id' => $asset->invoice_document_id,
            'warranty_ends_at' => $asset->warranty_ends_at?->toDateString(),
            'condition' => $asset->condition->value,
            'status' => $asset->status->value,
            'assigned_to_employee_id' => $asset->assigned_to_employee_id,
            'assignee' => $asset->assignee ? [
                'id' => $asset->assignee->id,
                'employee_code' => $asset->assignee->employee_code,
                'name' => $asset->assignee->displayName(),
            ] : null,
            'assigned_at' => $asset->assigned_at?->toIso8601String(),
            'location_id' => $asset->location_id,
            'notes' => $asset->notes,
            'assignments' => $asset->relationLoaded('assignments')
                ? $asset->assignments->map(fn (AssetAssignment $row): array => $this->presentAssignment($row))->all()
                : null,
            'maintenance' => $asset->relationLoaded('maintenanceRecords')
                ? $asset->maintenanceRecords->map(fn ($row): array => $this->presentMaintenance($row))->all()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAssignment(AssetAssignment $assignment): array
    {
        $assignment->loadMissing(['asset:id,asset_code,name', 'employee:id,employee_code,name']);

        return [
            'id' => $assignment->id,
            'asset_id' => $assignment->asset_id,
            'asset' => $assignment->asset ? [
                'id' => $assignment->asset->id,
                'asset_code' => $assignment->asset->asset_code,
                'name' => $assignment->asset->name,
            ] : null,
            'employee_id' => $assignment->employee_id,
            'employee' => $assignment->employee ? [
                'id' => $assignment->employee->id,
                'employee_code' => $assignment->employee->employee_code,
                'name' => $assignment->employee->displayName(),
            ] : null,
            'condition_out' => $assignment->condition_out->value,
            'acknowledged_at' => $assignment->acknowledged_at?->toIso8601String(),
            'returned_at' => $assignment->returned_at?->toIso8601String(),
            'condition_in' => $assignment->condition_in?->value,
            'return_note' => $assignment->return_note,
            'status' => $assignment->status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMaintenance(AssetMaintenance $record): array
    {
        return [
            'id' => $record->id,
            'asset_id' => $record->asset_id,
            'type' => $record->type->value,
            'description' => $record->description,
            'performed_by' => $record->performed_by,
            'cost' => $record->cost === null ? null : (string) $record->cost,
            'performed_at' => $record->performed_at->toDateString(),
            'next_due_at' => $record->next_due_at?->toDateString(),
            'notes' => $record->notes,
        ];
    }
}
