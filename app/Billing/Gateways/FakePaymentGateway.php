<?php

namespace App\Billing\Gateways;

use App\Billing\CardCaptureGateway;
use App\Billing\DTOs\CheckoutSession;
use App\Billing\DTOs\PaymentResult;
use App\Billing\DTOs\RefundResult;
use App\Billing\DTOs\WebhookResult;
use App\Billing\PaymentGateway;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Http\Request;

class FakePaymentGateway implements CardCaptureGateway, PaymentGateway
{
    private bool $shouldFail = false;

    private bool $validSignature = true;

    private ?string $failureReason = null;

    private array $recordedSessions = [];

    private array $recordedRefunds = [];

    public function name(): string
    {
        return Payment::PROVIDER_MOCK;
    }

    public function setShouldFail(bool $fail, ?string $reason = 'Simulated gateway failure'): self
    {
        $this->shouldFail = $fail;
        $this->failureReason = $reason;

        return $this;
    }

    public function setValidSignature(bool $valid): self
    {
        $this->validSignature = $valid;

        return $this;
    }

    public function createCheckoutSession(
        Tenant $tenant,
        SubscriptionPlan $plan,
        Payment $payment,
        array $options = []
    ): CheckoutSession {
        if ($this->shouldFail) {
            throw new \RuntimeException($this->failureReason ?? 'Checkout session creation failed');
        }

        $sessionId = 'fake_cs_'.bin2hex(random_bytes(8));
        $this->recordedSessions[] = [
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'payment_id' => $payment->id,
            'session_id' => $sessionId,
        ];

        return new CheckoutSession(
            provider: $this->name(),
            sessionId: $sessionId,
            redirectUrl: url("/app/subscription?session_id={$sessionId}&status=success"),
            clientSecret: 'fake_secret_'.bin2hex(random_bytes(6)),
            publishableKey: 'fake_pk_key',
            currency: $payment->currency,
            amountCents: $payment->amount_cents,
            raw: ['mock' => true],
        );
    }

    public function verifyPayment(string $providerPaymentId, array $payload = []): PaymentResult
    {
        if ($this->shouldFail) {
            return new PaymentResult(
                success: false,
                providerPaymentId: $providerPaymentId,
                providerOrderId: null,
                amountCents: 0,
                currency: 'usd',
                status: Payment::STATUS_FAILED,
                errorMessage: $this->failureReason,
            );
        }

        return new PaymentResult(
            success: true,
            providerPaymentId: $providerPaymentId,
            providerOrderId: 'fake_order_1',
            amountCents: (int) ($payload['amount_cents'] ?? 2900),
            currency: $payload['currency'] ?? 'usd',
            status: Payment::STATUS_COMPLETED,
            feeCents: 85,
        );
    }

    public function refund(Payment $payment, ?int $amountCents = null, ?string $reason = null): RefundResult
    {
        $amount = $amountCents ?? $payment->amount_cents;

        if ($this->shouldFail) {
            return new RefundResult(
                success: false,
                refundId: null,
                amountCents: 0,
                status: Payment::STATUS_FAILED,
                errorMessage: $this->failureReason,
            );
        }

        $refundId = 'fake_rfnd_'.bin2hex(random_bytes(6));
        $this->recordedRefunds[] = [
            'payment_id' => $payment->id,
            'refund_id' => $refundId,
            'amount_cents' => $amount,
        ];

        return new RefundResult(
            success: true,
            refundId: $refundId,
            amountCents: $amount,
            status: Payment::STATUS_REFUNDED,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return $this->validSignature;
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        $payload = $request->json()->all();
        $type = $payload['type'] ?? 'payment.succeeded';
        $status = match ($type) {
            'payment.succeeded', 'checkout.session.completed' => Payment::STATUS_COMPLETED,
            'payment.failed' => Payment::STATUS_FAILED,
            'refund.processed', 'charge.refunded' => Payment::STATUS_REFUNDED,
            default => 'unhandled',
        };

        return new WebhookResult(
            handled: $status !== 'unhandled',
            eventType: $type,
            providerEventId: $payload['id'] ?? ('evt_'.bin2hex(random_bytes(6))),
            providerPaymentId: $payload['payment_id'] ?? ('pi_'.bin2hex(random_bytes(6))),
            providerOrderId: $payload['order_id'] ?? null,
            idempotencyKey: $payload['idempotency_key'] ?? null,
            status: $status,
            amountCents: (int) ($payload['amount_cents'] ?? 2900),
            currency: $payload['currency'] ?? 'usd',
            payload: $payload,
            failureReason: $payload['failure_reason'] ?? null,
        );
    }

    /** @var array<string, array{tenant_id: int, complete: bool}> */
    private array $cardSessions = [];

    public array $trialSubscriptions = [];

    public function createCardSession(Tenant $tenant, string $successUrl, string $cancelUrl): array
    {
        if ($this->shouldFail) {
            throw new \RuntimeException($this->failureReason ?? 'Card session failed');
        }
        $id = 'fake_setup_'.bin2hex(random_bytes(6));
        $this->cardSessions[$id] = ['tenant_id' => $tenant->id, 'complete' => true];

        return ['id' => $id, 'url' => str_replace('{CHECKOUT_SESSION_ID}', $id, $successUrl)];
    }

    /** Test hook: make a card session look unfinished. */
    public function abandonCardSession(string $id): void
    {
        $this->cardSessions[$id]['complete'] = false;
    }

    public function retrieveCardSession(string $sessionId): array
    {
        $s = $this->cardSessions[$sessionId] ?? null;

        return [
            'complete' => (bool) ($s['complete'] ?? false),
            'reference' => isset($s['tenant_id']) ? (string) $s['tenant_id'] : null,
            'payment_method' => $s ? 'pm_fake_'.$sessionId : null,
        ];
    }

    public function startTrialSubscription(Tenant $tenant, SubscriptionPlan $plan, string $currency, string $paymentMethod, int $trialDays): array
    {
        $this->trialSubscriptions[] = compact('paymentMethod', 'trialDays') + ['tenant_id' => $tenant->id, 'plan_id' => $plan->id];

        return ['subscription_id' => 'sub_fake_'.count($this->trialSubscriptions), 'period_end' => now()->addDays($trialDays)->timestamp];
    }
}
