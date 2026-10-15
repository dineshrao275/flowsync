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

class RazorpayGateway implements PaymentGateway
{
    private const API_URL = 'https://api.razorpay.com/v1';

    public function __construct(
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return Payment::PROVIDER_RAZORPAY;
    }

    public function createCheckoutSession(
        Tenant $tenant,
        SubscriptionPlan $plan,
        Payment $payment,
        array $options = []
    ): CheckoutSession {
        $key = $this->config['key'] ?? '';
        $secret = $this->config['secret'] ?? '';

        if (empty($key) || empty($secret)) {
            // Mock mode when credentials are not configured
            $mockOrderId = 'order_test_'.bin2hex(random_bytes(10));

            return new CheckoutSession(
                provider: $this->name(),
                sessionId: $mockOrderId,
                redirectUrl: null,
                clientSecret: null,
                publishableKey: $key ?: 'rzp_test_mock',
                currency: 'inr',
                amountCents: $payment->amount_cents,
                raw: ['order_id' => $mockOrderId],
            );
        }

        $receipt = substr($payment->idempotency_key, 0, 40);

        $response = Http::withBasicAuth($key, $secret)
            ->post(self::API_URL.'/orders', [
                'amount' => $payment->amount_cents,
                'currency' => strtoupper($payment->currency),
                'receipt' => $receipt,
                'notes' => [
                    'tenant_id' => (string) $tenant->id,
                    'payment_id' => (string) $payment->id,
                    'plan_id' => (string) $plan->id,
                    'idempotency_key' => $payment->idempotency_key,
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Razorpay order creation error: '.$response->body());
        }

        $data = $response->json();
        $orderId = $data['id'];

        return new CheckoutSession(
            provider: $this->name(),
            sessionId: $orderId,
            redirectUrl: null,
            clientSecret: null,
            publishableKey: $key,
            currency: strtolower($data['currency'] ?? 'inr'),
            amountCents: (int) ($data['amount'] ?? $payment->amount_cents),
            raw: $data,
        );
    }

    public function verifyPayment(string $providerPaymentId, array $payload = []): PaymentResult
    {
        $key = $this->config['key'] ?? '';
        $secret = $this->config['secret'] ?? '';

        if (empty($key) || empty($secret)) {
            return new PaymentResult(
                success: true,
                providerPaymentId: $providerPaymentId,
                providerOrderId: $payload['razorpay_order_id'] ?? null,
                amountCents: (int) ($payload['amount_cents'] ?? 0),
                currency: 'inr',
                status: Payment::STATUS_COMPLETED,
            );
        }

        $response = Http::withBasicAuth($key, $secret)->get(self::API_URL."/payments/{$providerPaymentId}");

        if (! $response->successful()) {
            return new PaymentResult(
                success: false,
                providerPaymentId: $providerPaymentId,
                providerOrderId: null,
                amountCents: 0,
                currency: 'inr',
                status: Payment::STATUS_FAILED,
                errorMessage: $response->body(),
            );
        }

        $data = $response->json();
        $isSuccess = in_array($data['status'] ?? '', ['captured', 'authorized'], true);

        return new PaymentResult(
            success: $isSuccess,
            providerPaymentId: $data['id'] ?? $providerPaymentId,
            providerOrderId: $data['order_id'] ?? null,
            amountCents: (int) ($data['amount'] ?? 0),
            currency: strtolower($data['currency'] ?? 'inr'),
            status: $isSuccess ? Payment::STATUS_COMPLETED : Payment::STATUS_FAILED,
            feeCents: isset($data['fee']) ? (int) $data['fee'] : null,
            metadata: $data['notes'] ?? [],
        );
    }

    public function refund(Payment $payment, ?int $amountCents = null, ?string $reason = null): RefundResult
    {
        $key = $this->config['key'] ?? '';
        $secret = $this->config['secret'] ?? '';
        $amount = $amountCents ?? $payment->amount_cents;

        if (empty($key) || empty($secret) || empty($payment->provider_payment_id)) {
            return new RefundResult(
                success: true,
                refundId: 'rfnd_mock_'.bin2hex(random_bytes(8)),
                amountCents: $amount,
                status: Payment::STATUS_REFUNDED,
            );
        }

        $response = Http::withBasicAuth($key, $secret)
            ->post(self::API_URL."/payments/{$payment->provider_payment_id}/refund", [
                'amount' => $amount,
                'notes' => [
                    'reason' => $reason ?? 'Tenant requested refund',
                ],
            ]);

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
        $signature = $request->header('X-Razorpay-Signature');
        $secret = $this->config['webhook_secret'] ?? '';

        if (empty($signature) || empty($secret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        $payload = $request->json()->all();
        $eventType = $payload['event'] ?? 'unknown';
        $entity = $payload['payload']['payment']['entity'] ?? $payload['payload']['order']['entity'] ?? [];

        $paymentId = $entity['notes']['payment_id'] ?? null;
        $idempotencyKey = $entity['notes']['idempotency_key'] ?? null;
        $providerPaymentId = $entity['id'] ?? null;
        $providerOrderId = $entity['order_id'] ?? null;

        $status = match ($eventType) {
            'order.paid', 'payment.captured' => Payment::STATUS_COMPLETED,
            'payment.failed' => Payment::STATUS_FAILED,
            'refund.processed' => Payment::STATUS_REFUNDED,
            default => 'unhandled',
        };

        return new WebhookResult(
            handled: $status !== 'unhandled',
            eventType: $eventType,
            providerEventId: $payload['account_id'].':'.($payload['created_at'] ?? time()).':'.$eventType,
            providerPaymentId: $providerPaymentId,
            providerOrderId: $providerOrderId,
            idempotencyKey: $idempotencyKey,
            status: $status,
            amountCents: (int) ($entity['amount'] ?? 0),
            currency: strtolower($entity['currency'] ?? 'inr'),
            payload: $payload,
            failureReason: $entity['error_description'] ?? null,
        );
    }
}
