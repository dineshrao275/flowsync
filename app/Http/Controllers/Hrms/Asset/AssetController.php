<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hrms\Asset;

use App\Http\Controllers\Concerns\HandlesCustomFields;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\AssetAssignRequest;
use App\Http\Requests\Hrms\AssetIndexRequest;
use App\Http\Requests\Hrms\AssetMaintenanceRequest;
use App\Http\Requests\Hrms\AssetRequest;
use App\Http\Requests\Hrms\AssetReturnRequest;
use App\Models\Hrms\Asset\Asset;
use App\Models\Hrms\Asset\AssetAssignment;
use App\Models\Hrms\Employee\Employee;
use App\Models\Tenant;
use App\Services\Hrms\Asset\AssetAssignmentService;
use App\Services\Hrms\Asset\AssetPresenter;
use App\Services\Hrms\Asset\AssetService;
use App\Services\Hrms\DocumentService;
use App\Support\Hrms\HrmsSchema;
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
    use HandlesCustomFields;

    public function __construct(
        private readonly AssetService $assets,
        private readonly AssetAssignmentService $handovers,
        private readonly DocumentService $documents,
        private readonly TenantDatabaseManager $tenants,
        private readonly AssetPresenter $presenter,
    ) {}

    public function index(AssetIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $filters = $request->validated();

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

        $custom = $this->validatedCustomFields($request, 'asset', partial: false);

        $asset = $this->assets->create($request->validated(), $request->user());
        $this->storeCustomFields('asset', $asset->id, $custom);

        return response()->json([
            'message' => __('Asset registered.'),
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
            'message' => __('Asset assigned.'),
            'assignment' => $this->presenter->assignment($assignment),
            'asset' => $this->present($asset->refresh()),
        ]);
    }

    public function acknowledge(Request $request, AssetAssignment $assignment): JsonResponse
    {
        $this->authorize('acknowledgeAssignment', [Asset::class, $assignment]);

        $acknowledged = $this->handovers->acknowledge($assignment, $request->user());

        return response()->json([
            'message' => __('Handover acknowledged.'),
            'assignment' => $this->presenter->assignment($acknowledged),
        ]);
    }

    public function returnAsset(AssetReturnRequest $request, Asset $asset): JsonResponse
    {
        $assignment = $asset->assignments()->where('status', 'active')->orderByDesc('id')->firstOrFail();

        $this->authorize('returnAsset', [Asset::class, $assignment]);

        $data = $request->validated();

        $returned = $this->handovers->returnAsset(
            $assignment,
            (string) $data['condition_in'],
            $data['return_note'] ?? null,
            $request->user(),
        );

        return response()->json([
            'message' => __('Asset returned.'),
            'assignment' => $this->presenter->assignment($returned),
            'asset' => $this->present($asset->refresh()),
        ]);
    }

    public function maintenance(AssetMaintenanceRequest $request, Asset $asset): JsonResponse
    {
        $this->authorize('maintain', $asset);

        $record = $this->assets->maintenance($asset, $request->validated(), $request->user());

        return response()->json([
            'message' => __('Maintenance opened.'),
            'maintenance' => $this->presenter->maintenance($record),
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
            // A signed link outlives a product change: without the HRMS schema
            // the answer is 404, not a missing-table 500.
            abort_unless(HrmsSchema::present(), 404);

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
                ->map(fn (AssetAssignment $assignment): array => $this->presenter->assignment($assignment))->all(),
            'employee_id' => $employee->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Asset $asset): array
    {
        return $this->withCustomFields([$this->presenter->asset($asset)], 'asset', true)[0];
    }
}
