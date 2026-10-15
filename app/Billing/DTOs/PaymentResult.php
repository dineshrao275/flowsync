<?php

namespace App\Billing\DTOs;

class PaymentResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerPaymentId,
        public readonly ?string $providerOrderId,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?int $feeCents = null,
        public readonly array $metadata = [],
        public readonly ?string $errorMessage = null,
    ) {}
}
