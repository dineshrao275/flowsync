<?php

namespace App\Http\Controllers;

use App\Billing\PaymentService;
use App\Billing\Proration\ProrationCalculator;
use App\Http\Controllers\Concerns\DetectsPlatformUsers;
use App\Http\Controllers\Concerns\ResolvesCurrentTenant;
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
    use DetectsPlatformUsers;
    use ResolvesCurrentTenant;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly TenantLimits $limits,
        private readonly PaymentService $payments,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeBillingView($request);

        return response()->json($this->payload($this->currentTenant()));
    }

    /**
     * Current usage against the effective plan limits (tenant DB counts).
     */
    public function usage(Request $request): JsonResponse
    {
        $this->authorizeBillingView($request);

        $tenant = $this->currentTenant();

        $usage = [];
        foreach (['users', 'seats', 'workspaces', 'projects', 'tasks'] as $resource) {
            $usage[$resource] = $this->limits->currentCount($resource);
        }

        return response()->json([
            'usage' => $usage,
            'limits' => $this->limits->effective($tenant),
            'modules' => $this->limits->visibleModules($tenant),
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

        if ($tenant->subscriptionFor($plan->product ?? 'suite')?->plan_id === $plan->id) {
            return response()->json([
                'message' => 'Already subscribed to this plan.',
                ...$this->payload($tenant),
            ]);
        }

        abort_unless($plan->is_active, 422, 'That plan is not currently available.');

        $actor = $request->user();

        // Moving off a paid plan mid-period leaves unused paid time; record its value on
        // the event (P6.4) so support can honour it — nothing applies it automatically.
        $credit = app(ProrationCalculator::class)->credit($tenant->subscriptionFor($plan->product ?? 'suite'), $plan);

        $this->subscriptions->switch($tenant, $plan, [
            'actor_id' => null,
            'data' => array_filter([
                'actor' => ['id' => $actor->id, 'name' => $actor->name, 'email' => $actor->email],
                'proration' => $credit,
            ]),
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
        // Stop the provider from billing the next period before we say so locally.
        $product = $this->product($request);
        $this->payments->setProviderCancelAtPeriodEnd($tenant, true, $product);
        $this->subscriptions->cancel($tenant, null, $product);

        return response()->json(['message' => 'Subscription canceled.', ...$this->payload($tenant)]);
    }

    public function renew(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant();
        $this->authorizeAdmin($request);

        $product = $this->product($request);
        $this->payments->setProviderCancelAtPeriodEnd($tenant, false, $product);
        $this->subscriptions->renew($tenant, null, $product);

        return response()->json(['message' => 'Subscription renewed.', ...$this->payload($tenant)]);
    }

    /** Which product's subscription an action is about; none = the primary one (older clients). */
    private function product(Request $request): ?string
    {
        return $request->validate(['product' => ['nullable', 'in:suite,tms,hrms']])['product'] ?? null;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            $request->user()?->hasRole('admin') || $request->user()?->hasPermission('billing.manage'),
            403,
            'Only tenant admins can manage the subscription.',
        );
    }

    /**
     * Viewing subscription info is restricted to tenant admins (role: admin)
     * or any user explicitly granted the `billing.view` permission by their
     * tenant admin. The `billing.view` permission is included in the admin
     * role via the `*` wildcard in `config/permissions.php`; other roles
     * receive it only when a tenant admin explicitly grants it.
     */
    private function authorizeBillingView(Request $request): void
    {
        // Platform super admins live on the system DB, which holds no
        // tenant roles table — skip the check; currentTenant() 404s
        // downstream exactly as before.
        if ($this->isPlatformSuperAdmin($request)) {
            return;
        }

        $user = $request->user();

        if ($user && ! $user->relationLoaded('roles')) {
            $user->load(['roles', 'roles.permissions']);
        }

        abort_unless(
            $user?->hasRole('admin') || $user?->hasPermission('billing.view'),
            403,
            'You do not have permission to view billing information.',
        );
    }

    private function payload(Tenant $tenant): array
    {
        $tenant->load('subscription.plan', 'subscriptions.plan');
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
            // One entry per product the tenant pays for (a legacy bundle shows as `suite`).
            'subscriptions' => $tenant->subscriptions->map(fn ($s) => [
                'id' => $s->id,
                'product' => $s->product,
                'status' => $s->status,
                'plan' => $s->plan?->only(['id', 'name', 'slug', 'description', 'billing_cycle', 'price_cents', 'currency', 'trial_duration_days', 'limits', 'product']),
                'current_period_end' => $s->current_period_end?->toIso8601String(),
                'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
                'canceled_at' => $s->canceled_at?->toIso8601String(),
                'auto_renew' => $s->auto_renew,
                'billing_provider' => $s->billing_provider,
            ])->values(),
            'products' => [
                'tms' => $this->limits->productEnabled($tenant, 'tms'),
                'hrms' => $this->limits->productEnabled($tenant, 'hrms'),
            ],
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
