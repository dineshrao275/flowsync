<?php

namespace Tests\Feature;

use App\Billing\PaymentResolver;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** FB-6 — Stripe recurring billing, with the Stripe HTTP API faked. */
class StripeBillingTest extends TestCase
{
    use IsolatesDatabase;

    private const WEBHOOK_SECRET = 'whsec_unit_test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.driver' => 'stripe',
            'payments.gateways.stripe.secret' => 'sk_test_unit',
            'payments.gateways.stripe.key' => 'pk_test_unit',
            'payments.gateways.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        app()->forgetInstance(PaymentResolver::class);
    }

    private function acme(): Tenant
    {
        return Tenant::where('slug', 'acme')->firstOrFail();
    }

    private function pro(): SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', 'pro')->firstOrFail();
    }

    private function loginAdmin(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
    }

    private function signed(array $event): array
    {
        $body = json_encode($event);
        $t = time();

        return [$body, ['Stripe-Signature' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", self::WEBHOOK_SECRET), 'Content-Type' => 'application/json']];
    }

    private function webhook(array $event)
    {
        [$body, $headers] = $this->signed($event);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /** A tenant that already pays through Stripe. */
    private function stripeSubscriber(string $subId = 'sub_123'): Tenant
    {
        $tenant = $this->acme();
        app(SubscriptionService::class)->assign($tenant, $this->pro());
        $tenant->refresh()->subscription->update(['billing_provider' => 'stripe', 'provider_subscription_id' => $subId, 'status' => 'active']);

        return $tenant->refresh();
    }

    public function test_checkout_builds_a_subscription_session_and_remembers_the_customer_and_price(): void
    {
        $this->loginAdmin();
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/prices' => Http::response(['id' => 'price_1']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
        ]);

        $response = $this->postJson('/api/billing/checkout', ['plan_id' => $this->pro()->id])->assertCreated();

        $response->assertJsonPath('session.redirect_url', 'https://checkout.stripe.com/c/pay/cs_test_1');
        $this->assertSame('cus_1', $this->acme()->billing_customer_id);
        $this->assertSame('price_1', $this->pro()->stripe_price_id);

        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/checkout/sessions')) {
                return false;
            }
            $d = $r->data();

            return $d['mode'] === 'subscription'
                && $d['customer'] === 'cus_1'
                && $d['line_items[0][price]'] === 'price_1'
                && $d['subscription_data[metadata][tenant_id]'] === (string) $this->acme()->id
                && str_contains($d['success_url'], '{CHECKOUT_SESSION_ID}')
                && str_contains($d['success_url'], 'payment_id=');
        });
    }

    public function test_the_price_is_reused_until_the_plan_amount_changes(): void
    {
        $this->loginAdmin();
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/prices' => Http::sequence()->push(['id' => 'price_1'])->push(['id' => 'price_2']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_x', 'url' => 'https://x']),
        ]);

        $this->postJson('/api/billing/checkout', ['plan_id' => $this->pro()->id])->assertCreated();
        $this->postJson('/api/billing/checkout', ['plan_id' => $this->pro()->id])->assertCreated();
        $this->assertSame('price_1', $this->pro()->stripe_price_id);

        $this->pro()->update(['price_cents' => $this->pro()->price_cents + 100]);
        $this->postJson('/api/billing/checkout', ['plan_id' => $this->pro()->id])->assertCreated();
        $this->assertSame('price_2', $this->pro()->fresh()->stripe_price_id);
    }

    public function test_a_returning_checkout_session_activates_the_plan_and_stores_the_stripe_subscription(): void
    {
        $this->loginAdmin();
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/prices' => Http::response(['id' => 'price_1']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://x']),
        ]);
        $paymentId = $this->postJson('/api/billing/checkout', ['plan_id' => $this->pro()->id])->json('payment.id');

        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_test_1*' => Http::response([
                'id' => 'cs_test_1', 'status' => 'complete', 'payment_status' => 'paid',
                'client_reference_id' => (string) $paymentId, 'amount_total' => 2900, 'currency' => 'usd',
                'payment_intent' => 'pi_1', 'customer' => 'cus_1',
                'subscription' => ['id' => 'sub_1', 'current_period_end' => now()->addMonth()->timestamp],
            ]),
        ]);

        $this->postJson('/api/billing/verify', ['payment_id' => $paymentId, 'provider_payment_id' => 'cs_test_1'])
            ->assertOk()->assertJsonPath('payment.status', Payment::STATUS_COMPLETED);

        $subscription = $this->acme()->subscription;
        $this->assertSame('sub_1', $subscription->provider_subscription_id);
        $this->assertSame('stripe', $subscription->billing_provider);
        $this->assertSame($this->pro()->id, $subscription->plan_id);
    }

    public function test_a_session_created_for_another_payment_is_refused(): void
    {
        $this->loginAdmin();
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/prices' => Http::response(['id' => 'price_1']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://x']),
        ]);
        $paymentId = $this->postJson('/api/billing/checkout', ['plan_id' => $this->pro()->id])->json('payment.id');

        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_paid_elsewhere*' => Http::response([
                'id' => 'cs_paid_elsewhere', 'status' => 'complete', 'payment_status' => 'paid',
                'client_reference_id' => '99999', 'subscription' => 'sub_other',
            ]),
        ]);

        $this->postJson('/api/billing/verify', ['payment_id' => $paymentId, 'provider_payment_id' => 'cs_paid_elsewhere'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'This checkout session does not belong to the payment being verified.']);
        $this->assertNull($this->acme()->subscription?->provider_subscription_id);
    }

    public function test_an_unpaid_session_does_not_activate_anything(): void
    {
        $this->loginAdmin();
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/prices' => Http::response(['id' => 'price_1']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://x']),
        ]);
        $paymentId = $this->postJson('/api/billing/checkout', ['plan_id' => $this->pro()->id])->json('payment.id');
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_1*' => Http::response(['id' => 'cs_test_1', 'status' => 'open', 'payment_status' => 'unpaid', 'client_reference_id' => (string) $paymentId])]);

        $this->postJson('/api/billing/verify', ['payment_id' => $paymentId, 'provider_payment_id' => 'cs_test_1'])->assertStatus(422);
        $this->assertSame(Payment::STATUS_FAILED, Payment::find($paymentId)->status);
    }

    public function test_a_paid_renewal_invoice_extends_the_period_and_records_one_payment(): void
    {
        $tenant = $this->stripeSubscriber();
        $period = now()->addMonth()->startOfSecond();
        $event = [
            'id' => 'evt_inv_1', 'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_1', 'subscription' => 'sub_123', 'billing_reason' => 'subscription_cycle',
                'amount_paid' => 2900, 'currency' => 'usd', 'payment_intent' => 'pi_9',
                'lines' => ['data' => [['period' => ['end' => $period->timestamp]]]],
            ]],
        ];

        $this->webhook($event)->assertOk()->assertJsonPath('status', 'processed');
        $this->webhook($event)->assertOk()->assertJsonPath('status', 'already_processed');

        $this->assertSame(1, Payment::where('provider_order_id', 'in_1')->count());
        $this->assertSame(Payment::STATUS_COMPLETED, Payment::where('provider_order_id', 'in_1')->first()->status);
        $this->assertSame($period->timestamp, $tenant->refresh()->subscription->current_period_end->timestamp);
        $this->assertSame('active', $tenant->subscription->status);
    }

    public function test_the_subscription_creation_invoice_is_left_to_checkout_completion(): void
    {
        $this->stripeSubscriber();

        $this->webhook([
            'id' => 'evt_inv_create', 'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_create', 'subscription' => 'sub_123', 'billing_reason' => 'subscription_create', 'amount_paid' => 2900, 'currency' => 'usd']],
        ])->assertOk()->assertJsonPath('status', 'ignored');

        $this->assertSame(0, Payment::where('provider_order_id', 'in_create')->count());
    }

    public function test_a_failed_renewal_flags_the_subscription_past_due(): void
    {
        $tenant = $this->stripeSubscriber();

        $this->webhook([
            'id' => 'evt_fail', 'type' => 'invoice.payment_failed',
            'data' => ['object' => ['id' => 'in_f', 'subscription' => 'sub_123', 'billing_reason' => 'subscription_cycle', 'amount_due' => 2900, 'currency' => 'usd']],
        ])->assertOk();

        $this->assertSame('past_due', $tenant->refresh()->subscription->status);
    }

    public function test_stripe_cancelling_the_subscription_cancels_it_locally(): void
    {
        $tenant = $this->stripeSubscriber();

        $this->webhook([
            'id' => 'evt_del', 'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => 'sub_123', 'metadata' => ['tenant_id' => (string) $tenant->id]]],
        ])->assertOk();

        $this->assertSame('canceled', $tenant->refresh()->subscription->status);
    }

    public function test_cancel_at_period_end_in_stripe_turns_auto_renew_off(): void
    {
        $tenant = $this->stripeSubscriber();

        $this->webhook([
            'id' => 'evt_upd', 'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_123', 'cancel_at_period_end' => true, 'current_period_end' => now()->addDays(10)->timestamp, 'metadata' => []]],
        ])->assertOk();

        $this->assertFalse((bool) $tenant->refresh()->subscription->auto_renew);
    }

    public function test_cancelling_in_the_app_stops_renewal_at_stripe_first(): void
    {
        $this->stripeSubscriber();
        $this->loginAdmin();
        Http::fake(['api.stripe.com/v1/subscriptions/sub_123' => Http::response(['id' => 'sub_123'])]);

        $this->postJson('/api/my-subscription/cancel')->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/subscriptions/sub_123') && $r->data()['cancel_at_period_end'] === 'true');
        $this->assertSame('canceled', $this->acme()->subscription->status);
    }

    public function test_an_existing_stripe_subscriber_changes_plan_in_place_instead_of_paying_twice(): void
    {
        $this->stripeSubscriber();
        $business = SubscriptionPlan::where('slug', 'business')->firstOrFail();
        $this->loginAdmin();
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/prices' => Http::response(['id' => 'price_biz']),
            'api.stripe.com/v1/subscriptions/sub_123' => Http::sequence()
                ->push(['id' => 'sub_123', 'items' => ['data' => [['id' => 'si_1']]]])
                ->push(['id' => 'sub_123']),
        ]);

        $this->postJson('/api/billing/checkout', ['plan_id' => $business->id])->assertOk()->assertJsonPath('changed', true);

        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkout/sessions'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/subscriptions/sub_123')
            && ($r->data()['items[0][price]'] ?? null) === 'price_biz');
        $this->assertSame($business->id, $this->acme()->subscription->plan_id);
    }

    public function test_the_billing_portal_url_comes_from_stripe_and_needs_billing_rights(): void
    {
        $this->loginAdmin();
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.com/p/session/abc']),
        ]);

        $this->postJson('/api/billing/portal')->assertOk()->assertJsonPath('url', 'https://billing.stripe.com/p/session/abc');

        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'viewer@flowsync.test', 'password' => 'password'])->assertOk();
        $this->postJson('/api/billing/portal')->assertForbidden();
    }

    public function test_events_for_an_unknown_subscription_are_acknowledged_without_effect(): void
    {
        $this->webhook([
            'id' => 'evt_unknown', 'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_x', 'subscription' => 'sub_nobody', 'billing_reason' => 'subscription_cycle', 'amount_paid' => 1, 'currency' => 'usd']],
        ])->assertOk()->assertJsonPath('status', 'subscription_not_found');

        $this->assertSame(0, PaymentEvent::where('provider_event_id', 'evt_unknown')->count());
    }
}
