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

    /** Whether the gateway that would bill this tenant can save a card for a trial. */
    public function canCaptureCard(Tenant $tenant): bool
    {
        return $this->resolver->resolveForTenant($tenant) instanceof CardCaptureGateway;
    }

    /** @return array{id: string, url: string} */
    public function createCardSession(Tenant $tenant, string $successUrl, string $cancelUrl): array
    {
        $gateway = $this->resolver->resolveForTenant($tenant);
        if (! $gateway instanceof CardCaptureGateway) {
            throw new RuntimeException('Card capture is not available for this payment provider.');
        }

        return $gateway->createCardSession($tenant, $successUrl, $cancelUrl);
    }

    /** @return array{complete: bool, reference: string|null, payment_method: string|null} */
    public function retrieveCardSession(Tenant $tenant, string $sessionId): array
    {
        $gateway = $this->resolver->resolveForTenant($tenant);

        return $gateway instanceof CardCaptureGateway
            ? $gateway->retrieveCardSession($sessionId)
            : ['complete' => false, 'reference' => null, 'payment_method' => null];
    }

    /**
     * Create the tenant's customer record at the provider (idempotent: a tenant has one). Never throws — a provider
     * hiccup must not undo a provisioned tenant — but a failure is remembered on the tenant so `billing:reconcile` retries it.
     */
    public function ensureCustomer(Tenant $tenant): ?string
    {
        $gateway = $this->resolver->resolveForTenant($tenant);
        if (! $gateway instanceof CardCaptureGateway) {
            return null;
        }

        try {
            $id = $gateway->ensureCustomer($tenant);
            $this->clearSyncError($tenant, 'customer');

            return $id;
        } catch (\Throwable $e) {
            $this->recordSyncError($tenant, 'customer', $e);

            return null;
        }
    }

    /**
     * After sign-up / tenant creation: have the provider bill the plan when the trial ends. `$paymentMethod` may be null (a
     * tenant created by an administrator) — the provider subscription then cancels itself at trial end unless a card arrives.
     *
     * Provider first, local second: if saving the reference fails after the provider created the subscription, the provider
     * subscription is cancelled again (compensation) so no billable subscription exists that the application does not know.
     */
    public function startProviderTrial(Tenant $tenant, ?string $paymentMethod, int $trialDays, ?string $product = null): void
    {
        $subscription = $product ? $tenant->subscriptionFor($product) : $tenant->subscription;
        $gateway = $this->resolver->resolveForTenant($tenant);
        if (! $subscription?->plan || $subscription->plan->price_cents <= 0 || ! $gateway instanceof CardCaptureGateway
            || $subscription->provider_subscription_id) {
            return;
        }

        $result = $gateway->startTrialSubscription($tenant, $subscription->plan, $subscription->plan->currency ?? 'usd', $paymentMethod, max(1, $trialDays));

        try {
            $subscription->update([
                'billing_provider' => $gateway->name(),
                'provider_subscription_id' => $result['subscription_id'],
            ]);
        } catch (\Throwable $e) {
            if ($gateway instanceof RecurringGateway) {
                try {
                    $gateway->cancelSubscription($result['subscription_id']);
                } catch (\Throwable $undo) {
                    Log::critical('Provider subscription exists that the database does not know — cancel it by hand.', ['tenant_id' => $tenant->id, 'provider_subscription_id' => $result['subscription_id'], 'error' => $undo->getMessage()]);
                }
            }

            throw $e;
        }
    }

    /**
     * Give every trialing subscription of the tenant a provider subscription (and attach the card): the repair for a
     * provisioning-time provider failure and the step that follows a card being added to a card-less trial.
     */
    public function syncProviderTrials(Tenant $tenant, ?string $paymentMethod = null): int
    {
        $started = 0;

        foreach ($tenant->subscriptions()->where('status', Subscription::STATUS_TRIALING)->whereNull('provider_subscription_id')->with('plan')->get() as $subscription) {
            $days = $subscription->trial_ends_at ? (int) ceil(now()->diffInHours($subscription->trial_ends_at, false) / 24) : 0;
            if ($days < 1 || ($subscription->plan?->price_cents ?? 0) <= 0) {
                continue;
            }

            try {
                $this->startProviderTrial($tenant, $paymentMethod, $days, $subscription->product);
                $started++;
                $this->clearSyncError($tenant, 'trial');
            } catch (\Throwable $e) {
                $this->recordSyncError($tenant, 'trial', $e);
            }
        }

        return $started;
    }

    public function recordSyncError(Tenant $tenant, string $step, \Throwable $e): void
    {
        Log::warning("Billing provider sync failed at [{$step}].", ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);

        $settings = (array) $tenant->settings;
        $settings['billing']['sync_errors'][$step] = ['message' => Str::limit($e->getMessage(), 200), 'at' => now()->toIso8601String()];
        $tenant->forceFill(['settings' => $settings])->saveQuietly();
    }

    public function clearSyncError(Tenant $tenant, string $step): void
    {
        $settings = (array) $tenant->settings;
        if (isset($settings['billing']['sync_errors'][$step])) {
            unset($settings['billing']['sync_errors'][$step]);
            $tenant->forceFill(['settings' => $settings])->saveQuietly();
        }
    }

    /** Stop (or resume) renewal at the provider; a no-op for subscriptions it does not bill. */
    public function setProviderCancelAtPeriodEnd(Tenant $tenant, bool $cancel, ?string $product = null): void
    {
        $subscription = $product ? $tenant->subscriptionFor($product) : $tenant->subscription;
        $gateway = $subscription?->billing_provider ? $this->resolver->resolve($subscription->billing_provider) : null;

        if ($gateway instanceof RecurringGateway && $subscription->provider_subscription_id) {
            $gateway->setCancelAtPeriodEnd($subscription->provider_subscription_id, $cancel);
        }
    }

    /** Hosted billing portal (update card, see invoices) for a tenant billed through a recurring gateway. */
    public function portalUrl(Tenant $tenant, string $returnUrl): string
    {
        $gateway = $this->resolver->resolveForTenant($tenant);
        if (! $gateway instanceof RecurringGateway) {
            throw new RuntimeException('The billing portal is not available for this payment provider.');
        }

        return $gateway->portalUrl($tenant, $returnUrl);
    }
}
