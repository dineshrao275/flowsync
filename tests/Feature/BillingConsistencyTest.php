<?php

namespace Tests\Feature;

use App\Billing\PaymentMethods;
use App\Billing\PaymentResolver;
use App\Billing\PaymentService;
use App\Billing\Reconciliation\BillingReconciler;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Concerns\StripeTestHelpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Tenant creation ↔ Stripe: a customer and a trial subscription exist from day one, a card is mandatory, nothing is free by
 * accident, and a crash or provider failure at any step is repaired (or compensated) instead of leaving the two sides apart.
 */
class BillingConsistencyTest extends TestCase
{
    use IsolatesDatabase;
    use StripeTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStripe();
        config(['onboarding.require_card_for_trial' => true]);
        $this->loginSuperAdmin();
    }

    /** Stripe answers just enough for catalog + customer + subscription calls. */
    private function stripe(array $overrides = []): void
    {
        Http::swap(new Factory); // a later fake must REPLACE the earlier one (first match wins otherwise)
        Http::fake($overrides + [
            'api.stripe.com/v1/products*' => Http::response(['id' => 'prod_x']),
            'api.stripe.com/v1/prices*' => Http::response(['id' => 'price_x']),
            'api.stripe.com/v1/customers/*' => Http::response(['id' => 'cus_new', 'invoice_settings' => []]),
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_new']),
            'api.stripe.com/v1/subscriptions/*' => Http::response(['id' => 'sub_new', 'status' => 'trialing', 'current_period_end' => now()->addDays(14)->timestamp]),
            'api.stripe.com/v1/subscriptions' => Http::response(['id' => 'sub_new', 'status' => 'trialing', 'current_period_end' => now()->addDays(14)->timestamp]),
        ]);
    }

    /** @return array<string, mixed> */
    private function intake(array $over = []): array
    {
        return $over + [
            'industry' => 'Software', 'company_size' => '11-50', 'country' => 'in',
            'billing_email' => 'billing@new.test', 'contact_name' => 'Casey', 'contact_email' => 'casey@new.test',
            'admin_name' => 'Dana', 'admin_email' => 'dana@new.test',
            'plan_id' => SubscriptionPlan::where('slug', 'pro')->value('id'), 'start_trial' => true,
        ];
    }

    private function createTenant(array $over = [], ?string $paymentMethod = null): Tenant
    {
        $id = $this->postJson('/api/tenants', ['name' => 'New Co', 'slug' => 'new-co'])->assertCreated()->json('tenant.id');
        $this->putJson("/api/tenants/{$id}/intake", $this->intake($over) + ($paymentMethod ? ['payment_method' => $paymentMethod] : []))->assertOk();
        $this->postJson("/api/tenants/{$id}/intake/submit", ['admin_password' => 'password123', 'admin_password_confirmation' => 'password123'])->assertStatus(202);

        return Tenant::findOrFail($id);
    }

    /** @return list<HttpRequest> */
    private function sent(string $method, string $endsWith): array
    {
        $found = [];
        Http::assertSent(function (HttpRequest $r) use ($method, $endsWith, &$found) {
            if ($r->method() === $method && str_ends_with(parse_url($r->url(), PHP_URL_PATH), $endsWith)) {
                $found[] = $r;
            }

            return true;
        });

        return $found;
    }

    // ------------------------------------------------------------------ creation

    public function test_creating_a_tenant_creates_the_stripe_customer_and_a_trial_subscription(): void
    {
        $this->stripe();

        $tenant = $this->createTenant();

        $this->assertSame('cus_new', $tenant->billing_customer_id);
        $subscription = $tenant->subscription()->first();
        $this->assertSame(Subscription::STATUS_TRIALING, $subscription->status);
        $this->assertSame('sub_new', $subscription->provider_subscription_id);
        $this->assertSame('trial', $tenant->status);

        $created = $this->sent('POST', '/v1/subscriptions');
        $this->assertCount(1, $created);
        $this->assertSame('14', (string) $created[0]['trial_period_days']);
        // No card yet: Stripe must end the subscription at trial end rather than invoice a customer who cannot pay.
        $this->assertSame('cancel', $created[0]['trial_settings']['end_behavior']['missing_payment_method'] ?? $created[0]->data()['trial_settings[end_behavior][missing_payment_method]'] ?? null);
        $this->assertArrayNotHasKey('default_payment_method', $created[0]->data());
    }

    public function test_a_trial_without_a_card_is_held_at_billing_until_one_is_added(): void
    {
        $this->stripe();
        $tenant = $this->createTenant();

        $this->assertTrue($tenant->needsPaymentMethod());

        $this->postJson('/api/impersonate', ['user_id' => 1, 'tenant_id' => $tenant->id, 'reason' => 'verify card gate', 'mode' => 'read_only'])->assertOk();
        $this->getJson('/api/workspaces')->assertStatus(402)->assertJsonPath('code', 'payment_method_required');
        $this->getJson('/api/my-subscription')->assertOk()->assertJsonPath('billing.payment_method_required', true);
    }

    public function test_adding_a_card_opens_the_trial_and_attaches_the_card_to_the_stripe_subscription(): void
    {
        $this->stripe([
            'api.stripe.com/v1/payment_methods*' => Http::response(['data' => [['id' => 'pm_1', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 1, 'exp_year' => 2099]]]]),
        ]);
        $tenant = $this->createTenant();
        $this->assertTrue($tenant->needsPaymentMethod());

        app(PaymentMethods::class)->makeDefault($tenant->refresh(), 'pm_1');

        $this->assertFalse($tenant->refresh()->needsPaymentMethod());
        $attached = collect($this->sent('POST', '/v1/subscriptions/sub_new'))->first(fn ($r) => ($r->data()['default_payment_method'] ?? null) === 'pm_1');
        $this->assertNotNull($attached, 'the card must be attached to the existing Stripe subscription');
    }

    public function test_a_card_given_at_creation_goes_on_the_stripe_subscription_and_nothing_is_held(): void
    {
        $this->stripe();

        $tenant = $this->createTenant([], 'pm_card');

        $created = $this->sent('POST', '/v1/subscriptions');
        $this->assertSame('pm_card', $created[0]->data()['default_payment_method']);
        $this->assertFalse($tenant->needsPaymentMethod());
    }

    public function test_a_paid_plan_without_a_trial_or_payment_is_not_a_free_month(): void
    {
        $this->stripe();

        $tenant = $this->createTenant(['start_trial' => false]);

        $subscription = $tenant->subscription()->first();
        $this->assertSame(Subscription::STATUS_EXPIRED, $subscription->status);
        $this->assertNull($subscription->current_period_end);
        $this->assertSame('expired', $tenant->status);
        $this->assertSame('cus_new', $tenant->billing_customer_id); // the customer exists so they can pay straight away
        $this->assertCount(0, $this->sent('POST', '/v1/subscriptions'));
    }

    public function test_a_free_plan_stays_active_and_never_asks_for_a_card(): void
    {
        $this->stripe();
        $free = SubscriptionPlan::where('price_cents', 0)->where('is_active', true)->first();
        $this->assertNotNull($free, 'seeded catalog has a free plan');

        $tenant = $this->createTenant(['plan_id' => $free->id, 'start_trial' => false]);

        $this->assertSame(Subscription::STATUS_ACTIVE, $tenant->subscription()->first()->status);
        $this->assertFalse($tenant->needsPaymentMethod());
    }

    // ------------------------------------------------------- failure + repair

    public function test_a_stripe_outage_at_creation_does_not_lose_the_tenant_and_reconciliation_repairs_it(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'down']], 500)]);

        $tenant = $this->createTenant();

        $this->assertTrue($tenant->isProvisioned());
        $this->assertNull($tenant->billing_customer_id);
        $subscription = $tenant->subscription()->first();
        $this->assertSame(Subscription::STATUS_TRIALING, $subscription->status);
        $this->assertNull($subscription->provider_subscription_id);
        $this->assertArrayHasKey('trial', $tenant->settings['billing']['sync_errors'] ?? []);

        // Stripe is back: one reconciliation run creates the customer and the trial subscription.
        $this->stripe();
        $result = app(BillingReconciler::class)->run($tenant);

        $tenant->refresh();
        $this->assertSame('cus_new', $tenant->billing_customer_id);
        $this->assertSame('sub_new', $tenant->subscription()->first()->provider_subscription_id);
        $this->assertSame(1, $result['customers']);
        $this->assertSame(1, $result['trials']);
        $this->assertArrayNotHasKey('trial', $tenant->settings['billing']['sync_errors'] ?? []);
    }

    public function test_reconciliation_is_idempotent(): void
    {
        $this->stripe();
        $tenant = $this->createTenant();

        $first = app(BillingReconciler::class)->run($tenant);
        $second = app(BillingReconciler::class)->run($tenant);

        $this->assertSame(0, $second['customers'] + $second['trials']);
        $this->assertCount(1, $this->sent('POST', '/v1/subscriptions'));
        $this->assertSame(0, $first['failed']);
    }

    public function test_a_provider_subscription_that_cannot_be_recorded_is_cancelled_again(): void
    {
        $this->stripe(['api.stripe.com/v1/subscriptions/*' => Http::response(['id' => 'sub_new', 'status' => 'canceled'])]);
        $tenant = $this->acme();
        app(SubscriptionService::class)->startTrial($tenant, $this->plan('pro'), 14);
        $tenant->refresh();

        // The local write fails after Stripe already created the subscription.
        Subscription::updating(function () {
            throw new \RuntimeException('database went away');
        });

        try {
            app(PaymentService::class)->startProviderTrial($tenant, 'pm_1', 14, $this->plan('pro')->product ?? 'suite');
            $this->fail('expected the local failure to surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('database went away', $e->getMessage());
        } finally {
            Subscription::flushEventListeners();
        }

        $deleted = collect(Http::recorded())->first(fn ($pair) => $pair[0]->method() === 'DELETE' && str_ends_with($pair[0]->url(), '/v1/subscriptions/sub_new'));
        $this->assertNotNull($deleted, 'the orphan Stripe subscription must be cancelled (compensation)');
    }

    public function test_reconciliation_mirrors_what_stripe_says(): void
    {
        $this->stripe();
        $tenant = $this->createTenant([], 'pm_card');
        $subscription = $tenant->subscription()->first();

        // The trial converted and was paid at Stripe while our webhook was lost.
        $this->stripe(['api.stripe.com/v1/subscriptions/sub_new' => Http::response(['id' => 'sub_new', 'status' => 'active', 'current_period_end' => now()->addDays(30)->timestamp])]);
        app(BillingReconciler::class)->run($tenant);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->refresh()->status);
        $this->assertSame('active', $tenant->refresh()->status);

        // It then failed a renewal.
        $this->stripe(['api.stripe.com/v1/subscriptions/sub_new' => Http::response(['id' => 'sub_new', 'status' => 'past_due'])]);
        app(BillingReconciler::class)->run($tenant);
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->refresh()->status);

        // And was cancelled in the Stripe dashboard.
        $this->stripe(['api.stripe.com/v1/subscriptions/sub_new' => Http::response(['id' => 'sub_new', 'status' => 'canceled'])]);
        app(BillingReconciler::class)->run($tenant);
        $this->assertSame(Subscription::STATUS_CANCELED, $subscription->refresh()->status);
    }

    // ---------------------------------------------------------------- webhooks

    private function event(string $type, array $object, ?string $id = null): array
    {
        return ['id' => $id ?? 'evt_'.uniqid(), 'type' => $type, 'data' => ['object' => $object]];
    }

    public function test_customer_created_event_restores_a_lost_customer_id(): void
    {
        $this->stripe();
        $tenant = $this->createTenant();
        $tenant->forceFill(['billing_customer_id' => null])->save();

        $this->stripeWebhook($this->event('customer.created', ['id' => 'cus_new', 'metadata' => ['tenant_id' => (string) $tenant->id]]))
            ->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame('cus_new', $tenant->refresh()->billing_customer_id);
    }

    public function test_subscription_events_keep_status_in_step_with_stripe(): void
    {
        $this->stripe();
        $tenant = $this->createTenant([], 'pm_card');
        $sub = $tenant->subscription()->first();
        $object = fn (string $status, array $extra = []) => $extra + ['id' => 'sub_new', 'customer' => 'cus_new', 'status' => $status, 'metadata' => ['tenant_id' => (string) $tenant->id], 'current_period_end' => now()->addDays(30)->timestamp];

        // trial converted
        $this->stripeWebhook($this->event('customer.subscription.updated', $object('active')))->assertOk();
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->refresh()->status);

        // payment failed → past due
        $this->stripeWebhook($this->event('invoice.payment_failed', ['id' => 'in_1', 'subscription' => 'sub_new', 'customer' => 'cus_new', 'amount_due' => 100, 'currency' => 'usd']))->assertOk();
        $this->assertSame(Subscription::STATUS_PAST_DUE, $sub->refresh()->status);

        // paid again → active
        $this->stripeWebhook($this->event('invoice.paid', ['id' => 'in_2', 'subscription' => 'sub_new', 'customer' => 'cus_new', 'amount_paid' => 4900, 'currency' => 'usd', 'billing_reason' => 'subscription_cycle', 'lines' => ['data' => [['period' => ['start' => time(), 'end' => now()->addDays(30)->timestamp]]]]]))->assertOk();
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->refresh()->status);

        // cancelled
        $this->stripeWebhook($this->event('customer.subscription.deleted', $object('canceled')))->assertOk();
        $this->assertSame(Subscription::STATUS_CANCELED, $sub->refresh()->status);
    }

    public function test_a_redelivered_event_is_processed_once(): void
    {
        $this->stripe();
        $tenant = $this->createTenant([], 'pm_card');
        $event = $this->event('invoice.paid', ['id' => 'in_9', 'subscription' => 'sub_new', 'customer' => 'cus_new', 'amount_paid' => 4900, 'currency' => 'usd', 'billing_reason' => 'subscription_cycle', 'lines' => ['data' => [['period' => ['start' => time(), 'end' => now()->addDays(30)->timestamp]]]]], 'evt_same');

        $this->stripeWebhook($event)->assertOk()->assertJsonPath('status', 'processed');
        $this->stripeWebhook($event)->assertOk()->assertJsonPath('status', 'already_processed');

        $this->assertSame(1, Payment::where('tenant_id', $tenant->id)->where('provider_order_id', 'in_9')->count());
    }

    public function test_a_webhook_with_a_bad_signature_changes_nothing(): void
    {
        $this->stripe();
        $tenant = $this->createTenant([], 'pm_card');

        $this->call('POST', '/api/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad', 'CONTENT_TYPE' => 'application/json'], json_encode($this->event('customer.subscription.deleted', ['id' => 'sub_new', 'customer' => 'cus_new', 'metadata' => ['tenant_id' => (string) $tenant->id]])))
            ->assertStatus(403);

        $this->assertSame(Subscription::STATUS_TRIALING, $tenant->subscription()->first()->status);
    }

    // ----------------------------------------------------------------- Razorpay

    private function razorpay(): void
    {
        config([
            'payments.driver' => 'razorpay',
            'payments.gateways.razorpay.key' => 'rzp_test',
            'payments.gateways.razorpay.secret' => 'rzp_secret',
            'payments.gateways.razorpay.webhook_secret' => 'rzp_whsec',
        ]);
        app()->forgetInstance(PaymentResolver::class);
    }

    private function razorpayWebhook(array $event, ?string $eventId = null)
    {
        $body = json_encode($event);
        $headers = ['HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'rzp_whsec'), 'CONTENT_TYPE' => 'application/json'];
        if ($eventId) {
            $headers['HTTP_X_RAZORPAY_EVENT_ID'] = $eventId;
        }

        return $this->call('POST', '/api/webhooks/razorpay', [], [], [], $headers, $body);
    }

    private function pendingRazorpayPayment(int $amount = 4900, string $order = 'order_A'): Payment
    {
        return Payment::create([
            'tenant_id' => $this->acme()->id, 'provider' => 'razorpay', 'amount_cents' => $amount, 'currency' => 'inr',
            'status' => Payment::STATUS_PENDING, 'idempotency_key' => 'idem_'.uniqid(), 'provider_order_id' => $order,
            'metadata' => ['plan_id' => $this->plan('pro')->id],
        ]);
    }

    public function test_razorpay_verification_rejects_a_payment_that_belongs_to_another_order(): void
    {
        $this->razorpay();
        $payment = $this->pendingRazorpayPayment();
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'pay_other', 'status' => 'captured', 'order_id' => 'order_OTHER', 'amount' => 4900, 'currency' => 'INR'])]);

        $this->expectException(\RuntimeException::class);
        try {
            app(PaymentService::class)->verifyPayment($this->acme(), $payment, 'pay_other');
        } finally {
            $this->assertNotSame(Payment::STATUS_COMPLETED, $payment->refresh()->status);
            $this->assertNotSame('active', $this->acme()->refresh()->subscription?->status === 'active' && $this->acme()->subscription->plan_id === $this->plan('pro')->id ? 'active' : 'x');
        }
    }

    public function test_razorpay_verification_rejects_an_underpayment(): void
    {
        $this->razorpay();
        $payment = $this->pendingRazorpayPayment(9900);
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'pay_cheap', 'status' => 'captured', 'order_id' => 'order_A', 'amount' => 100, 'currency' => 'INR'])]);

        $this->expectException(\RuntimeException::class);
        app(PaymentService::class)->verifyPayment($this->acme(), $payment, 'pay_cheap');
    }

    public function test_the_webhook_and_the_browser_callback_confirm_a_razorpay_payment_once(): void
    {
        $this->razorpay();
        $payment = $this->pendingRazorpayPayment();
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'pay_1', 'status' => 'captured', 'order_id' => 'order_A', 'amount' => 4900, 'currency' => 'INR'])]);

        $this->razorpayWebhook(['event' => 'payment.captured', 'account_id' => 'acc', 'created_at' => 1, 'payload' => ['payment' => ['entity' => ['id' => 'pay_1', 'order_id' => 'order_A', 'amount' => 4900, 'currency' => 'INR', 'notes' => []]]]])->assertOk();
        $again = app(PaymentService::class)->verifyPayment($this->acme(), $payment->refresh(), 'pay_1');

        $this->assertSame(Payment::STATUS_COMPLETED, $again->status);
        $this->assertSame(1, Invoice::where('payment_id', $payment->id)->count());
    }

    public function test_two_razorpay_events_in_the_same_second_are_both_processed(): void
    {
        $this->razorpay();
        $a = $this->pendingRazorpayPayment(4900, 'order_A');
        $b = $this->pendingRazorpayPayment(4900, 'order_B');

        foreach (['A' => 'pay_a', 'B' => 'pay_b'] as $order => $pay) {
            $this->razorpayWebhook(['event' => 'payment.captured', 'account_id' => 'acc', 'created_at' => 1700000000, 'payload' => ['payment' => ['entity' => ['id' => $pay, 'order_id' => "order_{$order}", 'amount' => 4900, 'currency' => 'INR', 'notes' => []]]]])
                ->assertOk()->assertJsonPath('status', 'processed');
        }

        $this->assertSame(Payment::STATUS_COMPLETED, $a->refresh()->status);
        $this->assertSame(Payment::STATUS_COMPLETED, $b->refresh()->status);
    }

    public function test_a_razorpay_refund_event_finds_the_payment_it_belongs_to(): void
    {
        $this->razorpay();
        $payment = $this->pendingRazorpayPayment();
        $payment->update(['status' => Payment::STATUS_COMPLETED, 'provider_payment_id' => 'pay_1']);

        $this->razorpayWebhook(['event' => 'refund.processed', 'account_id' => 'acc', 'created_at' => 2, 'payload' => ['refund' => ['entity' => ['id' => 'rfnd_1', 'payment_id' => 'pay_1', 'amount' => 4900, 'currency' => 'INR']]]])
            ->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame(Payment::STATUS_REFUNDED, $payment->refresh()->status);
    }

    public function test_razorpay_webhooks_need_a_valid_signature(): void
    {
        $this->razorpay();

        $this->call('POST', '/api/webhooks/razorpay', [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => 'nope', 'CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(403);
    }

    public function test_reconciliation_settles_a_razorpay_payment_whose_webhook_was_lost(): void
    {
        $this->razorpay();
        $payment = $this->pendingRazorpayPayment();
        $payment->forceFill(['created_at' => now()->subHour()])->save();
        Http::fake([
            'api.razorpay.com/v1/orders/order_A/payments' => Http::response(['items' => [['id' => 'pay_1', 'status' => 'captured', 'amount' => 4900, 'currency' => 'INR']]]),
            'api.razorpay.com/v1/payments/pay_1' => Http::response(['id' => 'pay_1', 'status' => 'captured', 'order_id' => 'order_A', 'amount' => 4900, 'currency' => 'INR']),
        ]);

        $result = app(BillingReconciler::class)->run();

        $this->assertSame(1, $result['payments']);
        $this->assertSame(Payment::STATUS_COMPLETED, $payment->refresh()->status);
    }

    public function test_reconciliation_closes_abandoned_checkouts(): void
    {
        $this->razorpay();
        $payment = $this->pendingRazorpayPayment();
        $payment->forceFill(['created_at' => now()->subDays(2)])->save();
        Http::fake(['api.razorpay.com/*' => Http::response(['items' => []])]);

        app(BillingReconciler::class)->run();

        $this->assertSame(Payment::STATUS_FAILED, $payment->refresh()->status);
    }

    public function test_a_failed_checkout_leaves_no_pending_payment_behind(): void
    {
        $this->loginAdmin();
        Log::spy();
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->postJson('/api/billing/checkout', ['plan_id' => $this->plan('pro')->id])->assertStatus(503);

        $this->assertSame(0, Payment::where('status', Payment::STATUS_PENDING)->count());
    }
}
