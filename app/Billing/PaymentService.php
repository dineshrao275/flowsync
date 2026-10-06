<?php

namespace App\Billing;

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\TenantDatabaseManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentService
{
    public function __construct(
        private readonly PaymentResolver $resolver,
        private readonly SubscriptionService $subscriptions,
        private readonly TenantDatabaseManager $dbManager,
    ) {}

    private function centralDb(): ConnectionInterface
    {
        return DB::connection($this->dbManager->centralConnectionName());
    }

    /**
     * Initiate a checkout session for a subscription plan.
     *
     * Handles idempotency to guarantee no duplicate pending or completed charges.
     */
    public function initiateCheckout(
        Tenant $tenant,
        SubscriptionPlan $plan,
        ?User $user = null,
        array $options = []
    ): array {
        $currency = strtolower($options['currency'] ?? $plan->currency ?? 'usd');
        $idempotencyKey = $options['idempotency_key'] ?? ('fs_idem_'.Str::uuid());

        $gateway = $this->resolver->resolveForTenant($tenant, $currency);

        return $this->centralDb()->transaction(function () use ($tenant, $plan, $user, $gateway, $currency, $idempotencyKey, $options) {
            // Check for existing payment with this idempotency key
            $existing = Payment::where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                if ($existing->isCompleted()) {
                    throw new RuntimeException('This checkout request has already been completed.');
                }

                // If already pending, recreate session or reuse
                $session = $gateway->createCheckoutSession($tenant, $plan, $existing, $options);

                return [
                    'payment' => $existing,
                    'session' => $session->toArray(),
                ];
            }

            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $tenant->subscription_id,
                'user_id' => $user?->id,
                'provider' => $gateway->name(),
                'amount_cents' => $plan->price_cents,
                'currency' => $currency,
                'status' => Payment::STATUS_PENDING,
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    'plan_id' => $plan->id,
                    'plan_slug' => $plan->slug,
                    'plan_name' => $plan->name,
                ],
            ]);

            $session = $gateway->createCheckoutSession($tenant, $plan, $payment, $options);

            $payment->update([
                'provider_order_id' => $session->sessionId,
            ]);

            PaymentEvent::create([
                'payment_id' => $payment->id,
                'tenant_id' => $tenant->id,
                'type' => PaymentEvent::TYPE_CHECKOUT_CREATED,
                'provider' => $gateway->name(),
                'provider_event_id' => 'evt_init_'.$payment->id,
                'data' => [
                    'plan_id' => $plan->id,
                    'amount_cents' => $payment->amount_cents,
                    'session_id' => $session->sessionId,
                ],
            ]);

            return [
                'payment' => $payment,
                'session' => $session->toArray(),
            ];
        });
    }

    /**
     * Verify payment directly with provider and finalize transaction.
     */
    public function verifyPayment(
        Tenant $tenant,
        Payment $payment,
        string $providerPaymentId,
        array $payload = []
    ): Payment {
        $gateway = $this->resolver->resolve($payment->provider);

        $payment->refresh();

        if ($payment->isCompleted()) {
            return $payment;
        }

        $result = $gateway->verifyPayment($providerPaymentId, $payload);

        if (! $result->success) {
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'failure_reason' => $result->errorMessage,
            ]);

            PaymentEvent::create([
                'payment_id' => $payment->id,
                'tenant_id' => $tenant->id,
                'type' => PaymentEvent::TYPE_PAYMENT_FAILED,
                'provider' => $payment->provider,
                'provider_event_id' => 'evt_fail_'.Str::uuid(),
                'data' => ['error' => $result->errorMessage],
            ]);

            throw new RuntimeException($result->errorMessage ?? 'Payment verification failed.');
        }

        return $this->centralDb()->transaction(function () use ($tenant, $payment, $result) {
            $payment->update([
                'status' => Payment::STATUS_COMPLETED,
                'provider_payment_id' => $result->providerPaymentId,
                'fee_cents' => $result->feeCents,
            ]);

            // Sync subscription
            $this->activateSubscriptionForPayment($tenant, $payment);

            PaymentEvent::create([
                'payment_id' => $payment->id,
                'tenant_id' => $tenant->id,
                'type' => PaymentEvent::TYPE_PAYMENT_SUCCEEDED,
                'provider' => $payment->provider,
                'provider_event_id' => 'evt_suc_'.Str::uuid(),
                'data' => [
                    'amount_cents' => $payment->amount_cents,
                    'provider_payment_id' => $payment->provider_payment_id,
                ],
            ]);

            return $payment;
        });
    }

    /**
     * Process an incoming webhook with signature verification, replay protection, and transactional state change.
     */
    public function handleWebhook(string $providerName, Request $request): array
    {
        $gateway = $this->resolver->resolve($providerName);

        if (! $gateway->verifyWebhookSignature($request)) {
            abort(403, 'Invalid webhook signature.');
        }

        $result = $gateway->handleWebhook($request);

        if (! $result->handled) {
            return ['status' => 'ignored', 'event_type' => $result->eventType];
        }

        // Replay defense
        if ($result->providerEventId) {
            $alreadyProcessed = PaymentEvent::where('provider_event_id', $result->providerEventId)->exists();
            if ($alreadyProcessed) {
                return ['status' => 'already_processed', 'event_id' => $result->providerEventId];
            }
        }

        return $this->centralDb()->transaction(function () use ($result, $providerName) {
            // Find payment by idempotency key, provider payment id, or order id
            $payment = null;

            if ($result->idempotencyKey) {
                $payment = Payment::where('idempotency_key', $result->idempotencyKey)->first();
            }

            if (! $payment && $result->providerOrderId) {
                $payment = Payment::where('provider_order_id', $result->providerOrderId)->first();
            }

            if (! $payment && $result->providerPaymentId) {
                $payment = Payment::where('provider_payment_id', $result->providerPaymentId)->first();
            }

            if (! $payment) {
                // Log event without payment link for external audit
                return ['status' => 'payment_not_found', 'event_type' => $result->eventType];
            }

            $tenant = $payment->tenant;

            if ($result->status === Payment::STATUS_COMPLETED && ! $payment->isCompleted()) {
                $payment->update([
                    'status' => Payment::STATUS_COMPLETED,
                    'provider_payment_id' => $result->providerPaymentId ?? $payment->provider_payment_id,
                ]);

                if ($tenant) {
                    $this->activateSubscriptionForPayment($tenant, $payment);
                }
            } elseif ($result->status === Payment::STATUS_FAILED) {
                $payment->update([
                    'status' => Payment::STATUS_FAILED,
                    'failure_reason' => $result->failureReason,
                ]);
            } elseif ($result->status === Payment::STATUS_REFUNDED) {
                $payment->update([
                    'status' => Payment::STATUS_REFUNDED,
                ]);
            }

            PaymentEvent::create([
                'payment_id' => $payment->id,
                'tenant_id' => $payment->tenant_id,
                'type' => $result->eventType,
                'provider' => $providerName,
                'provider_event_id' => $result->providerEventId,
                'data' => $result->payload,
            ]);

            return [
                'status' => 'processed',
                'payment_id' => $payment->id,
                'payment_status' => $payment->status,
            ];
        });
    }

    /**
     * Refund a completed payment.
     */
    public function refund(Payment $payment, ?int $amountCents = null, ?string $reason = null): Payment
    {
        if (! $payment->isCompleted()) {
            throw new RuntimeException('Only completed payments can be refunded.');
        }

        $gateway = $this->resolver->resolve($payment->provider);

        return $this->centralDb()->transaction(function () use ($payment, $gateway, $amountCents, $reason) {
            $result = $gateway->refund($payment, $amountCents, $reason);

            if (! $result->success) {
                throw new RuntimeException($result->errorMessage ?? 'Refund processing failed.');
            }

            $isFull = ($amountCents === null || $amountCents >= $payment->amount_cents);

            $payment->update([
                'status' => $isFull ? Payment::STATUS_REFUNDED : Payment::STATUS_PARTIALLY_REFUNDED,
                'metadata' => array_merge($payment->metadata ?? [], [
                    'refund_id' => $result->refundId,
                    'refunded_amount_cents' => $result->amountCents,
                    'refund_reason' => $reason,
                ]),
            ]);

            PaymentEvent::create([
                'payment_id' => $payment->id,
                'tenant_id' => $payment->tenant_id,
                'type' => PaymentEvent::TYPE_REFUND_CREATED,
                'provider' => $payment->provider,
                'provider_event_id' => 'evt_rfnd_'.Str::uuid(),
                'data' => [
                    'refund_id' => $result->refundId,
                    'amount_cents' => $result->amountCents,
                    'reason' => $reason,
                ],
            ]);

            return $payment;
        });
    }

    private function activateSubscriptionForPayment(Tenant $tenant, Payment $payment): void
    {
        $planId = $payment->metadata['plan_id'] ?? null;
        $plan = $planId ? SubscriptionPlan::find($planId) : null;

        if ($plan) {
            $this->subscriptions->switch($tenant, $plan, [
                'billing_provider' => $payment->provider,
                'billing_reference' => $payment->provider_payment_id ?? $payment->provider_order_id,
            ]);
        } elseif ($tenant->subscription) {
            $this->subscriptions->renew($tenant);
            $tenant->subscription->update([
                'billing_provider' => $payment->provider,
                'billing_reference' => $payment->provider_payment_id ?? $payment->provider_order_id,
            ]);
        }
    }
}
