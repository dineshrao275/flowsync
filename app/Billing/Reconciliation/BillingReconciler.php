<?php

declare(strict_types=1);

namespace App\Billing\Reconciliation;

use App\Billing\Gateways\RazorpayGateway;
use App\Billing\PaymentResolver;
use App\Billing\PaymentService;
use App\Billing\RecurringGateway;
use App\Billing\Stripe\BillingProviderException;
use App\Billing\SubscriptionBillingSync;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Brings the application and the payment providers back in line when a webhook was lost, a provider call failed halfway or
 * a process died between "provider did it" and "we wrote it down". A database transaction cannot undo a provider call, so
 * consistency is restored by comparing both sides and fixing the application's record (or, for a subscription the
 * application never recorded, cancelling the orphan). Every step is idempotent and safe to run any number of times.
 *
 *  1. customers   — a billable tenant with no provider customer gets one.
 *  2. trials      — a trialing paid subscription with no provider subscription gets one (card attached when known).
 *  3. subscriptions — each provider subscription is read back and its status/period mirrored locally (trial converted,
 *                    went past due, was cancelled in the dashboard).
 *  4. payments    — pending one-off checkouts (Razorpay) are asked about at the provider: paid → completed, abandoned → failed.
 */
class BillingReconciler
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentResolver $resolver,
        private readonly SubscriptionBillingSync $sync,
    ) {}

    /** @return array{customers: int, trials: int, subscriptions: int, payments: int, failed: int} */
    public function run(?Tenant $only = null): array
    {
        $result = ['customers' => 0, 'trials' => 0, 'subscriptions' => 0, 'payments' => 0, 'failed' => 0];

        $tenants = Tenant::query()
            ->whereIn('status', [Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE, Tenant::STATUS_EXPIRED, Tenant::STATUS_SUSPENDED])
            ->when($only, fn ($q) => $q->whereKey($only->id))
            ->orderBy('id')->get();

        foreach ($tenants as $tenant) {
            try {
                $this->tenant($tenant, $result);
            } catch (\Throwable $e) {
                $result['failed']++;
                Log::warning('Billing reconciliation failed for a tenant.', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }

        if (! $only) {
            $result['payments'] = $this->pendingPayments($result['failed']);
        }

        return $result;
    }

    /** @param  array<string, int>  $result */
    private function tenant(Tenant $tenant, array &$result): void
    {
        $subscriptions = $tenant->subscriptions()->with('plan')->get();
        $billable = $subscriptions->contains(fn (Subscription $s) => ($s->plan?->price_cents ?? 0) > 0);

        if ($billable && ! $tenant->billing_customer_id && $this->payments->ensureCustomer($tenant)) {
            $result['customers']++;
        }

        $result['trials'] += $this->payments->syncProviderTrials($tenant->refresh());

        foreach ($tenant->subscriptions()->whereNotNull('provider_subscription_id')->get() as $subscription) {
            $result['subscriptions'] += $this->mirror($tenant, $subscription) ? 1 : 0;
        }
    }

    /** Read the provider subscription back and mirror it. @return bool whether anything was looked at successfully */
    private function mirror(Tenant $tenant, Subscription $subscription): bool
    {
        $gateway = $this->resolver->resolve((string) ($subscription->billing_provider ?: 'stripe'));
        if (! $gateway instanceof RecurringGateway) {
            return false;
        }

        try {
            $remote = $gateway->retrieveSubscription($subscription->provider_subscription_id);
        } catch (BillingProviderException $e) {
            Log::warning('Could not read a provider subscription.', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);

            return false;
        }

        DB::connection(config('tenancy.system.connection'))->transaction(function () use ($subscription, $remote) {
            $subscription->refresh();
            $this->sync->mirrorStatus($subscription, (string) ($remote['status'] ?? ''), $remote);

            $end = $remote['current_period_end'] ?? $remote['items']['data'][0]['current_period_end'] ?? null;
            if ($end && ($subscription->current_period_end === null || $end > $subscription->current_period_end->timestamp)) {
                $subscription->update(['current_period_end' => Carbon::createFromTimestamp((int) $end)]);
            }
        });

        return true;
    }

    /**
     * Pending checkouts: ask the provider. Paid but never confirmed (lost webhook + closed tab) → complete it through the
     * same path as a verified payment; unpaid after a day → failed, so nothing sits "pending" forever.
     */
    private function pendingPayments(int &$failed): int
    {
        $fixed = 0;

        $pending = Payment::where('status', Payment::STATUS_PENDING)->where('created_at', '<=', now()->subMinutes(30))->with('tenant')->orderBy('id')->limit(200)->get();

        foreach ($pending as $payment) {
            try {
                $gateway = $this->resolver->resolve($payment->provider);
                $paid = $gateway instanceof RazorpayGateway && $payment->provider_order_id
                    ? $gateway->capturedPaymentForOrder($payment->provider_order_id)
                    : null;

                if ($paid && $payment->tenant) {
                    $this->payments->verifyPayment($payment->tenant, $payment, $paid->providerPaymentId, ['razorpay_order_id' => $payment->provider_order_id]);
                    $fixed++;
                } elseif ($payment->created_at->lte(now()->subDay())) {
                    $payment->update(['status' => Payment::STATUS_FAILED, 'failure_reason' => 'abandoned']);
                    PaymentEvent::create(['payment_id' => $payment->id, 'tenant_id' => $payment->tenant_id, 'type' => PaymentEvent::TYPE_PAYMENT_FAILED, 'provider' => $payment->provider, 'provider_event_id' => 'evt_abandoned_'.$payment->id, 'data' => ['reason' => 'abandoned']]);
                    $fixed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Could not reconcile a pending payment.', ['payment' => $payment->id, 'error' => $e->getMessage()]);
            }
        }

        return $fixed;
    }
}
