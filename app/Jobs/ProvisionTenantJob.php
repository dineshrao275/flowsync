<?php

namespace App\Jobs;

use App\Billing\PaymentService;
use App\Models\ProvisioningRun;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\Tenancy\DefaultUserClaim;
use App\Services\TenantLifecycle;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provision a tenant for isolated (one-database-per-tenant) mode.
 *
 * Pipeline (idempotent + resumable; see TenantProvisioner::provisionIsolated):
 * status=provisioning → create database (PG role / sqlite file) → migrate the
 * tenant DB → seed catalogs + owner → mirror users into the central tenant_users
 * routing index → trial subscription (Phase 14, when a plan was requested) →
 * status=trial|active + provisioned.
 *
 * Failures record a failed ProvisioningRun + provisioning_error and move the
 * tenant to provisioning_failed; repair/retry happens via `tenants:provision`.
 */
class ProvisionTenantJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public Tenant $tenant,
        public ?int $planId = null,
        public ?int $trialDays = null,
        /** @var array{name: string, email: string, password_hash: string}|null */
        public ?array $defaultUser = null,
        /** Card saved during sign-up; the trial then converts to a paid subscription at the provider. */
        public ?string $paymentMethod = null,
        /** @var list<int> per-product plans beyond $planId (one TMS and one HRMS plan at most in total) */
        public array $additionalPlanIds = [],
    ) {}

    public function handle(
        TenantDatabaseManager $dbm,
        TenantProvisioner $provisioner,
        TenantLifecycle $lifecycle
    ): void {
        $this->tenant->refresh();

        $run = ProvisioningRun::create([
            'tenant_id' => $this->tenant->id,
            'status' => ProvisioningRun::STATUS_RUNNING,
            'step' => 'started',
            'started_at' => now(),
        ]);

        try {
            $dbm->connectSystem();
            $provisioner->provisionIsolated($this->tenant, $dbm, $lifecycle, $this->productsToBuild());

            // Intake collected a real default user: it replaces the seeded owner.
            if ($this->defaultUser) {
                app(DefaultUserClaim::class)->claim(
                    $this->tenant,
                    $this->defaultUser['name'],
                    $this->defaultUser['email'],
                    $this->defaultUser['password_hash'],
                );
            }

            // Phase 14 step 6: create the initial subscription once the tenant DB
            // is provisioned. No-ops when no plan was requested at onboarding.
            if ($this->planId) {
                // The provider customer exists from the moment the tenant does (best effort; `billing:reconcile` repairs it).
                app(PaymentService::class)->ensureCustomer($this->tenant->refresh());

                foreach ([$this->planId, ...$this->additionalPlanIds] as $index => $planId) {
                    $product = $this->provisionSubscription($planId, fallbackToDefault: $index === 0);
                    $product && $this->startProviderTrial($product);
                }
                // Keep the denormalised pointer on the primary (bundle > TMS > HRMS) subscription.
                $primary = $this->tenant->subscription()->first();
                $primary && $this->tenant->update(['subscription_id' => $primary->id]);

                $this->enforcePaymentRules($lifecycle);
            }

            $run->update([
                'status' => ProvisioningRun::STATUS_SUCCEEDED,
                'step' => null,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->tenant->refresh();
            $this->tenant->update(['provisioning_error' => $e->getMessage()]);

            $run->update([
                'status' => ProvisioningRun::STATUS_FAILED,
                'step' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            try {
                $lifecycle->transition($this->tenant, Tenant::STATUS_PROVISIONING_FAILED);
            } catch (Throwable $transitionError) {
                // Lifecycle guard rejected the transition (e.g. failure before
                // 'provisioning'); the run failure is still recorded.
            }

            Log::error('Tenant provisioning failed.', [
                'tenant_id' => $this->tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The products to build the schema for, from the plans being subscribed — the
     * subscription rows do not exist yet when the database is created. Null when no
     * plan was given (the tenant's current entitlement decides).
     *
     * @return list<string>|null
     */
    private function productsToBuild(): ?array
    {
        if (! $this->planId) {
            return null;
        }

        return SubscriptionPlan::whereIn('id', [$this->planId, ...$this->additionalPlanIds])->get()
            ->flatMap(fn (SubscriptionPlan $p) => $p->covers())->unique()->values()->all() ?: null;
    }

    /**
     * Best effort: a provider hiccup must not undo a provisioned tenant. A failure is remembered on the tenant and
     * `billing:reconcile` retries it; the card (if any) is attached to the provider subscription now, otherwise later.
     */
    private function startProviderTrial(string $product): void
    {
        $subscription = $this->tenant->refresh()->subscriptionFor($product);
        if ($subscription?->status !== Subscription::STATUS_TRIALING || ! $subscription->trial_ends_at) {
            return;
        }

        try {
            $days = max(1, (int) ceil(now()->diffInHours($subscription->trial_ends_at, false) / 24));
            app(PaymentService::class)->startProviderTrial($this->tenant, $this->paymentMethod, $days, $product);
        } catch (Throwable $e) {
            app(PaymentService::class)->recordSyncError($this->tenant->refresh(), 'trial', $e);
        }
    }

    /**
     * Nothing is free by accident. After the subscriptions exist:
     *  - a paid plan with no trial and no payment has no access until it is paid (tenant → expired, the 402 gate sends the
     *    admin to the Subscription page);
     *  - a trial that requires a card (`onboarding.require_card_for_trial`) but arrived without one is flagged so the same
     *    gate asks for the card first; adding one clears the flag.
     */
    private function enforcePaymentRules(TenantLifecycle $lifecycle): void
    {
        $tenant = $this->tenant->refresh();
        $subscriptions = $tenant->subscriptions()->with('plan')->get();
        $paid = $subscriptions->filter(fn (Subscription $s) => ($s->plan?->price_cents ?? 0) > 0);

        if ($paid->isEmpty()) {
            return;
        }

        if ($subscriptions->every(fn (Subscription $s) => $s->status === Subscription::STATUS_EXPIRED)
            && $tenant->status !== Tenant::STATUS_EXPIRED && $lifecycle->canTransition($tenant, Tenant::STATUS_EXPIRED)) {
            $lifecycle->transition($tenant, Tenant::STATUS_EXPIRED, null, ['reason' => 'awaiting_payment']);
        }

        $trialingPaid = $paid->contains(fn (Subscription $s) => $s->status === Subscription::STATUS_TRIALING);
        if ($trialingPaid && ! $this->paymentMethod && config('onboarding.require_card_for_trial')) {
            $settings = (array) $tenant->settings;
            $settings['billing']['card_required'] = true;
            $tenant->forceFill(['settings' => $settings])->saveQuietly();
        }
    }

    /**
     * Create the tenant's initial subscription on the system connection. Uses the
     * onboarding plan (planId) or falls back to the default catalog plan, enters a
     * trial when trialDays (or the plan's default trial) is present, and writes the
     * matching entry in subscription_events.
     */
    /** @return string|null the product the subscription was created for */
    private function provisionSubscription(int $planId, bool $fallbackToDefault): ?string
    {
        $plan = SubscriptionPlan::query()
            ->where('id', $planId)
            ->where('is_active', true)
            ->first();

        if (! $plan && $fallbackToDefault) {
            $plan = SubscriptionPlan::query()
                ->where('is_default', true)
                ->where('is_active', true)
                ->first() ?? SubscriptionPlan::where('is_active', true)->first();
        }

        if (! $plan) {
            return null;
        }

        $trialDays = $this->trialDays ?? $plan->trial_duration_days;
        $trialEndsAt = $trialDays ? now()->addDays($trialDays) : null;

        // A paid plan is only `active` once somebody has paid for it. No trial and no payment yet means no period at all:
        // the subscription waits as `expired` (the tenant sees the plan, the 402 gate asks for payment) instead of the
        // free month the old code granted by stamping an active period.
        $awaitingPayment = ! $trialEndsAt && ($plan->price_cents ?? 0) > 0;

        $subscription = Subscription::updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'product' => $plan->product ?? 'suite'],
            [
                'plan_id' => $plan->id,
                'status' => $trialEndsAt ? Subscription::STATUS_TRIALING : ($awaitingPayment ? Subscription::STATUS_EXPIRED : Subscription::STATUS_ACTIVE),
                'current_period_start' => $awaitingPayment ? null : now(),
                // A trial's period IS the trial; the first paid period starts when it converts.
                'current_period_end' => $awaitingPayment ? null : ($trialEndsAt ?? $plan->periodEnd()),
                'trial_ends_at' => $trialEndsAt,
                'auto_renew' => true,
                'seats' => $plan->limit('users') ?: 0,
            ]
        );

        $plan->events()->create([
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $subscription->id,
            'type' => $trialEndsAt ? Subscription::EVENT_TRIAL_STARTED : Subscription::EVENT_SUBSCRIBED,
            'to_plan_id' => $plan->id,
            'data' => $trialEndsAt ? ['days' => $trialDays] : ($awaitingPayment ? ['awaiting_payment' => true] : null),
        ]);

        Log::info('Tenant subscription provisioned.', [
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => $subscription->status,
        ]);

        return $subscription->product;
    }
}
