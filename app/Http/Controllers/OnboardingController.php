<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\TenantOnboarding;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly TenantOnboarding $onboarding,
    ) {}

    /**
     * Tenant-facing status (the current user's own tenant, via TenantContext).
     */
    public function show(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant();

        return response()->json(['onboarding' => $this->onboarding->status($tenant)]);
    }

    /**
     * Tenant-facing: complete a wizard step.
     */
    public function updateStep(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant();

        $data = $request->validate([
            'step' => ['required', 'string', Rule::in($this->onboarding->completableSteps())],
            'data' => ['nullable', 'array'],
        ]);

        $this->onboarding->markStep($tenant, $data['step'], $data['data'] ?? null);

        return response()->json(['onboarding' => $this->onboarding->status($tenant)]);
    }

    /**
     * Tenant-facing: finish the wizard.
     */
    public function complete(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant();

        $this->onboarding->complete($tenant);

        return response()->json(['onboarding' => $this->onboarding->status($tenant)]);
    }

    /**
     * Super-admin: read a tenant's onboarding state.
     */
    public function showFor(Tenant $tenant): JsonResponse
    {
        return response()->json(['onboarding' => $this->onboarding->status($tenant)]);
    }

    /**
     * Super-admin: complete/reset a step, or reset the whole wizard.
     */
    public function updateFor(Tenant $tenant, Request $request): JsonResponse
    {
        $steps = array_keys($this->onboarding->catalog());

        $data = $request->validate([
            'step' => ['required', 'string', Rule::in([...$steps, 'complete', 'reset'])],
        ]);

        if ($data['step'] === 'complete') {
            $this->onboarding->complete($tenant);
        } elseif ($data['step'] === 'reset') {
            $this->onboarding->reset($tenant);
        } else {
            $this->onboarding->markStep($tenant, $data['step']);
        }

        return response()->json(['onboarding' => $this->onboarding->status($tenant)]);
    }

    private function currentTenant(): Tenant
    {
        $tenantId = app(TenantContext::class)->currentId();
        abort_unless($tenantId, 404);

        return Tenant::findOrFail($tenantId);
    }
}
