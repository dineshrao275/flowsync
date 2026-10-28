<?php

declare(strict_types=1);

namespace App\Billing;

use App\Billing\DTOs\PaymentResult;
use App\Billing\DTOs\WebhookResult;
use App\Billing\Invoices\InvoiceService;
use App\Billing\Plans\PlanChangeScheduler;
use App\Billing\Proration\ProrationCalculator;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\TenantDatabaseManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentService
{
    public function __construct(
        private readonly PaymentResolver $resolver,
        private readonly SubscriptionService $subscriptions,
        private readonly TenantDatabaseManager $dbManager,
        private readonly SubscriptionBillingSync $sync,
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
        return BillingLock::for($tenant, fn () => $this->doInitiateCheckout($tenant, $plan, $user, $options));
    }

    /** @return array<string, mixed> */
    private function doInitiateCheckout(Tenant $tenant, SubscriptionPlan $plan, ?User $user, array $options): array
    {
        $currency = strtolower($options['currency'] ?? $plan->currency ?? 'usd');
        $idempotencyKey = $options['idempotency_key'] ?? ('fs_idem_'.Str::uuid());

        $gateway = $this->resolver->resolveForTenant($tenant, $currency);

        // Already paying through a recurring gateway: the change is queued for the end of the paid period (never a second
        // checkout, never a mid-period swap that would double-charge) — PlanChangeScheduler explains why.
        $providerSubscriptionId = $tenant->subscriptionFor($plan->product ?? 'suite')?->provider_subscription_id;
        if ($providerSubscriptionId && $gateway instanceof RecurringGateway && $plan->price_cents > 0) {
            $outcome = app(PlanChangeScheduler::class)->request($tenant, $plan, ['source' => 'checkout']);

            return ['payment' => null, 'session' => null, 'changed' => true, 'mode' => $outcome['mode'], 'effective_at' => $outcome['subscription']->pending_change_at?->toIso8601String()];
        }

        // A tenant still in its trial keeps the days it has left: Stripe starts
        // charging when the trial ends, not at checkout.
        if ($tenant->trial_ends_at?->isFuture() && ! isset($options['trial_days'])) {
            $options['trial_days'] = max(1, (int) ceil(now()->diffInHours($tenant->trial_ends_at) / 24));
        }

        // A paying tenant moving plans mid-period gets the unused time credited (one-off providers only).
        $proration = app(ProrationCalculator::class)->forCheckout($tenant, $plan, $gateway);

        // 1. Record the intent first and COMMIT it: if the customer pays and the process dies before we hear back, the
        //    webhook still finds this row (by idempotency key / payment_id metadata). A row that only existed inside an open
        //    transaction would make a paid checkout look like "payment not found".
        [$payment, $reused] = $this->centralDb()->transaction(function () use ($tenant, $plan, $user, $gateway, $currency, $idempotencyKey, $proration) {
            $existing = Payment::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existing) {
                if ($existing->isCompleted()) {
                    throw new RuntimeException('This checkout request has already been completed.');
                }

                return [$existing, true];
            }

            return [Payment::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $tenant->subscription_id,
                'user_id' => $user?->id,
                'provider' => $gateway->name(),
                'amount_cents' => $proration['amount_cents'] ?? $plan->price_cents,
                'currency' => $currency,
                'status' => Payment::STATUS_PENDING,
                'idempotency_key' => $idempotencyKey,
                'metadata' => array_filter([
                    'plan_id' => $plan->id,
                    'plan_slug' => $plan->slug,
                    'plan_name' => $plan->name,
                    'invoice_lines' => $proration['lines'] ?? null,
                ]),
            ]), false];
        });

        // 2. Talk to the provider outside any transaction (a network call must never hold a database lock). If it fails,
        //    compensate: a row nobody can ever pay must not stay "pending".
        try {
            $session = $gateway->createCheckoutSession($tenant, $plan, $payment, $options);
        } catch (\Throwable $e) {
            if (! $reused) {
                $payment->update(['status' => Payment::STATUS_FAILED, 'failure_reason' => 'checkout_session_failed']);
            }

            throw $e;
        }

        // 3. Attach the provider reference. If this write fails the session can still be paid: the webhook matches the row
        //    by its idempotency key, and `billing:reconcile` reports pending rows without a reference.
        $this->centralDb()->transaction(function () use ($payment, $tenant, $plan, $gateway, $session, $reused) {
            $payment->update(['provider_order_id' => $session->sessionId]);

            if (! $reused) {
                PaymentEvent::create([
                    'payment_id' => $payment->id,
                    'tenant_id' => $tenant->id,
                    'type' => PaymentEvent::TYPE_CHECKOUT_CREATED,
                    'provider' => $gateway->name(),
                    'provider_event_id' => 'evt_init_'.$payment->id,
                    'data' => ['plan_id' => $plan->id, 'amount_cents' => $payment->amount_cents, 'session_id' => $session->sessionId],
                ]);
            }
        });

        return ['payment' => $payment, 'session' => $session->toArray()];
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

        // The provider confirmed SOME payment; it must be THIS checkout's: same order, and not less than was asked. Without
        // this, a cheap captured payment id could be replayed to "pay" for an expensive plan.
        if ($result->success) {
            $wrongOrder = $result->providerOrderId && $payment->provider_order_id && ! hash_equals((string) $payment->provider_order_id, (string) $result->providerOrderId);
            $short = $result->amountCents > 0 && $result->amountCents < $payment->amount_cents;
            if ($wrongOrder || $short) {
                $result = new PaymentResult(false, $providerPaymentId, $result->providerOrderId, $result->amountCents, $result->currency, Payment::STATUS_FAILED, errorMessage: __('That payment does not match this checkout.'));
            }
        }

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
            // The webhook and the browser callback race to confirm the same payment: whoever gets the row lock first
            // completes it, the other finds it done (activation and invoice must happen exactly once).
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if ($payment->isCompleted()) {
                return $payment;
            }

            $payment->update([
                'status' => Payment::STATUS_COMPLETED,
                'provider_payment_id' => $result->providerPaymentId,
                'fee_cents' => $result->feeCents,
                'metadata' => array_merge($payment->metadata ?? [], array_filter([
                    'provider_subscription_id' => $result->metadata['subscription_id'] ?? null,
                    'period_end' => $result->metadata['period_end'] ?? null,
                ])),
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
            abort(403, __('Invalid webhook signature.'));
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

        // The same event delivered twice at once (Stripe retries on a slow answer): only one worker processes it, the
        // other acknowledges — a later redelivery then hits the replay check above.
        $lock = $result->providerEventId ? Cache::lock('billing:event:'.$result->providerEventId, 60) : null;
        if ($lock && ! $lock->get()) {
            return ['status' => 'in_progress', 'event_id' => $result->providerEventId];
        }

        try {
            return $this->process($result, $providerName);
        } finally {
            $lock?->release();
        }
    }

    /** @return array<string, mixed> */
    private function process(WebhookResult $result, string $providerName): array
    {
        if ($result->kind !== null) {
            return $this->centralDb()->transaction(fn () => $this->sync->handle($result, $providerName));
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
                // Keep the event: a webhook can arrive before the checkout row is visible, and reconciliation (or support)
                // needs to see what the provider said instead of it vanishing behind a 200.
                Log::warning('Payment webhook matched no payment.', ['event' => $result->eventType, 'provider_event' => $result->providerEventId, 'order' => $result->providerOrderId]);

                return ['status' => 'payment_not_found', 'event_type' => $result->eventType];
            }

            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();
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

                if ($tenant && $tenant->subscription) {
                    $this->subscriptions->suspend($tenant, [
                        'data' => [
                            'failure_reason' => $result->failureReason,
                            'payment_id' => $payment->id,
                        ],
                    ], null, SubscriptionPlan::find($payment->metadata['plan_id'] ?? 0)?->product);
                }
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

        // Provider call OUTSIDE the transaction: a database rollback cannot take money back from the provider, so the order is
        // refund first, record second — and if the record fails the refund is still real and the provider's refund event
        // (`charge.refunded` / `refund.processed`) brings the local row into line.
        $result = $gateway->refund($payment, $amountCents, $reason);

        if (! $result->success) {
            throw new RuntimeException($result->errorMessage ?? 'Refund processing failed.');
        }

        try {
            return $this->centralDb()->transaction(function () use ($payment, $result, $amountCents, $reason) {
                $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();
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
                    'provider_event_id' => 'evt_rfnd_'.($result->refundId ?: Str::uuid()),
                    'data' => ['refund_id' => $result->refundId, 'amount_cents' => $result->amountCents, 'reason' => $reason],
                ]);

                return $payment;
            });
        } catch (\Throwable $e) {
            Log::critical('Refund succeeded at the provider but could not be recorded; the provider event will reconcile it.', ['payment_id' => $payment->id, 'refund_id' => $result->refundId, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    private function activateSubscriptionForPayment(Tenant $tenant, Payment $payment): void
    {
        $planId = $payment->metadata['plan_id'] ?? null;
        $plan = $planId ? SubscriptionPlan::find($planId) : null;

        $providerSubscriptionId = $payment->metadata['provider_subscription_id'] ?? null;

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

        // Re-read: switch()/renew() may have just created or re-stamped the row.
        $current = Subscription::where('tenant_id', $tenant->id)
            ->when($plan, fn ($q) => $q->where('product', $plan->product ?? 'suite'))->orderBy('id')->first();
        if ($providerSubscriptionId && $current) {
            $current->update([
                'provider_subscription_id' => $providerSubscriptionId,
                'billing_provider' => $payment->provider,
            ]);
        }

        // The paid charge gets its invoice (idempotent per payment).
        app(InvoiceService::class)->issueForPayment($payment->refresh());
    }

    // ---- provider trials / cards / portal: see ProviderTrials ------------------------------------

    private function trials(): ProviderTrials
    {
        return new ProviderTrials($this->resolver);
    }

    /** Whether the gateway that would bill this tenant can save a card for a trial. */
    public function canCaptureCard(Tenant $tenant): bool
    {
        return $this->trials()->canCaptureCard($tenant);
    }

    /** @return array{id: string, url: string} */
    public function createCardSession(Tenant $tenant, string $successUrl, string $cancelUrl): array
    {
        return $this->trials()->createCardSession($tenant, $successUrl, $cancelUrl);
    }

    /** @return array{complete: bool, reference: string|null, payment_method: string|null} */
    public function retrieveCardSession(Tenant $tenant, string $sessionId): array
    {
        return $this->trials()->retrieveCardSession($tenant, $sessionId);
    }

    public function ensureCustomer(Tenant $tenant): ?string
    {
        return $this->trials()->ensureCustomer($tenant);
    }

    public function startProviderTrial(Tenant $tenant, ?string $paymentMethod, int $trialDays, ?string $product = null): void
    {
        $this->trials()->startProviderTrial($tenant, $paymentMethod, $trialDays, $product);
    }

    public function syncProviderTrials(Tenant $tenant, ?string $paymentMethod = null): int
    {
        return $this->trials()->syncProviderTrials($tenant, $paymentMethod);
    }

    public function recordSyncError(Tenant $tenant, string $step, \Throwable $e): void
    {
        $this->trials()->recordSyncError($tenant, $step, $e);
    }

    public function clearSyncError(Tenant $tenant, string $step): void
    {
        $this->trials()->clearSyncError($tenant, $step);
    }

    public function setProviderCancelAtPeriodEnd(Tenant $tenant, bool $cancel, ?string $product = null): void
    {
        $this->trials()->setProviderCancelAtPeriodEnd($tenant, $cancel, $product);
    }

    public function portalUrl(Tenant $tenant, string $returnUrl): string
    {
        return $this->trials()->portalUrl($tenant, $returnUrl);
    }
}
