<?php

namespace Tests\Feature;

use App\Billing\Gateways\FakePaymentGateway;
use App\Billing\Gateways\RazorpayGateway;
use App\Billing\Gateways\StripeGateway;
use App\Billing\PaymentResolver;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use IsolatesDatabase;

    private FakePaymentGateway $fakeGateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway = new FakePaymentGateway;
        app(PaymentResolver::class)->swapFake($this->fakeGateway);
    }

    private function acme(): Tenant
    {
        return Tenant::where('slug', 'acme')->firstOrFail();
    }

    private function proPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', 'pro')->firstOrFail();
    }

    public function test_non_admin_cannot_initiate_checkout(): void
    {
        $this->loginAs('viewer@flowsync.test');

        $this->postJson('/api/billing/checkout', [
            'plan_id' => $this->proPlan()->id,
        ])->assertForbidden();
    }

    public function test_admin_can_initiate_checkout_session(): void
    {
        $this->loginAs('admin@flowsync.test');
        $plan = $this->proPlan();

        $response = $this->postJson('/api/billing/checkout', [
            'plan_id' => $plan->id,
            'currency' => 'usd',
        ]);

        $response->assertCreated()
            ->assertJsonPath('payment.status', Payment::STATUS_PENDING)
            ->assertJsonPath('payment.amount_cents', $plan->price_cents)
            ->assertJsonPath('session.provider', 'mock');

        $this->assertTrue(Payment::where('tenant_id', $this->acme()->id)
            ->where('status', Payment::STATUS_PENDING)
            ->where('amount_cents', $plan->price_cents)
            ->exists());

        $this->assertTrue(PaymentEvent::where('type', PaymentEvent::TYPE_CHECKOUT_CREATED)->exists());
    }

    public function test_idempotent_checkout_avoids_duplicate_payments(): void
    {
        $this->loginAs('admin@flowsync.test');
        $plan = $this->proPlan();
        $idempotencyKey = 'idem_key_test_123';

        $res1 = $this->postJson('/api/billing/checkout', [
            'plan_id' => $plan->id,
            'idempotency_key' => $idempotencyKey,
        ])->assertCreated();

        $paymentId1 = $res1->json('payment.id');

        // Calling checkout again with same idempotency key returns existing payment
        $res2 = $this->postJson('/api/billing/checkout', [
            'plan_id' => $plan->id,
            'idempotency_key' => $idempotencyKey,
        ])->assertCreated();

        $paymentId2 = $res2->json('payment.id');
        $this->assertSame($paymentId1, $paymentId2);

        $this->assertSame(1, Payment::where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_payment_verification_activates_subscription(): void
    {
        $this->loginAs('admin@flowsync.test');
        $plan = $this->proPlan();

        $checkoutRes = $this->postJson('/api/billing/checkout', [
            'plan_id' => $plan->id,
        ])->assertCreated();

        $paymentId = $checkoutRes->json('payment.id');

        $verifyRes = $this->postJson('/api/billing/verify', [
            'payment_id' => $paymentId,
            'provider_payment_id' => 'pi_test_verified_1',
        ])->assertOk();

        $verifyRes->assertJsonPath('payment.status', Payment::STATUS_COMPLETED);

        // Assert payment record updated
        $payment = Payment::find($paymentId);
        $this->assertSame(Payment::STATUS_COMPLETED, $payment->status);
        $this->assertSame('pi_test_verified_1', $payment->provider_payment_id);

        // Assert tenant subscription activated and updated to pro plan
        $acme = $this->acme()->fresh();
        $this->assertNotNull($acme->subscription);
        $this->assertSame($plan->id, $acme->subscription->plan_id);
        $this->assertSame('active', $acme->subscription->status);

        // Assert payment event logged
        $this->assertTrue(PaymentEvent::where('payment_id', $paymentId)
            ->where('type', PaymentEvent::TYPE_PAYMENT_SUCCEEDED)
            ->exists());
    }

    public function test_billing_history_lists_tenant_payments(): void
    {
        $this->loginAs('admin@flowsync.test');

        Payment::create([
            'tenant_id' => $this->acme()->id,
            'provider' => 'stripe',
            'amount_cents' => 2900,
            'currency' => 'usd',
            'status' => Payment::STATUS_COMPLETED,
            'idempotency_key' => 'hist_test_1',
            'metadata' => ['plan_name' => 'Pro'],
        ]);

        $res = $this->getJson('/api/billing/history')
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.amount_cents', 2900)
            ->assertJsonPath('payments.0.status', Payment::STATUS_COMPLETED)
            ->assertJsonPath('payments.0.plan_name', 'Pro');
    }

    public function test_admin_can_refund_completed_payment(): void
    {
        $this->loginAs('admin@flowsync.test');

        $payment = Payment::create([
            'tenant_id' => $this->acme()->id,
            'provider' => 'mock',
            'amount_cents' => 2900,
            'currency' => 'usd',
            'status' => Payment::STATUS_COMPLETED,
            'idempotency_key' => 'rfnd_test_1',
        ]);

        $res = $this->postJson("/api/billing/payments/{$payment->id}/refund", [
            'amount_cents' => 2900,
            'reason' => 'Customer requested refund',
        ])->assertOk();

        $res->assertJsonPath('payment.status', Payment::STATUS_REFUNDED);

        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertTrue(PaymentEvent::where('payment_id', $payment->id)
            ->where('type', PaymentEvent::TYPE_REFUND_CREATED)
            ->exists());
    }

    public function test_verification_failure_rolls_back_and_marks_failed(): void
    {
        $this->loginAs('admin@flowsync.test');
        $plan = $this->proPlan();

        $checkoutRes = $this->postJson('/api/billing/checkout', [
            'plan_id' => $plan->id,
        ])->assertCreated();

        $paymentId = $checkoutRes->json('payment.id');

        // Simulate gateway verification failure
        $this->fakeGateway->setShouldFail(true, 'Card was declined by bank');

        $this->postJson('/api/billing/verify', [
            'payment_id' => $paymentId,
            'provider_payment_id' => 'pi_failed_1',
        ])->assertStatus(500);

        $payment = Payment::find($paymentId);
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertStringContainsString('Card was declined', $payment->failure_reason);
    }

    public function test_payment_resolver_routes_locale_and_currency_properly(): void
    {
        $resolver = new PaymentResolver(config('payments'));
        $acme = $this->acme();

        // USD currency resolves to stripe
        $this->assertSame('stripe', $resolver->resolveForTenant($acme, 'usd')->name());

        // EUR currency resolves to stripe
        $this->assertSame('stripe', $resolver->resolveForTenant($acme, 'eur')->name());

        // INR currency resolves to razorpay
        $this->assertSame('razorpay', $resolver->resolveForTenant($acme, 'inr')->name());
    }

    public function test_live_credentials_switch_configures_real_gateways_for_stripe_and_razorpay(): void
    {
        $liveConfig = [
            'default_currency' => 'usd',
            'driver' => 'auto',
            'gateways' => [
                'stripe' => [
                    'name' => 'Stripe',
                    'key' => 'pk_live_sample123',
                    'secret' => 'sk_live_sample123',
                    'webhook_secret' => 'whsec_sample123',
                    'currencies' => ['usd', 'eur', 'gbp'],
                ],
                'razorpay' => [
                    'name' => 'Razorpay',
                    'key' => 'rzp_live_sample123',
                    'secret' => 'rzp_sec_sample123',
                    'webhook_secret' => 'rzp_wh_sample123',
                    'currencies' => ['inr'],
                ],
            ],
            'routing' => config('payments.routing'),
        ];

        $resolver = new PaymentResolver($liveConfig);

        $stripeGateway = $resolver->resolve('stripe');
        $this->assertInstanceOf(StripeGateway::class, $stripeGateway);
        $this->assertSame('stripe', $stripeGateway->name());

        $razorpayGateway = $resolver->resolve('razorpay');
        $this->assertInstanceOf(RazorpayGateway::class, $razorpayGateway);
        $this->assertSame('razorpay', $razorpayGateway->name());
    }
}
