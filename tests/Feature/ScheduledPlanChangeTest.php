<?php

namespace Tests\Feature;

use App\Billing\BillingLock;
use App\Billing\Plans\PlanChangeScheduler;
use App\Mail\BillingNoticeMail;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\SubscriptionService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\StripeTestHelpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Upgrades and downgrades for a paying tenant wait for the period end: one subscription, one invoice per period, no overlap. */
class ScheduledPlanChangeTest extends TestCase
{
    use IsolatesDatabase;
    use StripeTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStripe();
        Mail::fake();
    }

    private function fakeStripe(array $overrides = []): void
    {
        Http::fake($overrides + [
            'api.stripe.com/v1/customers*' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/products*' => Http::response(['id' => 'prod_1']),
            'api.stripe.com/v1/prices/*' => Http::response(['id' => 'price_old']),
            'api.stripe.com/v1/prices' => Http::response(['id' => 'price_biz']),
            'api.stripe.com/v1/subscriptions/sub_123' => Http::response(['id' => 'sub_123', 'schedule' => null, 'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => 'price_pro']]]]]),
            'api.stripe.com/v1/subscription_schedules/*/release' => Http::response(['id' => 'sub_sched_1', 'status' => 'released']),
            'api.stripe.com/v1/subscription_schedules/sub_sched_1' => Http::response([
                'id' => 'sub_sched_1',
                'phases' => [['start_date' => 1700000000, 'end_date' => 1702600000, 'items' => [['price' => 'price_pro', 'quantity' => 1]]]],
            ]),
            'api.stripe.com/v1/subscription_schedules' => Http::response(['id' => 'sub_sched_1']),
        ]);
    }

    private function scheduleBusiness(): Subscription
    {
        $this->stripeSubscriber('pro');
        $this->fakeStripe();
        $this->loginAdmin();
        $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('business')->id])
            ->assertOk()->assertJsonPath('mode', 'scheduled');

        return $this->acme()->subscription;
    }

    public function test_switching_while_paying_is_queued_for_the_period_end_with_one_subscription_and_no_charge_now(): void
    {
        $tenant = $this->stripeSubscriber('pro', daysLeft: 12);
        $periodEnd = $tenant->subscription->current_period_end;
        $this->fakeStripe();
        $this->loginAdmin();

        $response = $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('business')->id])
            ->assertOk()->assertJsonPath('mode', 'scheduled');

        $subscription = $this->acme()->subscription;
        $this->assertSame($this->plan('pro')->id, $subscription->plan_id);          // still the paid plan
        $this->assertSame($this->plan('business')->id, $subscription->pending_plan_id);
        $this->assertTrue($subscription->pending_change_at->equalTo($periodEnd));    // exactly at the end of the paid period
        $this->assertSame('sub_sched_1', $subscription->provider_schedule_id);
        $this->assertSame(1, Subscription::where('tenant_id', $this->acme()->id)->count());
        $response->assertJsonPath('subscriptions.0.pending_change.plan.slug', 'business');

        // Stripe is told with a schedule: current plan until the boundary, then the new one, no proration
        $update = collect($this->stripePosts('/subscription_schedules/sub_sched_1'))->first();
        $this->assertSame('price_pro', $update->data()['phases[0][items][0][price]']);
        $this->assertSame('price_biz', $update->data()['phases[1][items][0][price]']);
        $this->assertSame('none', $update->data()['phases[1][proration_behavior]']);
        $this->assertSame('release', $update->data()['end_behavior']);
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkout/sessions'));
        $this->assertCount(0, $this->stripePosts('/subscriptions/sub_123')); // no mid-period price swap

        $this->assertNotNull(SubscriptionEvent::where('type', 'plan_change_scheduled')->first());
        Mail::assertQueued(BillingNoticeMail::class, fn (BillingNoticeMail $m) => $m->hasTo('billing@acme.test') && str_contains(implode(' ', $m->lines), 'Business'));
    }

    public function test_the_provider_performing_the_swap_is_mirrored_locally_exactly_once(): void
    {
        $subscription = $this->scheduleBusiness();
        $newStart = now()->addDays(20)->startOfSecond();

        $event = [
            'id' => 'evt_phase', 'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_123', 'cancel_at_period_end' => false, 'current_period_start' => $newStart->timestamp, 'current_period_end' => $newStart->copy()->addMonth()->timestamp,
                'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => 'price_biz']]]],
            ]],
        ];
        $this->stripeWebhook($event)->assertOk()->assertJsonPath('status', 'processed');
        $this->stripeWebhook($event)->assertOk()->assertJsonPath('status', 'already_processed');
        // a different event id for the same state (Stripe may send several) changes nothing more
        $this->stripeWebhook(['id' => 'evt_phase_2'] + $event)->assertOk();

        $subscription->refresh();
        $this->assertSame($this->plan('business')->id, $subscription->plan_id);
        $this->assertNull($subscription->pending_plan_id);
        $this->assertNull($subscription->provider_schedule_id);
        $this->assertSame($newStart->timestamp, $subscription->current_period_start->timestamp);
        $this->assertSame(1, Subscription::where('tenant_id', $this->acme()->id)->count());
        $this->assertSame(1, SubscriptionEvent::where('type', 'plan_changed')->where('to_plan_id', $this->plan('business')->id)->count());
    }

    public function test_withdrawing_the_queued_change_releases_the_provider_schedule(): void
    {
        $this->scheduleBusiness();

        $this->deleteJson('/api/my-subscription/pending-change')->assertOk();

        $subscription = $this->acme()->subscription;
        $this->assertNull($subscription->pending_plan_id);
        $this->assertNull($subscription->provider_schedule_id);
        $this->assertSame($this->plan('pro')->id, $subscription->plan_id);
        $this->assertCount(1, $this->stripePosts('/subscription_schedules/sub_sched_1/release'));
        $this->assertNotNull(SubscriptionEvent::where('type', 'plan_change_canceled')->first());
    }

    public function test_choosing_the_current_plan_again_also_withdraws_it(): void
    {
        $this->scheduleBusiness();

        $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('pro')->id])
            ->assertOk()->assertJsonPath('mode', 'cleared');

        $this->assertNull($this->acme()->subscription->pending_plan_id);
        $this->assertCount(1, $this->stripePosts('/subscription_schedules/sub_sched_1/release'));
    }

    public function test_cancelling_the_subscription_drops_a_queued_change(): void
    {
        $this->scheduleBusiness();
        $this->fakeStripe(['api.stripe.com/v1/subscriptions/sub_123' => Http::response(['id' => 'sub_123', 'cancel_at_period_end' => true])]);

        $this->postJson('/api/my-subscription/cancel')->assertOk();

        $subscription = $this->acme()->subscription;
        $this->assertNull($subscription->pending_plan_id);
        $this->assertSame(Subscription::STATUS_CANCELED, $subscription->status);
        $this->assertNotNull($subscription->current_period_end); // access continues to the end of the paid period
    }

    public function test_a_downgrade_is_queued_the_same_way(): void
    {
        $this->stripeSubscriber('business');
        $this->fakeStripe(['api.stripe.com/v1/prices' => Http::response(['id' => 'price_starter'])]);
        $this->loginAdmin();

        $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('pro')->id])
            ->assertOk()->assertJsonPath('mode', 'scheduled');

        $this->assertSame($this->plan('business')->id, $this->acme()->subscription->plan_id);
        $this->assertSame($this->plan('pro')->id, $this->acme()->subscription->pending_plan_id);
    }

    public function test_a_trial_a_free_plan_and_an_unbilled_subscription_switch_at_once(): void
    {
        $this->fakeStripe();
        $this->loginAdmin();
        $tenant = $this->acme();
        // platform-assigned paid plan: nobody bills it, so there is no paid period to protect
        app(SubscriptionService::class)->assign($tenant, $this->plan('pro'));

        $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('business')->id])
            ->assertOk()->assertJsonPath('mode', 'immediate');
        $this->assertSame($this->plan('business')->id, $tenant->refresh()->subscription->plan_id);
        Http::assertNothingSent();

        // a free plan has nothing paid either
        $tenant->subscription->update(['status' => Subscription::STATUS_TRIALING]);
        $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('starter')->id])
            ->assertOk()->assertJsonPath('mode', 'immediate');
    }

    public function test_an_inactive_plan_cannot_be_scheduled(): void
    {
        $this->stripeSubscriber('pro');
        $this->fakeStripe();
        $this->plan('business')->update(['is_active' => false]);
        $this->loginAdmin();

        $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('business')->id])->assertStatus(422);
        $this->assertNull($this->acme()->subscription->pending_plan_id);
    }

    public function test_when_stripe_is_down_nothing_is_recorded_and_the_admin_can_retry(): void
    {
        $this->stripeSubscriber('pro');
        $this->fakeStripe(['api.stripe.com/v1/subscription_schedules*' => Http::response(['error' => ['message' => 'overloaded']], 503)]);
        $this->loginAdmin();

        $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('business')->id])
            ->assertStatus(503)->assertJsonPath('code', 'billing_unavailable');

        $subscription = $this->acme()->subscription;
        $this->assertNull($subscription->pending_plan_id);
        $this->assertNull($subscription->provider_schedule_id);
        $this->assertNull(SubscriptionEvent::where('type', 'plan_change_scheduled')->first());
    }

    public function test_two_billing_changes_at_once_are_serialised(): void
    {
        $this->stripeSubscriber('pro');
        $this->fakeStripe();
        $this->loginAdmin();

        $lock = Cache::lock('billing:tenant:'.$this->acme()->id, 30);
        $this->assertTrue($lock->get());
        try {
            $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('business')->id])->assertStatus(409);
            $this->assertNull($this->acme()->subscription->pending_plan_id);
        } finally {
            $lock->release();
        }

        $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('business')->id])->assertOk();
    }

    public function test_the_billing_lock_is_re_entrant_within_a_request(): void
    {
        $result = BillingLock::for($this->acme(), fn () => BillingLock::for($this->acme(), fn () => 'inner'));

        $this->assertSame('inner', $result);
    }

    public function test_the_sweep_applies_unbilled_changes_but_never_runs_ahead_of_the_provider(): void
    {
        $tenant = $this->stripeSubscriber('pro');
        $business = $this->plan('business');
        $business->update(['stripe_price_id' => 'price_biz']);
        $subscription = $tenant->subscription;
        $subscription->update(['pending_plan_id' => $business->id, 'pending_change_at' => now()->subHour(), 'pending_requested_at' => now()->subDays(20), 'provider_schedule_id' => 'sub_sched_1']);

        // Stripe first reports the old price (the swap has not happened there yet, so it must not happen here), then the new one
        $start = now()->subHour()->startOfSecond();
        Http::fake(['api.stripe.com/v1/subscriptions/sub_123' => Http::sequence()
            ->push(['id' => 'sub_123', 'items' => ['data' => [['price' => ['id' => 'price_pro']]]]])
            ->push(['id' => 'sub_123', 'current_period_start' => $start->timestamp, 'current_period_end' => $start->copy()->addMonth()->timestamp, 'items' => ['data' => [['price' => ['id' => 'price_biz']]]]]),
        ]);
        $this->assertSame(['applied' => 0, 'waiting' => 1, 'failed' => 0], app(PlanChangeScheduler::class)->applyDue());
        $this->assertSame($this->plan('pro')->id, $subscription->fresh()->plan_id);

        // the webhook was missed: the next sweep sees the new price and mirrors it
        $this->assertSame(['applied' => 1, 'waiting' => 0, 'failed' => 0], app(PlanChangeScheduler::class)->applyDue());
        $this->assertSame($business->id, $subscription->fresh()->plan_id);
        $this->assertNull($subscription->fresh()->pending_plan_id);
        $this->assertSame(['applied' => 0, 'waiting' => 0, 'failed' => 0], app(PlanChangeScheduler::class)->applyDue());

        // a change nobody bills (no provider subscription) is applied locally when due
        $local = $this->acme()->subscription;
        $local->update(['provider_subscription_id' => null, 'billing_provider' => null, 'pending_plan_id' => $this->plan('pro')->id, 'pending_change_at' => now()->subMinute(), 'provider_schedule_id' => null]);
        $this->assertSame(1, app(PlanChangeScheduler::class)->applyDue()['applied']);
        $this->assertSame($this->plan('pro')->id, $local->fresh()->plan_id);
    }

    public function test_the_provider_dropping_the_schedule_clears_it_locally(): void
    {
        $this->scheduleBusiness();

        $this->stripeWebhook([
            'id' => 'evt_released', 'type' => 'subscription_schedule.released',
            'data' => ['object' => ['id' => 'sub_sched_1', 'released_subscription' => 'sub_123']],
        ])->assertOk()->assertJsonPath('status', 'processed');

        $subscription = $this->acme()->subscription;
        $this->assertNull($subscription->pending_plan_id);
        $this->assertSame($this->plan('pro')->id, $subscription->plan_id);
    }
}
