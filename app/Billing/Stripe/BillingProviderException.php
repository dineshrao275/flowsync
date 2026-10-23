<?php

declare(strict_types=1);

namespace App\Billing\Stripe;

use RuntimeException;

/**
 * Stripe could not be reached or refused a call. `retryable` separates "try again later" (network, 429, 5xx — jobs retry,
 * requests answer 503) from "this request is wrong" (4xx — retrying cannot help). The provider's own wording stays in
 * the log and `getMessage()`; it is never shown to a tenant.
 */
class BillingProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly ?int $status = null, public readonly ?string $stripeCode = null)
    {
        parent::__construct($message);
    }

    public function isMissing(): bool
    {
        return $this->status === 404 || $this->stripeCode === 'resource_missing';
    }
}
