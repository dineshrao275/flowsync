<?php

namespace Tests\Feature;

use App\Billing\Gateways\FakePaymentGateway;
use App\Billing\PaymentResolver;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use IsolatesDatabase;

    private function acme(): Tenant
    {
        return Tenant::where('slug', 'acme')->firstOrFail();
    }

    private function proPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', 'pro')->firstOrFail();
    }

    public function test_invalid_stripe_signature_is_rejected_with_403(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'type' => 'payment_intent.succeeded',
        ], [
            'Stripe-Signature' => 'invalid_signature',
        ]);

        $response->assertForbidden();
    }

    public function test_invalid_razorpay_signature_is_rejected_with_403(): void
    {
        $response = $this->postJson('/api/webhooks/razorpay', [
            'event' => 'payment.captured',
        ], [
            'X-Razorpay-Signature' => 'invalid_signature',
        ]);

        $response->assertForbidden();
    }

    public function test_valid_stripe_webhook_activates_subscription_and_marks_payment_completed(): void
    {
        $fake = new FakePaymentGateway;
        $fake->setValidSignature(true);
        app(PaymentResolver::class)->swapFake($fake);

        $plan = $this->proPlan();
        $payment = Payment::create([
            'tenant_id' => $this->acme()->id,
            'provider' => 'stripe',
            'amount_cents' => $plan->price_cents,
            'currency' => 'usd',
            'status' => Payment::STATUS_PENDING,
            'idempotency_key' => 'stripe_webhook_test_1',
            'metadata' => ['plan_id' => $plan->id],
        ]);

        $response = $this->postJson('/api/webhooks/stripe', [
            'id' => 'evt_stripe_test_1',
            'type' => 'payment.succeeded',
            'idempotency_key' => $payment->idempotency_key,
            'payment_id' => 'pi_stripe_suc_1',
            'amount_cents' => $plan->price_cents,
        ], [
            'Stripe-Signature' => 'valid_sig',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('payment_status', Payment::STATUS_COMPLETED);

        $payment = $payment->fresh();
        $this->assertSame(Payment::STATUS_COMPLETED, $payment->status);

        $acme = $this->acme()->fresh();
        $this->assertSame($plan->id, $acme->subscription->plan_id);
        $this->assertSame('active', $acme->subscription->status);
    }

    public function test_webhook_replay_guard_prevents_duplicate_processing(): void
    {
        $fake = new FakePaymentGateway;
        $fake->setValidSignature(true);
        app(PaymentResolver::class)->swapFake($fake);

        $plan = $this->proPlan();
        $payment = Payment::create([
            'tenant_id' => $this->acme()->id,
            'provider' => 'stripe',
            'amount_cents' => $plan->price_cents,
            'currency' => 'usd',
            'status' => Payment::STATUS_PENDING,
            'idempotency_key' => 'replay_test_1',
            'metadata' => ['plan_id' => $plan->id],
        ]);

        $payload = [
            'id' => 'evt_unique_12345',
            'type' => 'payment.succeeded',
            'idempotency_key' => $payment->idempotency_key,
            'payment_id' => 'pi_replay_1',
            'amount_cents' => $plan->price_cents,
        ];

        // First delivery
        $res1 = $this->postJson('/api/webhooks/stripe', $payload);
        $res1->assertOk()->assertJsonPath('status', 'processed');

        // Replay of the exact same event
        $res2 = $this->postJson('/api/webhooks/stripe', $payload);
        $res2->assertOk()->assertJsonPath('status', 'already_processed');

        // Only 1 payment event recorded
        $this->assertSame(1, PaymentEvent::where('provider_event_id', 'evt_unique_12345')->count());
    }

    public function test_payment_failure_webhook_marks_payment_failed(): void
    {
        $fake = new FakePaymentGateway;
        $fake->setValidSignature(true);
        app(PaymentResolver::class)->swapFake($fake);

        $payment = Payment::create([
            'tenant_id' => $this->acme()->id,
            'provider' => 'stripe',
            'amount_cents' => 2900,
            'currency' => 'usd',
            'status' => Payment::STATUS_PENDING,
            'idempotency_key' => 'fail_test_1',
        ]);

        $response = $this->postJson('/api/webhooks/stripe', [
            'id' => 'evt_fail_1',
            'type' => 'payment.failed',
            'idempotency_key' => $payment->idempotency_key,
            'failure_reason' => 'Insufficient funds in card',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('payment_status', Payment::STATUS_FAILED);

        $payment = $payment->fresh();
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertSame('Insufficient funds in card', $payment->failure_reason);
    }
}
