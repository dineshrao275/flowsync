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
    ) {}
}
