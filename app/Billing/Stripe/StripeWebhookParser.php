<?php

declare(strict_types=1);

namespace App\Billing\Stripe;

use App\Billing\DTOs\WebhookResult;
use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * Stripe webhook intake: verifies the `Stripe-Signature` header (HMAC over `t.body` with a
 * five-minute replay window) and maps an event onto the provider-neutral WebhookResult.
 * Pure: no HTTP calls and no database, so it is unit-testable from a raw request.
 */
class StripeWebhookParser
{
    public function __construct(private readonly string $webhookSecret) {}

    public function verifySignature(Request $request): bool
    {
        $signature = $request->header('Stripe-Signature');
        $secret = $this->webhookSecret;

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

    public function parse(Request $request): WebhookResult
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
            'customer.subscription.updated', 'customer.subscription.created' => 'subscription_updated',
            'customer.created', 'customer.updated' => 'customer',
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
            $isCustomer = $kind === 'customer';
            $subscriptionId = match (true) {
                $isCustomer => null,
                $isInvoice => $object['subscription'] ?? $object['parent']['subscription_details']['subscription'] ?? null,
                $isSchedule => $object['released_subscription'] ?? $object['subscription'] ?? null,
                $isPaymentMethod => null,
                default => $object['id'] ?? null,
            };
            $metadata = $isInvoice
                ? ($object['subscription_details']['metadata'] ?? $object['parent']['subscription_details']['metadata'] ?? [])
                : ($object['metadata'] ?? []);
            $customerId = $isCustomer ? ($object['id'] ?? null) : (is_string($object['customer'] ?? null) ? $object['customer'] : null);

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
                    : ($kind === 'trial_will_end' ? ($object['trial_end'] ?? null) : self::periodEndOf($object)),
                cancelAtPeriodEnd: ($isInvoice || $isSchedule || $isPaymentMethod || $isCustomer) ? null : (bool) ($object['cancel_at_period_end'] ?? false),
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

    /** Current-period end as a unix timestamp; Stripe moved it between API versions. */
    public static function periodEndOf(array $subscription): ?int
    {
        return $subscription['current_period_end']
            ?? $subscription['items']['data'][0]['current_period_end']
            ?? null;
    }
}
