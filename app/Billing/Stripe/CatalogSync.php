<?php

namespace App\Billing\Stripe;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;

/**
 * Keeps Stripe's catalog equal to ours: one Product per plan, one active Price per (amount, currency, interval) on it,
 * one Customer per tenant. Prices are immutable at Stripe, so a changed amount creates a NEW price, makes it the
 * default and archives the old one (subscriptions already on the old price keep billing at it until they are moved).
 * A deactivated plan archives its product and price: nobody new can buy it, existing subscriptions are untouched.
 *
 * Every call is idempotent — rerunning after a failure, or two workers at once, ends in the same state.
 */
class CatalogSync
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function enabled(): bool
    {
        return $this->stripe->configured();
    }

    /** Bring the plan's Stripe product and price in line with the plan row. */
    public function syncPlan(SubscriptionPlan $plan): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            // A free plan never reaches Stripe: nothing is charged and checkout is not used for it.
            if ((int) $plan->price_cents > 0) {
                $this->ensureProduct($plan);
                $plan->is_active ? $this->ensurePrice($plan, strtolower((string) ($plan->currency ?: 'usd'))) : $this->archivePrice($plan);
            }

            $plan->forceFill(['stripe_synced_at' => now(), 'stripe_sync_error' => null])->save();
        } catch (BillingProviderException $e) {
            $plan->forceFill(['stripe_sync_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            throw $e;
        }
    }

    /** The Stripe Price id to charge for this plan in `$currency`, creating product/price when missing or stale. */
    public function priceFor(SubscriptionPlan $plan, string $currency): string
    {
        // Fast path: already in sync, no Stripe round trip on every checkout.
        if ($plan->stripe_price_id && $plan->stripe_price_key === $this->priceKey($plan, strtolower($currency)) && $plan->is_active) {
            return $plan->stripe_price_id;
        }

        $this->ensureProduct($plan);

        return $this->ensurePrice($plan, strtolower($currency));
    }

    /** Archive what a deleted plan left at Stripe. */
    public function archive(?string $productId, ?string $priceId): void
    {
        if (! $this->enabled()) {
            return;
        }
        foreach (['/prices/' => $priceId, '/products/' => $productId] as $path => $id) {
            if ($id) {
                try {
                    $this->stripe->request('post', $path.$id, ['active' => 'false']);
                } catch (BillingProviderException $e) {
                    if (! $e->isMissing()) {
                        throw $e;
                    }
                }
            }
        }
    }

    private function ensureProduct(SubscriptionPlan $plan): string
    {
        $params = array_filter([
            'name' => "FlowSync {$plan->name} Plan",
            'description' => $plan->description ?: null,
            'active' => $plan->is_active ? 'true' : 'false',
            'metadata[plan_id]' => (string) $plan->id,
            'metadata[slug]' => $plan->slug,
            'metadata[product]' => $plan->product ?? 'suite',
        ], fn ($v) => $v !== null);

        if ($plan->stripe_product_id) {
            try {
                $this->stripe->request('post', "/products/{$plan->stripe_product_id}", $params, "plan_{$plan->id}_product_update");

                return $plan->stripe_product_id;
            } catch (BillingProviderException $e) {
                if (! $e->isMissing()) {
                    throw $e;
                }
                // Deleted at Stripe: fall through and create a fresh one.
            }
        }

        $product = $this->stripe->request('post', '/products', $params, "plan_{$plan->id}_product");
        $plan->forceFill(['stripe_product_id' => $product['id'], 'stripe_price_id' => null, 'stripe_price_key' => null])->save();

        return $product['id'];
    }

    private function ensurePrice(SubscriptionPlan $plan, string $currency): string
    {
        $interval = $plan->billing_cycle === 'annual' ? 'year' : 'month';
        $key = $this->priceKey($plan, $currency);

        if ($plan->stripe_price_id && $plan->stripe_price_key === $key) {
            // Re-activate it if the plan was deactivated and is back on sale.
            if ($plan->is_active) {
                $this->setPriceActive($plan->stripe_price_id, true);
            }

            return $plan->stripe_price_id;
        }

        $old = $plan->stripe_price_id;
        $price = $this->stripe->request('post', '/prices', [
            'product' => $plan->stripe_product_id,
            'currency' => $currency,
            'unit_amount' => $plan->price_cents,
            'recurring[interval]' => $interval,
            'metadata[plan_id]' => (string) $plan->id,
        ], "plan_{$plan->id}_price_{$key}_after_".($old ?: 'none')); // per transition: reverting to an earlier amount must create a fresh price, not replay the archived one

        $plan->forceFill(['stripe_price_id' => $price['id'], 'stripe_price_key' => $key])->save();

        $this->stripe->request('post', "/products/{$plan->stripe_product_id}", ['default_price' => $price['id']], "plan_{$plan->id}_default_{$price['id']}");
        if ($old && $old !== $price['id']) {
            $this->setPriceActive($old, false);
        }

        return $price['id'];
    }

    private function priceKey(SubscriptionPlan $plan, string $currency): string
    {
        return implode(':', [$plan->price_cents, $currency, $plan->billing_cycle === 'annual' ? 'year' : 'month']);
    }

    private function archivePrice(SubscriptionPlan $plan): void
    {
        $this->ensureProduct($plan); // pushes active=false to the product
        if ($plan->stripe_price_id) {
            $this->setPriceActive($plan->stripe_price_id, false);
        }
    }

    private function setPriceActive(string $priceId, bool $active): void
    {
        try {
            $this->stripe->request('post', "/prices/{$priceId}", ['active' => $active ? 'true' : 'false']);
        } catch (BillingProviderException $e) {
            if (! $e->isMissing()) {
                throw $e;
            }
        }
    }

    /**
     * The tenant's one Stripe Customer: created from the organisation's name on first need, and its name / billing
     * email kept in step afterwards. Returns the customer id.
     */
    public function customerFor(Tenant $tenant, ?string $fallbackEmail = null): string
    {
        $params = array_filter([
            'name' => $tenant->name,
            'email' => $tenant->billing_email ?: $fallbackEmail,
            'metadata[tenant_id]' => (string) $tenant->id,
            'metadata[slug]' => $tenant->slug,
        ]);

        if ($tenant->billing_customer_id) {
            return $tenant->billing_customer_id;
        }

        // Two requests racing to the first checkout must end with ONE customer.
        $customer = $this->stripe->request('post', '/customers', $params, "tenant_{$tenant->id}_customer");
        $tenant->forceFill(['billing_customer_id' => $customer['id']])->save();

        return $customer['id'];
    }

    /** Push the tenant's current name / billing email to its existing customer. */
    public function syncCustomer(Tenant $tenant): void
    {
        if (! $this->enabled() || ! $tenant->billing_customer_id) {
            return;
        }

        $this->stripe->request('post', "/customers/{$tenant->billing_customer_id}", array_filter([
            'name' => $tenant->name,
            'email' => $tenant->billing_email,
        ]), 'tenant_'.$tenant->id.'_customer_sync');
    }
}
