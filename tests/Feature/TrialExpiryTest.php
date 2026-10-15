<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * G-2: trials must end. Nothing else in the codebase moves a subscription
 * (or its tenant) to `expired`, so this command is the only thing that can.
 */
class TrialExpiryTest extends TestCase
{
    use IsolatesDatabase;

    private function plan(string $slug = 'starter'): SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', $slug)->firstOrFail();
    }

    /**
     * Put the tenant into a trial whose window has already passed.
     */
    private function lapsedTrial(Tenant $tenant): Subscription
    {
        $subscription = app(SubscriptionService::class)->startTrial($tenant, $this->plan(), 14);
        $subscription->update(['trial_ends_at' => now()->subDay()]);
        $tenant->update(['trial_ends_at' => now()->subDay()]);

        return $subscription;
    }

    private function expireTrials(array $options = []): int
    {
        return Artisan::call('tenants:expire-trials', $options);
    }

    private function trialExpiredEvents(?int $tenantId = null): int
    {
        return SubscriptionEvent::query()
            ->where('type', Subscription::EVENT_TRIAL_EXPIRED)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->count();
    }

    public function test_a_lapsed_trial_expires_the_tenant_and_the_subscription(): void
    {
        $acme = $this->acme();
        $this->lapsedTrial($acme);

        $this->assertSame(0, $this->expireTrials());

        $acme = $this->acme();
        $this->assertSame(Tenant::STATUS_EXPIRED, $acme->status);
        $this->assertFalse($acme->isServiceable());
        $this->assertSame(Subscription::STATUS_EXPIRED, $acme->subscription->status);
        $this->assertSame(1, $this->trialExpiredEvents($acme->id));
    }

    public function test_a_trial_that_has_not_ended_is_left_alone(): void
    {
        $acme = $this->acme();
        app(SubscriptionService::class)->startTrial($acme, $this->plan(), 14);

        $this->assertSame(0, $this->expireTrials());

        $this->assertSame(Tenant::STATUS_TRIAL, $this->acme()->status);
        $this->assertSame(Subscription::STATUS_TRIALING, $this->acme()->subscription->status);
        $this->assertSame(0, $this->trialExpiredEvents());
    }

    public function test_the_command_is_idempotent(): void
    {
        $this->lapsedTrial($this->acme());

        $this->assertSame(0, $this->expireTrials());
        $this->assertSame(0, $this->expireTrials());

        $this->assertSame(1, $this->trialExpiredEvents());
        $this->assertSame(Tenant::STATUS_EXPIRED, $this->acme()->status);
    }

    public function test_a_paid_subscription_on_a_trial_tenant_is_reported_and_skipped(): void
    {
        // Inconsistent data: the subscription says `active` (paid) while the
        // tenant row still says `trial` with a past window. Expiry must not
        // lock out a payer — it is reported and skipped instead.
        $acme = $this->acme();
        $subscription = $this->lapsedTrial($acme);
        $subscription->update(['status' => Subscription::STATUS_ACTIVE]);

        $this->assertSame(0, $this->expireTrials());

        $this->assertSame(Tenant::STATUS_TRIAL, $this->acme()->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $this->acme()->subscription->status);
        $this->assertSame(0, $this->trialExpiredEvents());
    }

    public function test_a_single_tenant_can_be_targeted(): void
    {
        $acme = $this->acme();
        $globex = $this->globex();
        $this->lapsedTrial($acme);
        $this->lapsedTrial($globex);

        $this->assertSame(0, $this->expireTrials(['--tenant' => $acme->id]));

        $this->assertSame(Tenant::STATUS_EXPIRED, $this->acme()->status);
        $this->assertSame(Tenant::STATUS_TRIAL, $globex->fresh()->status);
        $this->assertSame(1, $this->trialExpiredEvents($acme->id));
        $this->assertSame(0, $this->trialExpiredEvents($globex->id));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $acme = $this->acme();
        $this->lapsedTrial($acme);

        $this->assertSame(0, $this->expireTrials(['--dry-run' => true]));

        $this->assertSame(Tenant::STATUS_TRIAL, $this->acme()->status);
        $this->assertSame(Subscription::STATUS_TRIALING, $this->acme()->subscription->status);
        $this->assertSame(0, $this->trialExpiredEvents());
    }

    public function test_an_unknown_tenant_id_fails_loudly(): void
    {
        $this->assertSame(1, $this->expireTrials(['--tenant' => 999999]));
    }
}
