<?php

namespace Tests\Feature;

use App\Models\DunningAttempt;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\UserNotification;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P6.2 — failed payments walk the dunning sequence and end in suspension after the grace period. */
class DunningTest extends TestCase
{
    use IsolatesDatabase;

    private function pastDue(int $daysAgo): Subscription
    {
        $tenant = Tenant::where('slug', 'acme')->firstOrFail();
        $service = app(SubscriptionService::class);
        $service->assign($tenant, SubscriptionPlan::where('slug', 'pro')->firstOrFail());
        $subscription = $service->suspend($tenant->refresh());
        $subscription->update(['past_due_at' => now()->subDays($daysAgo)->subMinute()]);

        return $subscription->refresh();
    }

    private function sweep(): void
    {
        $this->artisan('billing:dunning')->assertSuccessful();
    }

    public function test_the_first_failure_anchors_the_cycle_and_repeats_keep_it(): void
    {
        $subscription = $this->pastDue(0);
        $anchor = $subscription->past_due_at;

        app(SubscriptionService::class)->suspend(Tenant::where('slug', 'acme')->firstOrFail());

        $this->assertEquals($anchor->timestamp, $subscription->refresh()->past_due_at->timestamp);
    }

    public function test_day_zero_sends_a_reminder_to_tenant_admins_once(): void
    {
        $this->pastDue(0);

        $this->sweep();
        $this->sweep();

        $this->assertSame(1, DunningAttempt::where('stage', 'reminder')->where('step_day', 0)->count());
        $this->assertSame(1, UserNotification::where('type', 'billing.payment_failed')->count());
        $this->assertSame('active', Tenant::where('slug', 'acme')->first()->status);
    }

    public function test_a_late_sweep_sends_only_the_newest_notice_and_records_the_rest_as_skipped(): void
    {
        $this->pastDue(5);

        $this->sweep();

        $this->assertSame(['done'], DunningAttempt::where('stage', 'final_notice')->pluck('outcome')->all());
        $this->assertSame(['skipped', 'skipped'], DunningAttempt::where('stage', 'reminder')->orderBy('step_day')->pluck('outcome')->all());
        $this->assertSame(1, UserNotification::where('type', 'billing.final_notice')->count());
        $this->assertSame(0, UserNotification::where('type', 'billing.payment_failed')->count());
    }

    public function test_the_tenant_is_suspended_after_the_grace_period(): void
    {
        config(['billing.dunning.grace_days' => 7]);
        $this->pastDue(7);

        $this->sweep();

        $this->assertSame('suspended', Tenant::where('slug', 'acme')->first()->status);
        $this->assertSame(1, UserNotification::where('type', 'billing.suspended')->count());
        $this->assertSame(1, DunningAttempt::where('stage', 'suspend')->count());
    }

    public function test_paying_clears_the_cycle_and_brings_a_suspended_tenant_back(): void
    {
        $this->pastDue(8);
        $this->sweep();
        $tenant = Tenant::where('slug', 'acme')->firstOrFail();
        $this->assertSame('suspended', $tenant->status);

        $subscription = app(SubscriptionService::class)->renew($tenant);

        $this->assertNull($subscription->refresh()->past_due_at);
        $this->assertSame('active', $tenant->refresh()->status);
        $this->artisan('billing:dunning')->expectsOutputToContain('Nothing to do');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->pastDue(8);

        $this->artisan('billing:dunning --dry-run')->assertSuccessful();

        $this->assertSame(0, DunningAttempt::count());
        $this->assertSame('active', Tenant::where('slug', 'acme')->first()->status);
    }
}
