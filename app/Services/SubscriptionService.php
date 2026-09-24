<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Central (system DB) subscription lifecycle.
 *
 * Every tenant owns exactly ONE `subscriptions` row (unique tenant_id) — plan
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

        $existing = $tenant->subscription;

        $subscription = Subscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
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
            'subscription_id' => $subscription->id,
            'trial_ends_at' => null,
        ]);
        $this->lifecycle->transition($tenant, Tenant::STATUS_ACTIVE);

        $this->record(
            $tenant,
            $subscription,
            $existing && $existing->plan_id !== $plan->id
                ? Subscription::EVENT_PLAN_CHANGED
                : Subscription::EVENT_SUBSCRIBED,
            fromPlanId: $existing ? $existing->plan_id : null,
            toPlanId: $plan->id,
            actorId: $options['actor_id'] ?? null,
            data: $options['data'] ?? [],
        );

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

        $subscription = Subscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
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
            'subscription_id' => $subscription->id,
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
        $existing = $tenant->subscription;

        if ($existing && $existing->plan_id === $plan->id) {
            return $existing;
        }

        return $this->assign($tenant, $plan, $options);
    }

    /**
     * Cancel at period end: status=canceled, auto_renew off.
     */
    public function cancel(Tenant $tenant, ?int $actorId = null): Subscription
    {
        $subscription = $tenant->subscription;

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
    public function renew(Tenant $tenant, ?int $actorId = null): Subscription
    {
        $subscription = $tenant->subscription;

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
    public function suspend(Tenant $tenant, array $options = [], ?int $actorId = null): Subscription
    {
        $subscription = $tenant->subscription;

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
}
