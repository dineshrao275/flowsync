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
            $provisioner->provisionIsolated($this->tenant, $dbm, $lifecycle);

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
                $this->provisionSubscription();
                $this->startProviderTrial();
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

    /** Best effort: a provider hiccup must not undo a provisioned tenant — the tenant can add a card later. */
    private function startProviderTrial(): void
    {
        if (! $this->paymentMethod || ! $this->trialDays) {
            return;
        }

        try {
            app(PaymentService::class)->startProviderTrial($this->tenant->refresh(), $this->paymentMethod, $this->trialDays);
        } catch (Throwable $e) {
            Log::warning('Could not start the provider trial subscription.', ['tenant_id' => $this->tenant->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Create the tenant's initial subscription on the system connection. Uses the
     * onboarding plan (planId) or falls back to the default catalog plan, enters a
     * trial when trialDays (or the plan's default trial) is present, and writes the
     * matching entry in subscription_events.
     */
    private function provisionSubscription(): void
    {
        $plan = SubscriptionPlan::query()
            ->where('id', $this->planId)
            ->where('is_active', true)
            ->first();

        if (! $plan) {
            $plan = SubscriptionPlan::query()
                ->where('is_default', true)
                ->where('is_active', true)
                ->first() ?? SubscriptionPlan::where('is_active', true)->first();
        }

        if (! $plan) {
            return;
        }

        $trialDays = $this->trialDays ?? $plan->trial_duration_days;
        $trialEndsAt = $trialDays ? now()->addDays($trialDays) : null;

        $subscription = Subscription::updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'product' => $plan->product ?? 'suite'],
            [
                'plan_id' => $plan->id,
                'status' => $trialEndsAt ? Subscription::STATUS_TRIALING : Subscription::STATUS_ACTIVE,
                'current_period_start' => now(),
                'current_period_end' => $plan->periodEnd(),
                'trial_ends_at' => $trialEndsAt,
                'auto_renew' => true,
                'seats' => $plan->limit('users') ?: 0,
            ]
        );

        $this->tenant->update(['subscription_id' => $subscription->id]);

        $plan->events()->create([
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $subscription->id,
            'type' => $trialEndsAt ? Subscription::EVENT_TRIAL_STARTED : Subscription::EVENT_SUBSCRIBED,
            'to_plan_id' => $plan->id,
            'data' => $trialEndsAt ? ['days' => $trialDays] : null,
        ]);

        Log::info('Tenant subscription provisioned.', [
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => $subscription->status,
        ]);
    }
}
