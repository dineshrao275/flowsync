<?php

namespace App\Http\Controllers;

use App\Http\Requests\TenantHrmsUpdateRequest;
use App\Http\Resources\Hrms\Shared\TenantHrmsResource;
use App\Models\Tenant;
use App\Services\Hrms\Shared\TenantHrmsService;
use App\Services\ModuleTree;
use Illuminate\Http\JsonResponse;

/**
 * P1.7 — super-admin per-tenant HRMS entitlement.
 *
 * Read + toggle the additive `tenants.features_override.modules` layer for one
 * tenant. Thin by design (D2.16.1): validate → one service call → present.
 * The route group already carries `super_admin`.
 */
class TenantHrmsController extends Controller
{
    public function __construct(
        private readonly TenantHrmsService $hrms,
        private readonly ModuleTree $moduleTree,
    ) {}

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'hrms' => $this->resource($tenant)->resolve(request()),
        ]);
    }

    public function update(TenantHrmsUpdateRequest $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('modules', $data)) {
            $this->hrms->setModules($tenant, $data['modules']);
        } else {
            $this->hrms->setEnabled($tenant, (bool) $data['enabled']);
        }

        return response()->json([
            'message' => 'HRMS entitlement updated.',
            'hrms' => $this->resource($tenant)->resolve($request),
        ]);
    }

    private function resource(Tenant $tenant): TenantHrmsResource
    {
        return new TenantHrmsResource(
            $this->hrms->status($tenant),
            $this->moduleTree->groups(),
        );
    }
}
