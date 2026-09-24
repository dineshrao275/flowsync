<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 14 — per-tenant subscription management (system DB, super admin only).
 */
class TenantSubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json($this->payload($tenant));
    }

    /**
     * Assign a plan (creates the row or re-stamps it — plan_changed when the
     * current plan differs) and records the audit event.
     */
    public function assign(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'auto_renew' => ['nullable', 'boolean'],
            'billing_provider' => ['nullable', 'string', 'max:64'],
            'billing_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $plan = SubscriptionPlan::findOrFail($data['plan_id']);

        $this->subscriptions->assign(
            $tenant,
            $plan,
            [
                'seats' => $data['seats'] ?? null,
                'auto_renew' => $data['auto_renew'] ?? null,
                'billing_provider' => $data['billing_provider'] ?? null,
                'billing_reference' => $data['billing_reference'] ?? null,
                'actor_id' => auth()->id(),
            ]
        );

        return response()->json([
            'message' => 'Subscription saved.',
            ...$this->payload($tenant),
        ]);
    }

    public function startTrial(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $plan = SubscriptionPlan::findOrFail($data['plan_id']);

        $this->subscriptions->startTrial($tenant, $plan, $data['days'] ?? null, auth()->id());

        return response()->json([
            'message' => 'Trial started.',
            ...$this->payload($tenant),
        ]);
    }

    public function cancel(Tenant $tenant): JsonResponse
    {
        $this->subscriptions->cancel($tenant, auth()->id());

        return response()->json([
            'message' => 'Subscription canceled.',
            ...$this->payload($tenant),
        ]);
    }

    public function renew(Tenant $tenant): JsonResponse
    {
        $this->subscriptions->renew($tenant, auth()->id());

        return response()->json([
            'message' => 'Subscription renewed.',
            ...$this->payload($tenant),
        ]);
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->subscriptions->suspend($tenant, ['data' => ['reason' => $data['reason'] ?? null]], auth()->id());

        return response()->json([
            'message' => 'Subscription suspended.',
            ...$this->payload($tenant),
        ]);
    }

    public function events(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'events' => $tenant->subscriptionEvents()
                ->with('fromPlan:id,name,slug', 'toPlan:id,name,slug', 'actor:id,name')
                ->orderByDesc('id')
                ->paginate(50),
        ]);
    }

    private function payload(Tenant $tenant): array
    {
        $tenant->load('subscription.plan');
        $subscription = $tenant->subscription;

        return [
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'plan' => $subscription->plan ? [
                    'id' => $subscription->plan->id,
                    'name' => $subscription->plan->name,
                    'slug' => $subscription->plan->slug,
                    'limits' => $subscription->plan->limits,
                ] : null,
                'current_period_start' => $subscription->current_period_start?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'canceled_at' => $subscription->canceled_at?->toIso8601String(),
                'auto_renew' => $subscription->auto_renew,
                'seats' => $subscription->seats,
                'billing_provider' => $subscription->billing_provider,
                'billing_reference' => $subscription->billing_reference,
            ] : null,
            'plans' => SubscriptionPlan::orderBy('sort_order')->orderBy('id')->get([
                'id', 'name', 'slug', 'description', 'is_active', 'is_default', 'billing_cycle',
                'price_cents', 'currency', 'trial_duration_days', 'sort_order',
            ]),
        ];
    }
}
