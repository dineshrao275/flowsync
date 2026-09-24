<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 14 — subscription-plan catalog (system DB). Reads are role-aware:
 * super admins get the full catalog, tenants the active plans; mutations are
 * super admin only.
 */
class PlanController extends Controller
{
    /**
     * Plan catalog. Super admins see every plan (admin page); any other
     * authenticated user sees the active plans only (tenant subscription page).
     */
    public function index(Request $request): JsonResponse
    {
        $isSuperAdmin = (bool) $request->user()?->is_super_admin;

        $query = SubscriptionPlan::orderBy('sort_order')->orderBy('id');

        if (! $isSuperAdmin) {
            $query->where('is_active', true);
        }

        return response()->json(['plans' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $plan = SubscriptionPlan::create([
            ...$data,
            'currency' => $data['currency'] ?? config('subscriptions.currency', 'USD'),
        ]);

        $this->ensureSingleDefault($plan);

        return response()->json(['plan' => $plan], 201);
    }

    public function update(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $data = $this->validateData($request, $plan);

        $plan->update($data);

        $this->ensureSingleDefault($plan);

        return response()->json(['plan' => $plan->fresh()]);
    }

    public function destroy(SubscriptionPlan $plan): JsonResponse
    {
        if ($plan->subscriptions()->exists()) {
            abort(422, 'Cannot delete a plan that tenants are subscribed to.');
        }

        if ($plan->is_default) {
            abort(422, 'Cannot delete the default plan.');
        }

        $plan->delete();

        return response()->json(['message' => 'Plan deleted.']);
    }

    private function validateData(Request $request, ?SubscriptionPlan $plan = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('subscription_plans', 'slug')->ignore($plan),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'billing_cycle' => ['nullable', 'string', Rule::in(['monthly', 'annual'])],
            'price_cents' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'trial_duration_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'limits' => ['nullable', 'array'],
            'limits.modules' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function ensureSingleDefault(SubscriptionPlan $plan): void
    {
        if ($plan->is_default) {
            SubscriptionPlan::where('id', '!=', $plan->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }
}
