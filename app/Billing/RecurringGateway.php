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

    /** Move the subscription to the plan's price from the next invoice on, without prorating or charging now. */
    public function setSubscriptionPrice(string $providerSubscriptionId, SubscriptionPlan $plan, string $currency): void;

    /**
     * Queue a plan change for the end of the current period (a provider schedule: current plan until then, the new
     * one after — never both). Reuses an existing schedule when given/attached. Returns the schedule id.
     */
    public function scheduleChange(string $providerSubscriptionId, SubscriptionPlan $plan, string $currency, ?string $scheduleId = null): string;

    /** Drop a queued change; the subscription simply continues on its current plan. */
    public function releaseSchedule(string $scheduleId): void;

    /** Saved cards of the tenant's customer. @return list<array{id: string, brand: string, last4: string, exp_month: int, exp_year: int, is_default: bool}> */
    public function paymentMethods(Tenant $tenant): array;

    /** Make a saved card the one future invoices (and the given subscriptions) are charged to. @param  list<string>  $providerSubscriptionIds */
    public function setDefaultPaymentMethod(Tenant $tenant, string $paymentMethodId, array $providerSubscriptionIds = []): void;

    public function detachPaymentMethod(string $paymentMethodId): void;

    /** The provider's own record of a subscription (status, period, price) — used to reconcile after missed webhooks. @return array<string, mixed> */
    public function retrieveSubscription(string $providerSubscriptionId): array;
}
