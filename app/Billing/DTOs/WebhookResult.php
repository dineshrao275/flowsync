<?php

namespace App\Billing\DTOs;

class WebhookResult
{
    public function __construct(
        public readonly bool $handled,
        public readonly string $eventType,
        public readonly ?string $providerEventId,
        public readonly ?string $providerPaymentId,
        public readonly ?string $providerOrderId,
        public readonly ?string $idempotencyKey,
        public readonly string $status,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly array $payload,
        public readonly ?string $failureReason = null,
        /** Subscription-lifecycle event, not tied to one checkout payment: invoice_paid|invoice_failed|subscription_updated|subscription_deleted. */
        public readonly ?string $kind = null,
        public readonly ?string $providerSubscriptionId = null,
        public readonly ?int $tenantId = null,
        public readonly ?int $periodEnd = null,
        public readonly ?bool $cancelAtPeriodEnd = null,
    ) {}
}
