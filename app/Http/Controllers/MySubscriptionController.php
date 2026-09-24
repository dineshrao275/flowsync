<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use App\Services\TenantLimits;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 14 — tenant-facing subscription self-service.
 *
 * Self-scoped via TenantContext (no tenant_context middleware): a tenant user
 * resolves their own central `tenants` row. Plan switches, cancellation and
 * renewal are gated to tenant admins (`hasRole('admin')`); a non-impersonating
 * super admin has no tenant context and gets 404 (they use the SA endpoints).
 */
class MySubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly TenantLimits $limits,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload($this->currentTenant()));
    }

    /**
     * Current usage against the effective plan limits (tenant DB counts).
     */
    public function usage(): JsonResponse
    {
        $tenant = $this->currentTenant();

        $usage = [];
        foreach (['users', 'seats', 'workspaces', 'projects', 'tasks'] as $resource) {
            $usage[$resource] = $this->limits->currentCount($resource);
        }

        return response()->json([
            'usage' => $usage,
            'limits' => $this->limits->effective($tenant),
            'modules' => $this->limits->limit($tenant, 'modules'),
            'modules_available' => config('subscriptions.modules', []),
        ]);
    }

    public function switch(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant();
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
        ]);

        $plan = SubscriptionPlan::find($data['plan_id']);
        abort_unless($plan, 422, 'Unknown plan.');

        if ($tenant->subscription?->plan_id === $plan->id) {
            return response()->json([
                'message' => 'Already subscribed to this plan.',
                ...$this->payload($tenant),
            ]);
        }

        abort_unless($plan->is_active, 422, 'That plan is not currently available.');

        $actor = $request->user();

        $this->subscriptions->switch($tenant, $plan, [
            'actor_id' => null,
            'data' => ['actor' => ['id' => $actor->id, 'name' => $actor->name, 'email' => $actor->email]],
        ]);

        return response()->json(['message' => 'Plan updated.', ...$this->payload($tenant)]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant();
        $this->authorizeAdmin($request);

        // The tenant admin is not a central `users` row, so we cannot use their
        // id as subscription_events.actor_id (FK → system users). Omitting the
        // actor keeps the audit event write safe; the tenant is implicit.
        $this->subscriptions->cancel($tenant);

        return response()->json(['message' => 'Subscription canceled.', ...$this->payload($tenant)]);
    }

    public function renew(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant();
        $this->authorizeAdmin($request);

        $this->subscriptions->renew($tenant);

        return response()->json(['message' => 'Subscription renewed.', ...$this->payload($tenant)]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('admin'), 403, 'Only tenant admins can manage the subscription.');
    }

    private function currentTenant(): Tenant
    {
        $tenantId = app(TenantContext::class)->currentId();
        abort_unless($tenantId, 404);

        return Tenant::findOrFail($tenantId);
    }

    private function payload(Tenant $tenant): array
    {
        $tenant->load('subscription.plan');
        $subscription = $tenant->subscription;

        $events = $tenant->subscriptionEvents()
            ->with('fromPlan:id,name,slug', 'toPlan:id,name,slug', 'actor:id,name')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type,
                'from_plan' => $event->fromPlan?->only('id', 'name', 'slug'),
                'to_plan' => $event->toPlan?->only('id', 'name', 'slug'),
                'actor' => $event->actor?->only('id', 'name'),
                'created_at' => $event->created_at?->toIso8601String(),
            ]);

        return [
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'plan' => $subscription->plan ? $subscription->plan->only([
                    'id', 'name', 'slug', 'description', 'billing_cycle',
                    'price_cents', 'currency', 'trial_duration_days', 'limits',
                ]) : null,
                'current_period_start' => $subscription->current_period_start?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'canceled_at' => $subscription->canceled_at?->toIso8601String(),
                'auto_renew' => $subscription->auto_renew,
                'seats' => $subscription->seats,
                'billing_provider' => $subscription->billing_provider,
                'billing_reference' => $subscription->billing_reference,
            ] : null,
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            ],
            'events' => $events,
        ];
    }
}
