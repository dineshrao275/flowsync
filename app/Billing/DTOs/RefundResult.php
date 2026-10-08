<?php

namespace App\Billing\DTOs;

class RefundResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $refundId,
        public readonly int $amountCents,
        public readonly string $status,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = [],
    ) {}
}
