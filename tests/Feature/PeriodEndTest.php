<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P6.3 — a lapsed paid period is expired, renewed or sent to dunning according to who bills it. */
class PeriodEndTest extends TestCase
{
    use IsolatesDatabase;

    private function lapsed(array $attributes = [], string $plan = 'pro'): Subscription
    {
        $tenant = Tenant::where('slug', 'acme')->firstOrFail();
        $subscription = app(SubscriptionService::class)->assign($tenant, SubscriptionPlan::where('slug', $plan)->firstOrFail());
        $subscription->update(['current_period_end' => now()->subHour()] + $attributes);

        return $subscription->refresh();
    }

    private function sweep(): void
    {
        $this->artisan('billing:period-ends')->assertSuccessful();
    }

    public function test_a_cancelled_subscription_expires_with_its_tenant_at_period_end(): void
    {
        $subscription = $this->lapsed(['status' => 'canceled', 'auto_renew' => false]);

        $this->sweep();

        $this->assertSame('expired', $subscription->refresh()->status);
        $this->assertSame('expired', Tenant::where('slug', 'acme')->first()->status);
        $this->assertSame(1, $subscription->events()->where('type', 'expired')->count());
    }

    public function test_a_future_period_is_left_alone(): void
    {
        $subscription = $this->lapsed(['current_period_end' => now()->addDay(), 'status' => 'canceled']);

        $this->sweep();

        $this->assertSame('canceled', $subscription->refresh()->status);
    }

    public function test_a_platform_billed_subscription_rolls_forward(): void
    {
        $subscription = $this->lapsed();

        $this->sweep();

        $this->assertTrue($subscription->refresh()->current_period_end->isFuture());
        $this->assertSame('active', $subscription->status);
        $this->assertSame('active', Tenant::where('slug', 'acme')->first()->status);
    }

    public function test_the_unbilled_policy_can_expire_instead(): void
    {
        config(['billing.period_end.unbilled_policy' => 'expire']);
        $subscription = $this->lapsed();

        $this->sweep();

        $this->assertSame('expired', $subscription->refresh()->status);
    }

    public function test_a_stripe_subscription_gets_the_renewal_grace_then_falls_into_dunning(): void
    {
        config(['billing.period_end.renewal_grace_hours' => 72]);
        $subscription = $this->lapsed(['billing_provider' => 'stripe', 'provider_subscription_id' => 'sub_pe']);

        $this->sweep();
        $this->assertSame('active', $subscription->refresh()->status);

        $subscription->update(['current_period_end' => now()->subHours(73)]);
        $this->sweep();

        $this->assertSame('past_due', $subscription->refresh()->status);
        $this->assertNotNull($subscription->past_due_at);
    }

    public function test_a_one_off_provider_payment_falls_into_dunning_at_once(): void
    {
        $subscription = $this->lapsed(['billing_provider' => 'razorpay']);

        $this->sweep();

        $this->assertSame('past_due', $subscription->refresh()->status);
    }

    public function test_turning_auto_renew_off_expires_it_and_a_second_run_does_nothing(): void
    {
        $subscription = $this->lapsed(['auto_renew' => false]);

        $this->sweep();
        $this->sweep();

        $this->assertSame('expired', $subscription->refresh()->status);
        $this->assertSame(1, $subscription->events()->where('type', 'expired')->count());
    }
}
