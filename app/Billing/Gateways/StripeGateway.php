<?php

namespace App\Billing\Gateways;

use App\Billing\DTOs\CheckoutSession;
use App\Billing\DTOs\PaymentResult;
use App\Billing\DTOs\RefundResult;
use App\Billing\DTOs\WebhookResult;
use App\Billing\PaymentGateway;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StripeGateway implements PaymentGateway
{
    private const API_URL = 'https://api.stripe.com/v1';

    public function __construct(
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return Payment::PROVIDER_STRIPE;
    }

    public function createCheckoutSession(
        Tenant $tenant,
        SubscriptionPlan $plan,
        Payment $payment,
        array $options = []
    ): CheckoutSession {
        $secret = $this->config['secret'] ?? '';
        $key = $this->config['key'] ?? '';

        if (empty($secret)) {
            // In dev / test when keys are not configured, generate a mock hosted checkout session
            return new CheckoutSession(
                provider: $this->name(),
                sessionId: 'cs_test_'.bin2hex(random_bytes(12)),
                redirectUrl: url("/app/subscription?session_id=cs_test_{$payment->id}&status=success"),
                clientSecret: null,
                publishableKey: $key ?: 'pk_test_mock',
                currency: $payment->currency,
                amountCents: $payment->amount_cents,
            );
        }

        $successUrl = $options['success_url'] ?? url('/app/subscription?session_id={CHECKOUT_SESSION_ID}&status=success');
        $cancelUrl = $options['cancel_url'] ?? url('/app/subscription?status=canceled');

        $response = Http::withToken($secret)
            ->asForm()
            ->post(self::API_URL.'/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => (string) $payment->id,
                'customer_email' => $options['customer_email'] ?? null,
                'line_items[0][price_data][currency]' => strtolower($payment->currency),
                'line_items[0][price_data][unit_amount]' => $payment->amount_cents,
                'line_items[0][price_data][product_data][name]' => "FlowSync {$plan->name} Plan",
                'line_items[0][quantity]' => 1,
                'metadata[tenant_id]' => (string) $tenant->id,
                'metadata[payment_id]' => (string) $payment->id,
                'metadata[idempotency_key]' => $payment->idempotency_key,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Stripe checkout error: '.$response->body());
        }

        $data = $response->json();

        return new CheckoutSession(
            provider: $this->name(),
            sessionId: $data['id'],
            redirectUrl: $data['url'] ?? null,
            clientSecret: $data['client_secret'] ?? null,
            publishableKey: $key,
            currency: $payment->currency,
            amountCents: $payment->amount_cents,
            raw: $data,
        );
    }

    public function verifyPayment(string $providerPaymentId, array $payload = []): PaymentResult
    {
        $secret = $this->config['secret'] ?? '';

        if (empty($secret)) {
            return new PaymentResult(
                success: true,
                providerPaymentId: $providerPaymentId,
                providerOrderId: $payload['session_id'] ?? null,
                amountCents: (int) ($payload['amount_cents'] ?? 0),
                currency: $payload['currency'] ?? 'usd',
                status: Payment::STATUS_COMPLETED,
            );
        }

        $response = Http::withToken($secret)->get(self::API_URL."/payment_intents/{$providerPaymentId}");

        if (! $response->successful()) {
            return new PaymentResult(
                success: false,
                providerPaymentId: $providerPaymentId,
                providerOrderId: null,
                amountCents: 0,
                currency: 'usd',
                status: Payment::STATUS_FAILED,
                errorMessage: $response->body(),
            );
        }

        $data = $response->json();
        $isSuccess = ($data['status'] ?? '') === 'succeeded';

        return new PaymentResult(
            success: $isSuccess,
            providerPaymentId: $data['id'] ?? $providerPaymentId,
            providerOrderId: null,
            amountCents: (int) ($data['amount'] ?? 0),
            currency: strtolower($data['currency'] ?? 'usd'),
            status: $isSuccess ? Payment::STATUS_COMPLETED : Payment::STATUS_FAILED,
            metadata: $data['metadata'] ?? [],
        );
    }

    public function refund(Payment $payment, ?int $amountCents = null, ?string $reason = null): RefundResult
    {
        $secret = $this->config['secret'] ?? '';
        $amount = $amountCents ?? $payment->amount_cents;

        if (empty($secret)) {
            return new RefundResult(
                success: true,
                refundId: 're_mock_'.bin2hex(random_bytes(8)),
                amountCents: $amount,
                status: Payment::STATUS_REFUNDED,
            );
        }

        $payload = [
            'amount' => $amount,
        ];
        if ($payment->provider_payment_id) {
            $payload['payment_intent'] = $payment->provider_payment_id;
        }
        if ($reason) {
            $payload['reason'] = $reason;
        }

        $response = Http::withToken($secret)->asForm()->post(self::API_URL.'/refunds', $payload);

        if (! $response->successful()) {
            return new RefundResult(
                success: false,
                refundId: null,
                amountCents: 0,
                status: Payment::STATUS_FAILED,
                errorMessage: $response->body(),
            );
        }

        $data = $response->json();

        return new RefundResult(
            success: true,
            refundId: $data['id'] ?? null,
            amountCents: (int) ($data['amount'] ?? $amount),
            status: Payment::STATUS_REFUNDED,
            raw: $data,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $signature = $request->header('Stripe-Signature');
        $secret = $this->config['webhook_secret'] ?? '';

        if (empty($signature) || empty($secret)) {
            return false;
        }

        // Stripe-Signature: t=1492774577,v1=5257a869e7ecebeda32affa62cd...
        $items = explode(',', $signature);
        $timestamp = null;
        $signatures = [];

        foreach ($items as $item) {
            $parts = explode('=', trim($item), 2);
            if (count($parts) === 2) {
                if ($parts[0] === 't') {
                    $timestamp = $parts[1];
                } elseif ($parts[0] === 'v1') {
                    $signatures[] = $parts[1];
                }
            }
        }

        if (! $timestamp || empty($signatures)) {
            return false;
        }

        // Replay defense: verify within 5 minutes
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $payload = $timestamp.'.'.$request->getContent();
        $expected = hash_hmac('sha256', $payload, $secret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        $payload = $request->json()->all();
        $eventType = $payload['type'] ?? 'unknown';
        $eventId = $payload['id'] ?? null;
        $dataObject = $payload['data']['object'] ?? [];

        $paymentId = $dataObject['metadata']['payment_id'] ?? $dataObject['client_reference_id'] ?? null;
        $idempotencyKey = $dataObject['metadata']['idempotency_key'] ?? null;
        $providerPaymentId = $dataObject['payment_intent'] ?? $dataObject['id'] ?? null;

        $status = match ($eventType) {
            'checkout.session.completed', 'payment_intent.succeeded' => Payment::STATUS_COMPLETED,
            'payment_intent.payment_failed' => Payment::STATUS_FAILED,
            'charge.refunded' => Payment::STATUS_REFUNDED,
            default => 'unhandled',
        };

        return new WebhookResult(
            handled: $status !== 'unhandled',
            eventType: $eventType,
            providerEventId: $eventId,
            providerPaymentId: $providerPaymentId,
            providerOrderId: $dataObject['id'] ?? null,
            idempotencyKey: $idempotencyKey,
            status: $status,
            amountCents: (int) ($dataObject['amount_total'] ?? $dataObject['amount'] ?? 0),
            currency: strtolower($dataObject['currency'] ?? 'usd'),
            payload: $payload,
            failureReason: $dataObject['last_payment_error']['message'] ?? null,
        );
    }
}
