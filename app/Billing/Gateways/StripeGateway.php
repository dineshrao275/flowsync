<?php

namespace App\Billing\Gateways;

use App\Billing\CardCaptureGateway;
use App\Billing\DTOs\CheckoutSession;
use App\Billing\DTOs\PaymentResult;
use App\Billing\DTOs\RefundResult;
use App\Billing\DTOs\WebhookResult;
use App\Billing\PaymentGateway;
use App\Billing\RecurringGateway;
use App\Billing\Stripe\BillingProviderException;
use App\Billing\Stripe\CatalogSync;
use App\Billing\Stripe\StripeClient;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StripeGateway implements CardCaptureGateway, PaymentGateway, RecurringGateway
{
    private const API_URL = 'https://api.stripe.com/v1';

    private ?StripeClient $client = null;

    private ?CatalogSync $catalog = null;

    public function __construct(
        private readonly array $config,
    ) {}

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient((string) ($this->config['secret'] ?? ''));
    }

    private function catalog(): CatalogSync
    {
        return $this->catalog ??= new CatalogSync($this->client());
    }

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
            'invoice.payment_action_required' => 'action_required',
            'customer.subscription.updated' => 'subscription_updated',
            'customer.subscription.deleted' => 'subscription_deleted',
            'customer.subscription.trial_will_end' => 'trial_will_end',
            'subscription_schedule.released', 'subscription_schedule.canceled', 'subscription_schedule.aborted' => 'schedule_ended',
            'payment_method.attached', 'payment_method.detached', 'payment_method.updated', 'payment_method.automatically_updated' => 'payment_method',
            default => null,
        };

        if ($kind !== null) {
            $isInvoice = str_starts_with($eventType, 'invoice.');
            $isSchedule = str_starts_with($eventType, 'subscription_schedule.');
            $isPaymentMethod = $kind === 'payment_method';
            $subscriptionId = match (true) {
                $isInvoice => $object['subscription'] ?? $object['parent']['subscription_details']['subscription'] ?? null,
                $isSchedule => $object['released_subscription'] ?? $object['subscription'] ?? null,
                $isPaymentMethod => null,
                default => $object['id'] ?? null,
            };
            $metadata = $isInvoice
                ? ($object['subscription_details']['metadata'] ?? $object['parent']['subscription_details']['metadata'] ?? [])
                : ($object['metadata'] ?? []);
            $customerId = is_string($object['customer'] ?? null) ? $object['customer'] : null;

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
                    : ($kind === 'trial_will_end' ? ($object['trial_end'] ?? null) : $this->periodEndOf($object)),
                cancelAtPeriodEnd: ($isInvoice || $isSchedule || $isPaymentMethod) ? null : (bool) ($object['cancel_at_period_end'] ?? false),
                providerCustomerId: $customerId,
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

    public function setSubscriptionPrice(string $providerSubscriptionId, SubscriptionPlan $plan, string $currency): void
    {
        $sub = $this->call('get', "/subscriptions/{$providerSubscriptionId}");
        $itemId = $sub['items']['data'][0]['id'] ?? null;
        if (! $itemId) {
            throw new RuntimeException('Stripe subscription has no item to change.');
        }

        // proration_behavior=none: the period already paid is untouched and nothing is charged now.
        $this->call('post', "/subscriptions/{$providerSubscriptionId}", [
            'items[0][id]' => $itemId,
            'items[0][price]' => $this->priceFor($plan, $currency),
            'proration_behavior' => 'none',
            'metadata[plan_id]' => (string) $plan->id,
        ], "sub_{$providerSubscriptionId}_price_{$plan->id}");
    }

    public function scheduleChange(string $providerSubscriptionId, SubscriptionPlan $plan, string $currency, ?string $scheduleId = null): string
    {
        $newPrice = $this->priceFor($plan, $currency);

        if ($scheduleId === null) {
            $sub = $this->call('get', "/subscriptions/{$providerSubscriptionId}");
            $scheduleId = is_string($sub['schedule'] ?? null) ? $sub['schedule'] : null;
        }
        $scheduleId ??= $this->call('post', '/subscription_schedules', ['from_subscription' => $providerSubscriptionId], "sub_{$providerSubscriptionId}_schedule")['id'];

        $schedule = $this->call('get', "/subscription_schedules/{$scheduleId}");
        $current = $schedule['phases'][0] ?? null;
        $currentItem = $current['items'][0] ?? null;
        if (! $current || ! $currentItem) {
            throw new RuntimeException('Stripe schedule has no current phase.');
        }
        $currentPrice = is_array($currentItem['price'] ?? null) ? ($currentItem['price']['id'] ?? null) : ($currentItem['price'] ?? null);

        $this->call('post', "/subscription_schedules/{$scheduleId}", [
            'end_behavior' => 'release',
            'phases[0][start_date]' => $current['start_date'],
            'phases[0][end_date]' => $current['end_date'],
            'phases[0][items][0][price]' => $currentPrice,
            'phases[0][items][0][quantity]' => $currentItem['quantity'] ?? 1,
            'phases[1][items][0][price]' => $newPrice,
            'phases[1][items][0][quantity]' => 1,
            'phases[1][proration_behavior]' => 'none',
            'phases[1][metadata][plan_id]' => (string) $plan->id,
            'metadata[pending_plan_id]' => (string) $plan->id,
        ], "schedule_{$scheduleId}_to_{$plan->id}");

        return $scheduleId;
    }

    public function releaseSchedule(string $scheduleId): void
    {
        try {
            $this->call('post', "/subscription_schedules/{$scheduleId}/release", [], "schedule_{$scheduleId}_release");
        } catch (BillingProviderException $e) {
            // Already released/completed/cancelled: the goal (no queued change) is met.
            if ($e->status === 404 || ($e->status === 400 && str_contains($e->getMessage(), 'schedule'))) {
                return;
            }
            throw $e;
        }
    }

    public function paymentMethods(Tenant $tenant): array
    {
        if (! $tenant->billing_customer_id) {
            return [];
        }

        $customer = $this->call('get', "/customers/{$tenant->billing_customer_id}");
        $default = $customer['invoice_settings']['default_payment_method'] ?? null;
        $default = is_array($default) ? ($default['id'] ?? null) : $default;
        $list = $this->call('get', '/payment_methods', ['customer' => $tenant->billing_customer_id, 'type' => 'card']);

        return collect($list['data'] ?? [])->map(fn (array $pm): array => [
            'id' => $pm['id'],
            'brand' => (string) ($pm['card']['brand'] ?? 'card'),
            'last4' => (string) ($pm['card']['last4'] ?? ''),
            'exp_month' => (int) ($pm['card']['exp_month'] ?? 0),
            'exp_year' => (int) ($pm['card']['exp_year'] ?? 0),
            'is_default' => $pm['id'] === $default,
        ])->values()->all();
    }

    public function setDefaultPaymentMethod(Tenant $tenant, string $paymentMethodId, array $providerSubscriptionIds = []): void
    {
        $customer = $this->customerFor($tenant);
        $this->call('post', "/customers/{$customer}", ['invoice_settings[default_payment_method]' => $paymentMethodId], "cust_{$customer}_default_{$paymentMethodId}");

        // Subscriptions started with an explicit card keep charging it unless told otherwise.
        foreach ($providerSubscriptionIds as $id) {
            $this->call('post', "/subscriptions/{$id}", ['default_payment_method' => $paymentMethodId], "sub_{$id}_pm_{$paymentMethodId}");
        }
    }

    public function detachPaymentMethod(string $paymentMethodId): void
    {
        $this->call('post', "/payment_methods/{$paymentMethodId}/detach", [], "pm_{$paymentMethodId}_detach");
    }

    public function retrieveSubscription(string $providerSubscriptionId): array
    {
        return $this->call('get', "/subscriptions/{$providerSubscriptionId}");
    }

    // ---------------------------------------------------------- card on file

    public function createCardSession(Tenant $tenant, string $successUrl, string $cancelUrl): array
    {
        $session = $this->call('post', '/checkout/sessions', [
            'mode' => 'setup',
            'customer' => $this->customerFor($tenant),
            'currency' => strtolower((string) config('payments.default_currency', 'usd')),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $tenant->id,
            'metadata[tenant_id]' => (string) $tenant->id,
        ]);

        return ['id' => $session['id'], 'url' => $session['url']];
    }

    public function retrieveCardSession(string $sessionId): array
    {
        $session = $this->call('get', "/checkout/sessions/{$sessionId}", ['expand[0]' => 'setup_intent']);
        $intent = is_array($session['setup_intent'] ?? null) ? $session['setup_intent'] : [];

        return [
            'complete' => ($session['status'] ?? '') === 'complete' && ($intent['status'] ?? '') === 'succeeded',
            'reference' => $session['client_reference_id'] ?? null,
            'payment_method' => is_string($intent['payment_method'] ?? null) ? $intent['payment_method'] : null,
        ];
    }

    public function startTrialSubscription(Tenant $tenant, SubscriptionPlan $plan, string $currency, string $paymentMethod, int $trialDays): array
    {
        $customer = $this->customerFor($tenant);
        $this->call('post', "/customers/{$customer}", ['invoice_settings[default_payment_method]' => $paymentMethod]);

        $sub = $this->call('post', '/subscriptions', [
            'customer' => $customer,
            'items[0][price]' => $this->priceFor($plan, $currency),
            'trial_period_days' => $trialDays,
            'default_payment_method' => $paymentMethod,
            'metadata[tenant_id]' => (string) $tenant->id,
            'metadata[plan_id]' => (string) $plan->id,
        ]);

        return ['subscription_id' => $sub['id'], 'period_end' => $this->periodEndOf($sub)];
    }

    // -------------------------------------------------------------- helpers

    /** One Stripe Customer per tenant (named after the organisation), created lazily and remembered. */
    private function customerFor(Tenant $tenant, ?string $email = null): string
    {
        return $this->catalog()->customerFor($tenant, $email);
    }

    /** The Stripe Price for this plan; the catalog creates product/price when missing or stale. */
    private function priceFor(SubscriptionPlan $plan, string $currency): string
    {
        return $this->catalog()->priceFor($plan, $currency);
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
    private function call(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        return $this->client()->request($method, $path, $params, $idempotencyKey);
    }
}
