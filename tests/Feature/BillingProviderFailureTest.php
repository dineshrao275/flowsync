<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\StripeTestHelpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Stripe failing, a plan being retired and two admins clicking at once: nothing half-applies, nobody is charged twice. */
class BillingProviderFailureTest extends TestCase
{
    use IsolatesDatabase;
    use StripeTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStripe();
        $this->loginAdmin();
    }

    public function test_checkout_while_stripe_is_down_answers_503_and_leaves_no_pending_payment(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Internal error']], 500)]);

        $this->postJson('/api/billing/checkout', ['plan_id' => $this->plan('pro')->id])
            ->assertStatus(503)->assertJsonPath('code', 'billing_unavailable')
            ->assertJsonMissing(['message' => 'Internal error']); // Stripe's wording never reaches the tenant

        $this->assertSame(0, Payment::count());
        Http::assertSentCount(3); // bounded retries, then give up
    }

    public function test_a_request_stripe_rejects_answers_502_without_retrying(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'No such price', 'code' => 'resource_missing']], 400)]);

        $this->postJson('/api/billing/portal')->assertStatus(502)->assertJsonPath('code', 'billing_unavailable');

        Http::assertSentCount(1); // the customer call is rejected and not retried
    }

    public function test_cancelling_while_stripe_is_down_changes_nothing_locally(): void
    {
        $tenant = $this->stripeSubscriber('pro');
        Http::fake(['api.stripe.com/*' => Http::response([], 503)]);

        $this->postJson('/api/my-subscription/cancel')->assertStatus(503);

        $subscription = $tenant->subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertTrue($subscription->auto_renew); // still billed — exactly what Stripe still believes
    }

    public function test_a_connection_failure_is_retried_and_then_reported_as_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->postJson('/api/billing/checkout', ['plan_id' => $this->plan('pro')->id])->assertStatus(503);
    }

    public function test_a_retired_plan_keeps_serving_its_subscribers_but_cannot_be_chosen(): void
    {
        $tenant = $this->stripeSubscriber('pro');
        $this->plan('pro')->update(['is_active' => false]);
        $this->plan('business')->update(['is_active' => false]);

        // existing subscribers are untouched
        $this->assertTrue($tenant->subscription->refresh()->isActive());
        $this->getJson('/api/my-subscription')->assertOk()->assertJsonPath('subscription.plan.slug', 'pro');

        // nobody can move onto a retired plan, by switch or by checkout
        $this->postJson('/api/my-subscription/switch', ['plan_id' => $this->plan('business')->id])->assertStatus(422);
        $this->postJson('/api/billing/checkout', ['plan_id' => $this->plan('business')->id])->assertStatus(422);
        // and a retired plan is not offered
        $this->assertNotContains('business', collect($this->getJson('/api/plans')->json('plans'))->pluck('slug')->all());
    }

    public function test_two_checkouts_at_once_cannot_both_start(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'x', 'url' => 'https://x'])]);
        $lock = Cache::lock('billing:tenant:'.$this->acme()->id, 30);
        $this->assertTrue($lock->get());

        try {
            $this->postJson('/api/billing/checkout', ['plan_id' => $this->plan('pro')->id])->assertStatus(409);
            $this->assertSame(0, Payment::count());
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_the_same_checkout_request_twice_does_not_create_two_payments(): void
    {
        Http::fake([
            'api.stripe.com/v1/customers*' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/products*' => Http::response(['id' => 'prod_1']),
            'api.stripe.com/v1/prices*' => Http::response(['id' => 'price_1']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/cs_1']),
        ]);
        $key = 'client-key-1';

        $first = $this->postJson('/api/billing/checkout', ['plan_id' => $this->plan('pro')->id, 'idempotency_key' => $key])->assertCreated();
        $second = $this->postJson('/api/billing/checkout', ['plan_id' => $this->plan('pro')->id, 'idempotency_key' => $key])->assertCreated();

        $this->assertSame($first->json('payment.id'), $second->json('payment.id'));
        $this->assertSame(1, Payment::count());
    }

    public function test_a_second_stripe_subscription_is_never_created_for_a_tenant_that_already_pays(): void
    {
        $tenant = $this->stripeSubscriber('pro');
        Http::fake([
            'api.stripe.com/v1/customers*' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/products*' => Http::response(['id' => 'prod_1']),
            'api.stripe.com/v1/prices*' => Http::response(['id' => 'price_biz']),
            'api.stripe.com/v1/subscriptions/sub_123' => Http::response(['id' => 'sub_123', 'items' => ['data' => [['id' => 'si_1', 'price' => ['id' => 'price_pro']]]]]),
            'api.stripe.com/v1/subscription_schedules*' => Http::response(['id' => 'sub_sched_1', 'phases' => [['start_date' => 1, 'end_date' => 2, 'items' => [['price' => 'price_pro']]]]]),
        ]);

        $this->postJson('/api/billing/checkout', ['plan_id' => $this->plan('business')->id])->assertOk()->assertJsonPath('mode', 'scheduled');

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/checkout/sessions') || ($r->method() === 'POST' && str_ends_with($r->url(), '/v1/subscriptions')));
        $this->assertSame(1, Subscription::where('tenant_id', $tenant->id)->count());
    }
}
