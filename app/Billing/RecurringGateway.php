<?php

namespace App\Billing;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;

/**
 * A gateway that bills a plan on a schedule and can be managed after checkout
 * (change plan, cancel at period end, customer portal). Only Stripe implements
 * it today; one-off gateways keep the plain checkout flow.
 */
interface RecurringGateway
{
    /** Move an existing provider subscription to the plan's price (prorated). */
    public function changePlan(string $providerSubscriptionId, SubscriptionPlan $plan, string $currency): void;

    /** Stop renewing at the end of the paid period (or resume with $cancel = false). */
    public function setCancelAtPeriodEnd(string $providerSubscriptionId, bool $cancel): void;

    /** Hosted page where the customer updates their card and sees invoices. */
    public function portalUrl(Tenant $tenant, string $returnUrl): string;
}
