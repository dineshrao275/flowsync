<?php

namespace App\Billing;

use App\Billing\DTOs\WebhookResult;
use App\Billing\Invoices\InvoiceService;
use App\Billing\Notices\BillingNotifier;
use App\Billing\Plans\PlanChangeScheduler;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Support\Carbon;

/**
 * Applies a recurring-billing webhook (renewal paid, renewal failed, provider
 * cancelled/changed the subscription) to the local subscription. Replay is
 * already screened by the caller via the provider event id.
 */
class SubscriptionBillingSync
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly InvoiceService $invoices,
        private readonly BillingNotifier $notifier,
        private readonly PlanChangeScheduler $scheduler,
        private readonly PaymentMethods $methods,
    ) {}

    /** @return array<string, mixed> */
    public function handle(WebhookResult $event, string $provider): array
    {
        // A card event is about the customer, not a subscription.
        if ($event->kind === 'payment_method') {
            return $this->paymentMethodEvent($event, $provider);
        }

        $subscription = $event->providerSubscriptionId
            ? Subscription::where('provider_subscription_id', $event->providerSubscriptionId)->first()
            : null;
        $tenant = $subscription?->tenant
            ?? ($event->tenantId ? Tenant::find($event->tenantId) : null)
            ?? ($event->providerCustomerId ? Tenant::where('billing_customer_id', $event->providerCustomerId)->first() : null);

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
            'trial_will_end' => $this->trialWillEnd($tenant, $event),
            'action_required' => $this->notifier->paymentActionRequired($tenant),
            'schedule_ended' => $this->scheduleEnded($tenant, $subscription),
            default => null,
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

    /** @return array<string, mixed> */
    private function paymentMethodEvent(WebhookResult $event, string $provider): array
    {
        $object = $event->payload['data']['object'] ?? [];
        $tenant = $event->providerCustomerId ? Tenant::where('billing_customer_id', $event->providerCustomerId)->first() : null;

        // A detach clears `customer`; match the cached default card by id instead.
        $tenant ??= Tenant::whereNotNull('billing_customer_id')->get()->first(fn (Tenant $t) => ($t->settings['billing']['card']['id'] ?? null) === ($object['id'] ?? ''));
        if (! $tenant) {
            return ['status' => 'tenant_not_found', 'event_type' => $event->eventType];
        }

        $cached = $tenant->settings['billing']['card'] ?? null;
        $card = $object['card'] ?? [];
        if ($event->eventType === 'payment_method.detached') {
            if (($cached['id'] ?? null) === ($object['id'] ?? null)) {
                $this->methods->remember($tenant, null);
            }
        } elseif (($cached['id'] ?? null) === ($object['id'] ?? null)) { // updated / auto-updated: refresh the default card's expiry
            $this->methods->remember($tenant, ['id' => $object['id'], 'brand' => (string) ($card['brand'] ?? 'card'), 'last4' => (string) ($card['last4'] ?? ''), 'exp_month' => (int) ($card['exp_month'] ?? 0), 'exp_year' => (int) ($card['exp_year'] ?? 0)]);
        }

        PaymentEvent::create(['payment_id' => null, 'tenant_id' => $tenant->id, 'type' => $event->eventType, 'provider' => $provider, 'provider_event_id' => $event->providerEventId, 'data' => $event->payload]);

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

        // A late duplicate of an older invoice must not renew again or shrink the period: the payment/invoice rows above
        // are idempotent, the period only ever moves forward.
        // (`invoice.paid` and `invoice.payment_succeeded` both describe one invoice; the second finds the payment already there.)
        $stale = ! $payment->wasRecentlyCreated
            || ($event->periodEnd !== null && $subscription->current_period_end !== null && $event->periodEnd < $subscription->current_period_end->timestamp - 60);
        if ($stale) {
            $this->invoice($payment, $subscription, $event);

            return $payment->id;
        }

        $renewed = $this->subscriptions->renew($tenant, null, $subscription->product);
        // For a paid invoice the provider's period is authoritative (renew() only estimated it).
        if ($event->periodEnd) {
            $renewed->update(['current_period_end' => Carbon::createFromTimestamp($event->periodEnd)]);
        }
        $this->invoice($payment, $renewed, $event);

        return $payment->id;
    }

    /** The renewal's invoice, itemised the way the provider itemised it (proration lines included). */
    private function invoice(Payment $payment, Subscription $subscription, WebhookResult $event): void
    {
        $object = $event->payload['data']['object'] ?? [];
        $lines = collect($object['lines']['data'] ?? [])->map(fn (array $line): array => [
            'description' => $line['description'] ?? ($subscription->plan?->name ?? 'Subscription'),
            'amount_cents' => (int) ($line['amount'] ?? 0),
            'quantity' => (int) ($line['quantity'] ?? 1),
            'kind' => ($line['proration'] ?? false) ? 'proration' : 'subscription',
        ])->values()->all();

        $period = $object['lines']['data'][0]['period'] ?? [];

        $invoice = $this->invoices->issueForPayment(
            $payment,
            $lines ?: null,
            $event->providerOrderId,
            isset($period['start']) ? Carbon::createFromTimestamp($period['start']) : null,
            isset($period['end']) ? Carbon::createFromTimestamp($period['end']) : null,
            (int) ($object['tax'] ?? 0),
        );

        // Where the customer can see / download the provider's own invoice and receipt.
        $links = array_filter([
            'hosted_invoice_url' => $object['hosted_invoice_url'] ?? null,
            'invoice_pdf' => $object['invoice_pdf'] ?? null,
            'receipt_number' => $object['receipt_number'] ?? null,
        ]);
        if ($links !== []) {
            $invoice->update(['metadata' => array_merge((array) $invoice->metadata, $links)]);
        }
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
        // The provider moved the subscription onto the price we queued (a schedule phase starting): mirror it.
        $object = $event->payload['data']['object'] ?? [];
        $price = $object['items']['data'][0]['price'] ?? null;
        $priceId = is_array($price) ? ($price['id'] ?? null) : $price;
        $mirrored = $this->scheduler->mirror(
            $subscription,
            $priceId,
            $object['current_period_start'] ?? $object['items']['data'][0]['current_period_start'] ?? null,
            $event->periodEnd,
        );
        if ($mirrored) {
            return;
        }

        $this->applyPeriodEnd($subscription, $event->periodEnd);

        if ($event->cancelAtPeriodEnd !== null) {
            $subscription->update(['auto_renew' => ! $event->cancelAtPeriodEnd]);
        }
    }

    private function deleted(Tenant $tenant, Subscription $subscription): void
    {
        $this->subscriptions->cancel($tenant, null, $subscription->product);
    }

    /** Only ever moves the period forward: webhooks arrive late and out of order, an old one must not shrink it. */
    private function applyPeriodEnd(Subscription $subscription, ?int $timestamp): void
    {
        if (! $timestamp) {
            return;
        }

        $end = Carbon::createFromTimestamp($timestamp);
        if ($subscription->current_period_end === null || $end->gt($subscription->current_period_end)) {
            $subscription->update(['current_period_end' => $end]);
        }
    }

    private function trialWillEnd(Tenant $tenant, WebhookResult $event): void
    {
        if ($event->periodEnd) {
            $this->notifier->trialEnding($tenant, Carbon::createFromTimestamp($event->periodEnd));
        }
    }

    /** The provider dropped the queued change (portal, dashboard, abort): forget it locally too. */
    private function scheduleEnded(Tenant $tenant, Subscription $subscription): void
    {
        if ($subscription->hasPendingChange()) {
            $this->scheduler->cancelPending($tenant, $subscription->product);
        }
    }
}
