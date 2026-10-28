<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;

/**
 * Collects a payment method without charging it, so a trial can start with a
 * card on file and convert to a paid subscription when the trial ends.
 */
interface CardCaptureGateway
{
    /** Hosted page that saves a card for the tenant. @return array{id: string, url: string} */
    public function createCardSession(Tenant $tenant, string $successUrl, string $cancelUrl): array;

    /** @return array{complete: bool, reference: string|null, payment_method: string|null} reference = the tenant id the session was made for */
    public function retrieveCardSession(string $sessionId): array;

    /**
     * Start billing at the end of the trial. With a saved card it is charged then; with NO card yet (a tenant created by an
     * administrator) the provider subscription still exists from day one but cancels itself at trial end unless a card
     * was attached by then (`setDefaultPaymentMethod`).
     *
     * @return array{subscription_id: string, period_end: int|null}
     */
    public function startTrialSubscription(Tenant $tenant, SubscriptionPlan $plan, string $currency, ?string $paymentMethod, int $trialDays): array;

    /** The tenant's customer record at the provider (created once, remembered in `tenants.billing_customer_id`). */
    public function ensureCustomer(Tenant $tenant): string;
}
