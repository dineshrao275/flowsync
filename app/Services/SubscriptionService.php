<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\Tenancy\TenantProductSync;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Central (system DB) subscription lifecycle.
 *
 * A tenant owns one `subscriptions` row per product (unique tenant_id + product: the
 * legacy `suite` bundle, or a `tms` and/or `hrms` plan) — plan
 * changes, trials, cancellation and renewal all re-stamp that row and append a
 * SubscriptionEvent for audit. Callers must run on the system connection
 * (CentralConnection pins the models regardless of the current default).
 */
class SubscriptionService
{
    public function __construct(
        private readonly TenantLifecycle $lifecycle,
    ) {}

    /**
     * Attach a plan to a tenant, creating the row on first subscribe and
     * re-stamping it (with a plan_changed event) on subsequent assignments.
     */
    public function assign(
        Tenant $tenant,
        SubscriptionPlan $plan,
        array $options = [],
    ): Subscription {
        // Drop explicitly-null options so callers can pass "unset" values without
        // overriding computed defaults (seats, auto_renew, billing fields).
        $options = array_filter($options, static fn ($value) => $value !== null);

        $product = $plan->product ?? SubscriptionPlan::PRODUCT_SUITE;
        $this->assertProductFits($tenant, $product);
        $existing = $tenant->subscriptionFor($product);

        $subscription = Subscription::updateOrCreate(
            ['tenant_id' => $tenant->id, 'product' => $product],
            array_merge([
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_start' => now(),
                'current_period_end' => $plan->periodEnd(),
                'trial_ends_at' => null,
                'canceled_at' => null,
                'auto_renew' => $options['auto_renew'] ?? true,
                'seats' => $options['seats'] ?? $plan->limit('users') ?: $plan->limit('seats') ?: 0,
                'billing_provider' => $options['billing_provider'] ?? null,
                'billing_reference' => $options['billing_reference'] ?? null,
            ], $options)
        );

        $tenant->update([
            'subscription_id' => $this->primary($tenant)?->id ?? $subscription->id,
            'trial_ends_at' => null,
        ]);
        $this->lifecycle->transition($tenant, Tenant::STATUS_ACTIVE);

        // Seats that end up different from the stored ones are a seats_changed
        // transition (an omitted `seats` option re-derives the plan default, so
        // the comparison is against the stored row, not the input); plan changes
        // already carry the seat move in their plan_changed event. `$existing`
        // still holds the pre-write value.
        $seatsChanged = $existing !== null
            && (int) $existing->seats !== (int) $subscription->seats;

        $this->record(
            $tenant,
            $subscription,
            match (true) {
                $existing && $existing->plan_id !== $plan->id => Subscription::EVENT_PLAN_CHANGED,
                $seatsChanged => Subscription::EVENT_SEATS_CHANGED,
                default => Subscription::EVENT_SUBSCRIBED,
            },
            fromPlanId: $existing ? $existing->plan_id : null,
            toPlanId: $plan->id,
            actorId: $options['actor_id'] ?? null,
            data: $seatsChanged
                ? array_merge($options['data'] ?? [], [
                    'seats_from' => $existing->seats,
                    'seats_to' => $subscription->seats,
                ])
                : ($options['data'] ?? []),
        );

        app(TenantProductSync::class)->ensure($tenant);

        return $subscription;
    }

    /**
     * Start a trial on a tenant. Idempotent-ish: re-entering a trial for the same
     * plan emits a fresh trial_started event and re-frames the period.
     */
    public function startTrial(
        Tenant $tenant,
        SubscriptionPlan $plan,
        ?int $days = null,
        ?int $actorId = null,
    ): Subscription {
        $days ??= $plan->trial_duration_days ?? config('subscriptions.default_trial_days', 14);
        $trialEndsAt = Carbon::now()->addDays(max(1, $days));

        $product = $plan->product ?? SubscriptionPlan::PRODUCT_SUITE;
        $this->assertProductFits($tenant, $product);

        $subscription = Subscription::updateOrCreate(
            ['tenant_id' => $tenant->id, 'product' => $product],
            [
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_TRIALING,
                'current_period_start' => now(),
                'current_period_end' => $trialEndsAt,
                'trial_ends_at' => $trialEndsAt,
                'canceled_at' => null,
                'seats' => $plan->limit('users') ?: $plan->limit('seats') ?: 0,
            ]
        );

        $tenant->update([
            'subscription_id' => $this->primary($tenant)?->id ?? $subscription->id,
            'trial_ends_at' => $trialEndsAt,
        ]);
        $this->lifecycle->transition($tenant, Tenant::STATUS_TRIAL);

        $this->record(
            $tenant,
            $subscription,
            Subscription::EVENT_TRIAL_STARTED,
            toPlanId: $plan->id,
            actorId: $actorId,
            data: ['days' => $days],
        );

        app(TenantProductSync::class)->ensure($tenant);

        return $subscription;
    }

    /**
     * Move a tenant to a different plan (re-stamp + plan_changed event).
     * `assign()` already records plan_changed when the plan differs, so this is a
     * semantic alias; returns the existing row untouched when the plan matches.
     */
    public function switch(
        Tenant $tenant,
        SubscriptionPlan $plan,
        array $options = [],
    ): Subscription {
        $existing = $tenant->subscriptionFor($plan->product ?? SubscriptionPlan::PRODUCT_SUITE);

        if ($existing && $existing->plan_id === $plan->id) {
            return $existing;
        }

        return $this->assign($tenant, $plan, $options);
    }

    /**
     * Cancel at period end: status=canceled, auto_renew off.
     */
    public function cancel(Tenant $tenant, ?int $actorId = null, ?string $product = null): Subscription
    {
        $subscription = $this->subscriptionOf($tenant, $product);

        if (! $subscription) {
            abort(422, 'Tenant has no subscription.');
        }

        $subscription->update([
            'status' => Subscription::STATUS_CANCELED,
            'auto_renew' => false,
            'canceled_at' => now(),
        ]);

        $this->record(
            $tenant,
            $subscription,
            Subscription::EVENT_CANCELED,
            fromPlanId: $subscription->plan_id,
            toPlanId: $subscription->plan_id,
            actorId: $actorId,
        );

        return $subscription;
    }

    /**
     * Renew for another period (active) or resume a canceled/expired one (reactivated).
     */
    public function renew(Tenant $tenant, ?int $actorId = null, ?string $product = null): Subscription
    {
        $subscription = $this->subscriptionOf($tenant, $product);

        if (! $subscription) {
            abort(422, 'Tenant has no subscription.');
        }

        $wasCanceled = $subscription->isCanceled();
        $plan = $subscription->plan;

        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'auto_renew' => true,
            'canceled_at' => null,
            'current_period_start' => now(),
            'current_period_end' => $plan->periodEnd($subscription->current_period_end),
            'trial_ends_at' => null,
        ]);

        $tenant->update(['trial_ends_at' => null]);
        $this->lifecycle->transition($tenant, Tenant::STATUS_ACTIVE);

        $this->record(
            $tenant,
            $subscription,
            $wasCanceled ? Subscription::EVENT_REACTIVATED : Subscription::EVENT_RENEWED,
            fromPlanId: $subscription->plan_id,
            toPlanId: $subscription->plan_id,
            actorId: $actorId,
        );

        return $subscription;
    }

    /**
     * Flag the subscription past due (payment trouble) — the tenant still lists as
     * serviceable until the platform decides to suspend; EVENT_PAUSED for audit.
     */
    public function suspend(Tenant $tenant, array $options = [], ?int $actorId = null, ?string $product = null): Subscription
    {
        $subscription = $this->subscriptionOf($tenant, $product);

        if (! $subscription) {
            abort(422, 'Tenant has no subscription.');
        }

        $subscription->update([
            'status' => Subscription::STATUS_PAST_DUE,
            'auto_renew' => true,
        ]);

        $this->record(
            $tenant,
            $subscription,
            Subscription::EVENT_PAUSED,
            fromPlanId: $subscription->plan_id,
            toPlanId: $subscription->plan_id,
            actorId: $actorId,
            data: $options['data'] ?? [],
        );

        return $subscription;
    }

    /**
     * Append an event row for a subscription change.
     */
    public function record(
        Tenant $tenant,
        Subscription $subscription,
        string $type,
        ?int $fromPlanId = null,
        ?int $toPlanId = null,
        ?int $actorId = null,
        array $data = [],
    ): SubscriptionEvent {
        return SubscriptionEvent::create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'type' => $type,
            'from_plan_id' => $fromPlanId,
            'to_plan_id' => $toPlanId,
            'data' => $data ?: null,
            'actor_id' => $actorId,
        ]);
    }

    /** The named product's subscription, or the primary one when no product is given. */
    private function subscriptionOf(Tenant $tenant, ?string $product): ?Subscription
    {
        return $product ? $tenant->subscriptionFor($product) : $this->primary($tenant);
    }

    /** Bundle first, then TMS, then HRMS — what `Tenant::subscription()` means. */
    private function primary(Tenant $tenant): ?Subscription
    {
        return $tenant->subscription()->first();
    }

    /**
     * A tenant is on the legacy bundle OR on per-product plans, never both: a bundle
     * already covers everything, so a second plan would be paid for and ignored.
     */
    private function assertProductFits(Tenant $tenant, string $product): void
    {
        $others = $tenant->subscriptions()->where('status', '!=', Subscription::STATUS_ENDED)->pluck('product')->all();

        if ($product !== SubscriptionPlan::PRODUCT_SUITE && in_array(SubscriptionPlan::PRODUCT_SUITE, $others, true)) {
            throw ValidationException::withMessages(['plan_id' => 'This tenant is on a bundle plan. Move it to per-product plans first.']);
        }
        if ($product === SubscriptionPlan::PRODUCT_SUITE && array_diff($others, [SubscriptionPlan::PRODUCT_SUITE]) !== []) {
            throw ValidationException::withMessages(['plan_id' => 'This tenant is on per-product plans. End them before moving to a bundle plan.']);
        }
    }

    /**
     * Move a bundle tenant onto per-product plans in one step, so no product is ever
     * left uncovered: the bundle ends, each given plan is assigned.
     *
     * @param  array<int, SubscriptionPlan>  $plans  at most one plan per product
     */
    public function splitBundle(Tenant $tenant, array $plans, ?int $actorId = null): void
    {
        $products = collect($plans)->map(fn (SubscriptionPlan $p) => $p->product)->all();
        if (count($products) !== count(array_unique($products)) || in_array(SubscriptionPlan::PRODUCT_SUITE, $products, true) || $plans === []) {
            throw ValidationException::withMessages(['plan_id' => 'Give one TMS and/or one HRMS plan.']);
        }

        $bundle = $tenant->subscriptionFor(SubscriptionPlan::PRODUCT_SUITE);
        if ($bundle) {
            $bundle->update(['status' => Subscription::STATUS_ENDED, 'auto_renew' => false, 'canceled_at' => now()]);
            $this->record($tenant, $bundle, Subscription::EVENT_CANCELED, fromPlanId: $bundle->plan_id, actorId: $actorId, data: ['reason' => 'moved_to_product_plans']);
        }
        foreach ($plans as $plan) {
            $this->assign($tenant->refresh(), $plan, ['actor_id' => $actorId]);
        }
    }
}
