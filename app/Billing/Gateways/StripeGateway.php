<?php

namespace App\Billing\Gateways;

use App\Billing\DTOs\CheckoutSession;
use App\Billing\DTOs\PaymentResult;
use App\Billing\DTOs\RefundResult;
use App\Billing\DTOs\WebhookResult;
use App\Billing\PaymentGateway;
use App\Billing\RecurringGateway;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StripeGateway implements PaymentGateway, RecurringGateway
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
                redirectUrl: url("/app/subscription?session_id=cs_test_{$payment->id}&payment_id={$payment->id}&status=success"),
                clientSecret: null,
                publishableKey: $key ?: 'pk_test_mock',
                currency: $payment->currency,
                amountCents: $payment->amount_cents,
            );
        }

        $successUrl = $options['success_url']
            ?? url("/app/subscription?session_id={CHECKOUT_SESSION_ID}&payment_id={$payment->id}&status=success");
        $cancelUrl = $options['cancel_url'] ?? url('/app/subscription?status=canceled');

        $metadata = [
            'tenant_id' => (string) $tenant->id,
            'payment_id' => (string) $payment->id,
            'plan_id' => (string) $plan->id,
            'idempotency_key' => $payment->idempotency_key,
        ];

        $params = [
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $payment->id,
            'customer' => $this->customerFor($tenant, $options['customer_email'] ?? null),
            'line_items[0][price]' => $this->priceFor($plan, $payment->currency),
            'line_items[0][quantity]' => 1,
        ];
        foreach ($metadata as $k => $v) {
            $params["metadata[{$k}]"] = $v;
            // Subscription events (invoice.*, customer.subscription.*) carry the
            // subscription's metadata, not the session's — copy it across.
            $params["subscription_data[metadata][{$k}]"] = $v;
        }
        if (! empty($options['trial_days'])) {
            $params['subscription_data[trial_period_days]'] = (int) $options['trial_days'];
        }

        $data = $this->call('post', '/checkout/sessions', $params);

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

        if (str_starts_with($providerPaymentId, 'cs_')) {
            return $this->verifyCheckoutSession($providerPaymentId, $payload);
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

    /**
     * Stripe redirects back with a Checkout Session id. It is only trusted when
     * the session is complete AND was created for this very payment — otherwise a
     * tenant could replay somebody else's paid session against its own payment.
     */
    private function verifyCheckoutSession(string $sessionId, array $payload): PaymentResult
    {
        $fail = fn (string $why) => new PaymentResult(
            success: false, providerPaymentId: $sessionId, providerOrderId: $sessionId,
            amountCents: 0, currency: 'usd', status: Payment::STATUS_FAILED, errorMessage: $why,
        );

        try {
            $session = $this->call('get', "/checkout/sessions/{$sessionId}", ['expand[0]' => 'subscription']);
        } catch (RuntimeException $e) {
            return $fail($e->getMessage());
        }

        $expected = isset($payload['payment_id']) ? (string) $payload['payment_id'] : null;
        if ($expected !== null && ($session['client_reference_id'] ?? null) !== $expected) {
            return $fail('This checkout session does not belong to the payment being verified.');
        }

        $paid = ($session['status'] ?? '') === 'complete'
            && in_array($session['payment_status'] ?? '', ['paid', 'no_payment_required'], true);
        if (! $paid) {
            return $fail('Checkout is not complete yet.');
        }

        $subscription = is_array($session['subscription'] ?? null) ? $session['subscription'] : [];

        return new PaymentResult(
            success: true,
            providerPaymentId: $session['payment_intent'] ?? ($subscription['id'] ?? $sessionId),
            providerOrderId: $sessionId,
            amountCents: (int) ($session['amount_total'] ?? 0),
            currency: strtolower($session['currency'] ?? 'usd'),
            status: Payment::STATUS_COMPLETED,
            metadata: [
                'subscription_id' => $subscription['id'] ?? (is_string($session['subscription'] ?? null) ? $session['subscription'] : null),
                'period_end' => $this->periodEndOf($subscription),
                'customer' => is_string($session['customer'] ?? null) ? $session['customer'] : null,
            ],
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
        $object = $payload['data']['object'] ?? [];

        // Recurring lifecycle events are about a subscription, not one payment.
        $kind = match ($eventType) {
            'invoice.paid', 'invoice.payment_succeeded' => 'invoice_paid',
            'invoice.payment_failed' => 'invoice_failed',
            'customer.subscription.updated' => 'subscription_updated',
            'customer.subscription.deleted' => 'subscription_deleted',
            default => null,
        };

        if ($kind !== null) {
            $isInvoice = str_starts_with($eventType, 'invoice.');
            $subscriptionId = $isInvoice
                ? ($object['subscription'] ?? $object['parent']['subscription_details']['subscription'] ?? null)
                : ($object['id'] ?? null);
            $metadata = $isInvoice
                ? ($object['subscription_details']['metadata'] ?? $object['parent']['subscription_details']['metadata'] ?? [])
                : ($object['metadata'] ?? []);

            // The creation invoice is settled by checkout completion itself.
            $skip = $isInvoice && ($object['billing_reason'] ?? '') === 'subscription_create';

            return new WebhookResult(
                handled: ! $skip,
                eventType: $eventType,
                providerEventId: $eventId,
                providerPaymentId: $object['payment_intent'] ?? null,
                providerOrderId: $isInvoice ? ($object['id'] ?? null) : null,
                idempotencyKey: null,
                status: $kind === 'invoice_failed' ? Payment::STATUS_FAILED : Payment::STATUS_COMPLETED,
                amountCents: (int) ($object['amount_paid'] ?? $object['amount_due'] ?? 0),
                currency: strtolower($object['currency'] ?? 'usd'),
                payload: $payload,
                failureReason: $object['last_finalization_error']['message'] ?? null,
                kind: $kind,
                providerSubscriptionId: is_string($subscriptionId) ? $subscriptionId : null,
                tenantId: isset($metadata['tenant_id']) ? (int) $metadata['tenant_id'] : null,
                periodEnd: $isInvoice
                    ? ($object['lines']['data'][0]['period']['end'] ?? null)
                    : $this->periodEndOf($object),
                cancelAtPeriodEnd: $isInvoice ? null : (bool) ($object['cancel_at_period_end'] ?? false),
            );
        }

        $paymentId = $object['metadata']['payment_id'] ?? $object['client_reference_id'] ?? null;
        $idempotencyKey = $object['metadata']['idempotency_key'] ?? null;
        $providerPaymentId = $object['payment_intent'] ?? $object['id'] ?? null;

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
            providerOrderId: $object['id'] ?? null,
            idempotencyKey: $idempotencyKey,
            status: $status,
            amountCents: (int) ($object['amount_total'] ?? $object['amount'] ?? 0),
            currency: strtolower($object['currency'] ?? 'usd'),
            payload: $payload,
            failureReason: $object['last_payment_error']['message'] ?? null,
            providerSubscriptionId: is_string($object['subscription'] ?? null) ? $object['subscription'] : null,
        );
    }

    // ------------------------------------------------------------ recurring

    public function changePlan(string $providerSubscriptionId, SubscriptionPlan $plan, string $currency): void
    {
        $sub = $this->call('get', "/subscriptions/{$providerSubscriptionId}");
        $itemId = $sub['items']['data'][0]['id'] ?? null;
        if (! $itemId) {
            throw new RuntimeException('Stripe subscription has no item to change.');
        }

        $this->call('post', "/subscriptions/{$providerSubscriptionId}", [
            'items[0][id]' => $itemId,
            'items[0][price]' => $this->priceFor($plan, $currency),
            'proration_behavior' => 'create_prorations',
            'cancel_at_period_end' => 'false',
            'metadata[plan_id]' => (string) $plan->id,
        ]);
    }

    public function setCancelAtPeriodEnd(string $providerSubscriptionId, bool $cancel): void
    {
        $this->call('post', "/subscriptions/{$providerSubscriptionId}", [
            'cancel_at_period_end' => $cancel ? 'true' : 'false',
        ]);
    }

    public function portalUrl(Tenant $tenant, string $returnUrl): string
    {
        $session = $this->call('post', '/billing_portal/sessions', [
            'customer' => $this->customerFor($tenant),
            'return_url' => $returnUrl,
        ]);

        return $session['url'];
    }

    // -------------------------------------------------------------- helpers

    /** One Stripe Customer per tenant, created lazily and remembered. */
    private function customerFor(Tenant $tenant, ?string $email = null): string
    {
        if ($tenant->billing_customer_id) {
            return $tenant->billing_customer_id;
        }

        $customer = $this->call('post', '/customers', array_filter([
            'name' => $tenant->name,
            'email' => $tenant->billing_email ?: $email,
            'metadata[tenant_id]' => (string) $tenant->id,
            'metadata[slug]' => $tenant->slug,
        ]));

        $tenant->forceFill(['billing_customer_id' => $customer['id']])->save();

        return $customer['id'];
    }

    /** The Stripe Price for this plan; re-created whenever amount, currency or cycle change. */
    private function priceFor(SubscriptionPlan $plan, string $currency): string
    {
        $currency = strtolower($currency);
        $interval = $plan->billing_cycle === 'annual' ? 'year' : 'month';
        $key = implode(':', [$plan->price_cents, $currency, $interval]);

        if ($plan->stripe_price_id && $plan->stripe_price_key === $key) {
            return $plan->stripe_price_id;
        }

        $price = $this->call('post', '/prices', [
            'currency' => $currency,
            'unit_amount' => $plan->price_cents,
            'recurring[interval]' => $interval,
            'product_data[name]' => "FlowSync {$plan->name} Plan",
            'metadata[plan_id]' => (string) $plan->id,
        ]);

        $plan->forceFill(['stripe_price_id' => $price['id'], 'stripe_price_key' => $key])->save();

        return $price['id'];
    }

    /** Current-period end as a unix timestamp; Stripe moved it between API versions. */
    private function periodEndOf(array $subscription): ?int
    {
        return $subscription['current_period_end']
            ?? $subscription['items']['data'][0]['current_period_end']
            ?? null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $params = []): array
    {
        $request = Http::withToken($this->config['secret'])->asForm()->timeout(20);
        $response = $method === 'get'
            ? $request->get(self::API_URL.$path, $params)
            : $request->post(self::API_URL.$path, $params);

        if (! $response->successful()) {
            throw new RuntimeException('Stripe error: '.($response->json('error.message') ?? $response->body()));
        }

        return $response->json();
    }
}
