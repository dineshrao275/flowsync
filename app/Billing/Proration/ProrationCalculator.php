<?php

namespace App\Billing\Proration;

use App\Billing\RecurringGateway;
use App\Models\InvoiceLine;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;

/**
 * P6.4 — proration for subscriptions FlowSync bills itself.
 *
 * Stripe prorates by itself (`proration_behavior=create_prorations` on a plan
 * change, see StripeGateway::changePlan), so this only runs for one-off
 * providers (Razorpay, the fake gateway). The model is the standard one: a
 * plan change starts a fresh period at the new plan's price, and the unused
 * share of what the tenant already paid for the old period is credited
 * against it. Only a subscription that really paid earns a credit — trials,
 * past-due and platform-assigned (provider-less) ones paid nothing.
 *
 * Seat changes have no financial effect today: plan prices are flat, there is
 * no per-seat price to prorate. The overage policy is `billing.overage`.
 */
class ProrationCalculator
{
    /** The charge floor: a gateway cannot take a zero or negative payment. */
    public const MIN_CHARGE_CENTS = 100;

    /**
     * What the unused part of the current paid period is worth, or null when
     * there is nothing to credit.
     *
     * @return array{credit_cents: int, ratio: float, old_plan: string}|null
     */
    public function credit(?Subscription $current, SubscriptionPlan $new): ?array
    {
        $old = $current?->plan;

        if (! config('billing.proration.enabled', true)
            || ! $current || ! $old
            || $current->status !== Subscription::STATUS_ACTIVE
            || ! $current->billing_provider
            || $old->price_cents <= 0
            || $old->id === $new->id
            || strtolower((string) $old->currency) !== strtolower((string) $new->currency)
            || ! $current->current_period_start || ! $current->current_period_end) {
            return null;
        }

        $total = $current->current_period_start->diffInSeconds($current->current_period_end);
        $left = now()->diffInSeconds($current->current_period_end, false);
        if ($total <= 0 || $left <= 0) {
            return null;
        }

        $ratio = min(1.0, $left / $total);
        $credit = (int) round($old->price_cents * $ratio);

        return $credit > 0 ? ['credit_cents' => $credit, 'ratio' => round($ratio, 4), 'old_plan' => $old->name] : null;
    }

    /**
     * The amount and invoice lines for a checkout that moves a paying tenant
     * to `$plan`, or null when the gateway prorates itself / nothing is owed back.
     *
     * @return array{amount_cents: int, lines: array<int, array<string, mixed>>}|null
     */
    public function forCheckout(Tenant $tenant, SubscriptionPlan $plan, object $gateway): ?array
    {
        if ($gateway instanceof RecurringGateway || $plan->price_cents <= 0) {
            return null;
        }

        $credit = $this->credit($tenant->subscriptionFor($plan->product ?? SubscriptionPlan::PRODUCT_SUITE), $plan);
        if (! $credit) {
            return null;
        }

        $applied = min($credit['credit_cents'], max(0, $plan->price_cents - self::MIN_CHARGE_CENTS));
        if ($applied <= 0) {
            return null;
        }

        return [
            'amount_cents' => $plan->price_cents - $applied,
            'lines' => [
                ['description' => $plan->name.' plan', 'amount_cents' => $plan->price_cents, 'kind' => InvoiceLine::KIND_SUBSCRIPTION],
                ['description' => 'Unused time on '.$credit['old_plan'], 'amount_cents' => -$applied, 'kind' => InvoiceLine::KIND_PRORATION],
            ],
        ];
    }
}
