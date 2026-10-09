<?php

namespace App\Billing;

use App\Billing\DTOs\WebhookResult;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Carbon\Carbon;

/**
 * Applies a recurring-billing webhook (renewal paid, renewal failed, provider
 * cancelled/changed the subscription) to the local subscription. Replay is
 * already screened by the caller via the provider event id.
 */
class SubscriptionBillingSync
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /** @return array<string, mixed> */
    public function handle(WebhookResult $event, string $provider): array
    {
        $subscription = $event->providerSubscriptionId
            ? Subscription::where('provider_subscription_id', $event->providerSubscriptionId)->first()
            : null;
        $tenant = $subscription?->tenant ?? ($event->tenantId ? Tenant::find($event->tenantId) : null);

        $subscription ??= $tenant?->subscription;
        if (! $tenant || ! $subscription) {
            return ['status' => 'subscription_not_found', 'event_type' => $event->eventType];
        }

        // The metadata fallback only finds the tenant; remember the provider id.
        if ($event->providerSubscriptionId && ! $subscription->provider_subscription_id) {
            $subscription->update(['provider_subscription_id' => $event->providerSubscriptionId, 'billing_provider' => $provider]);
        }

        $paymentId = null;
        match ($event->kind) {
            'invoice_paid' => $paymentId = $this->renewed($tenant, $subscription, $event, $provider),
            'invoice_failed' => $this->failed($tenant, $subscription, $event),
            'subscription_updated' => $this->updated($subscription, $event),
            'subscription_deleted' => $this->deleted($tenant, $subscription),
        };

        PaymentEvent::create([
            'payment_id' => $paymentId,
            'tenant_id' => $tenant->id,
            'type' => $event->eventType,
            'provider' => $provider,
            'provider_event_id' => $event->providerEventId,
            'data' => $event->payload,
        ]);

        return ['status' => 'processed', 'event_type' => $event->eventType, 'kind' => $event->kind];
    }

    private function renewed(Tenant $tenant, Subscription $subscription, WebhookResult $event, string $provider): ?int
    {
        // One history row per invoice; a replayed invoice must not double up.
        $payment = Payment::firstOrCreate(
            ['provider' => $provider, 'provider_order_id' => $event->providerOrderId],
            [
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'provider_payment_id' => $event->providerPaymentId,
                'amount_cents' => $event->amountCents,
                'currency' => $event->currency,
                'status' => Payment::STATUS_COMPLETED,
                'idempotency_key' => 'inv_'.$event->providerOrderId,
                'metadata' => [
                    'plan_id' => $subscription->plan_id,
                    'plan_name' => $subscription->plan?->name,
                    'renewal' => true,
                ],
            ],
        );

        $renewed = $this->subscriptions->renew($tenant, null, $subscription->product);
        $this->applyPeriodEnd($renewed, $event->periodEnd);

        return $payment->id;
    }

    private function failed(Tenant $tenant, Subscription $subscription, WebhookResult $event): void
    {
        $this->subscriptions->suspend($tenant, ['data' => [
            'failure_reason' => $event->failureReason,
            'invoice' => $event->providerOrderId,
        ]], null, $subscription->product);
    }

    private function updated(Subscription $subscription, WebhookResult $event): void
    {
        $this->applyPeriodEnd($subscription, $event->periodEnd);

        if ($event->cancelAtPeriodEnd !== null) {
            $subscription->update(['auto_renew' => ! $event->cancelAtPeriodEnd]);
        }
    }

    private function deleted(Tenant $tenant, Subscription $subscription): void
    {
        $this->subscriptions->cancel($tenant, null, $subscription->product);
    }

    private function applyPeriodEnd(Subscription $subscription, ?int $timestamp): void
    {
        if ($timestamp) {
            $subscription->update(['current_period_end' => Carbon::createFromTimestamp($timestamp)]);
        }
    }
}
