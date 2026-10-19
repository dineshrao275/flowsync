<?php

namespace App\Billing\PriceChanges;

use App\Billing\Notices\BillingNotifier;
use App\Billing\PaymentResolver;
use App\Billing\RecurringGateway;
use App\Billing\Stripe\BillingProviderException;
use App\Models\PlanPriceChange;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What happens to people already paying when the super admin changes a plan's price.
 *
 *  - The plan row (and the Stripe price new customers get) changes at once.
 *  - Every existing subscriber of that plan gets a notice: new amount + the date it starts, which is their first renewal
 *    at least `billing.price_change_notice_days` after we told them (a decrease: their next renewal). The email goes
 *    out when the change is announced, before any renewal.
 *  - Their provider subscription keeps its old price until then; `apply()` (daily) moves it to the new price shortly
 *    before that renewal, without proration, so the renewal invoice carries the amount they were told.
 *
 * Idempotent: one notice per (change, subscription); a newer change supersedes a pending older one.
 */
class PlanPriceChanges
{
    public function __construct(
        private readonly BillingNotifier $notifier,
        private readonly PaymentResolver $resolver,
    ) {}

    /** How many paying subscriptions a price edit would reach — shown to the super admin before they confirm. */
    public function affectedCount(SubscriptionPlan $plan): int
    {
        return $this->subscribers($plan)->count();
    }

    public function announce(SubscriptionPlan $plan, int $oldCents, ?string $oldStripePriceId = null): ?PlanPriceChange
    {
        $newCents = (int) $plan->price_cents;
        if ($newCents === $oldCents || $oldCents <= 0 || $newCents <= 0) {
            return null; // free <-> paid is a different plan, not a price change; nobody is billed an amount they never agreed to
        }

        $subscribers = $this->subscribers($plan);
        $notice = $newCents > $oldCents ? (int) config('billing.price_change_notice_days', 30) : 0;

        $change = DB::connection(config('tenancy.system.connection'))->transaction(function () use ($plan, $oldCents, $newCents, $oldStripePriceId, $notice, $subscribers): PlanPriceChange {
            $change = PlanPriceChange::create([
                'plan_id' => $plan->id, 'old_price_cents' => $oldCents, 'new_price_cents' => $newCents,
                'currency' => strtolower((string) ($plan->currency ?: 'usd')), 'billing_cycle' => $plan->billing_cycle ?: 'monthly',
                'old_stripe_price_id' => $oldStripePriceId, 'new_stripe_price_id' => $plan->stripe_price_id,
                'notice_days' => $notice, 'announced_at' => now(),
            ]);

            foreach ($subscribers as $subscription) {
                // A still-pending earlier notice is replaced; what the subscriber pays TODAY is its old amount.
                $earlier = SubscriptionPriceNotice::where('subscription_id', $subscription->id)->where('status', SubscriptionPriceNotice::PENDING)->orderBy('id')->first();
                $paysNow = $earlier?->old_amount_cents ?? $oldCents;
                SubscriptionPriceNotice::where('subscription_id', $subscription->id)->where('status', SubscriptionPriceNotice::PENDING)->update(['status' => SubscriptionPriceNotice::CANCELLED]);

                SubscriptionPriceNotice::create([
                    'plan_price_change_id' => $change->id, 'subscription_id' => $subscription->id, 'tenant_id' => $subscription->tenant_id,
                    'old_amount_cents' => $paysNow, 'new_amount_cents' => $newCents, 'currency' => $change->currency,
                    'effective_at' => $this->effectiveAt($subscription, $plan, $notice), 'status' => SubscriptionPriceNotice::PENDING,
                ]);
            }

            return $change;
        });

        // Mail after commit: a slow mail server must not hold the price edit, and a failure never undoes it.
        foreach ($change->notices()->with(['change.plan', 'tenant'])->get() as $notice) {
            $this->notifier->priceChange($notice);
        }

        return $change;
    }

    /**
     * Move due subscriptions to their new price. Safe to run any number of times.
     *
     * @return array{applied: int, cancelled: int, failed: int}
     */
    public function apply(?Carbon $now = null): array
    {
        $now ??= now();
        $result = ['applied' => 0, 'cancelled' => 0, 'failed' => 0];

        $due = SubscriptionPriceNotice::where('status', SubscriptionPriceNotice::PENDING)->with(['change.plan', 'subscription.tenant'])->orderBy('effective_at')->get();

        foreach ($due as $notice) {
            $subscription = $notice->subscription;
            $plan = $notice->change?->plan;

            // No longer renewing at that plan: the notice has nothing left to do.
            if (! $subscription || ! $plan || $subscription->plan_id !== $plan->id || ! $subscription->auto_renew
                || in_array($subscription->status, [Subscription::STATUS_CANCELED, Subscription::STATUS_EXPIRED, Subscription::STATUS_ENDED], true)) {
                $notice->update(['status' => SubscriptionPriceNotice::CANCELLED]);
                $result['cancelled']++;

                continue;
            }

            $providerId = $subscription->provider_subscription_id;
            if ($providerId) {
                // One day ahead of the renewal, so the provider's next invoice already carries the new amount.
                if ($notice->effective_at->gt($now->copy()->addDay())) {
                    continue;
                }

                try {
                    $gateway = $this->resolver->resolve((string) ($subscription->billing_provider ?: 'stripe'));
                    if ($gateway instanceof RecurringGateway) {
                        $gateway->setSubscriptionPrice($providerId, $plan, $notice->currency);
                    }
                } catch (BillingProviderException $e) {
                    $result['failed']++;
                    Log::warning('Price change could not be applied at the provider; will retry.', ['notice' => $notice->id, 'error' => $e->getMessage()]);

                    continue;
                }
            } elseif ($notice->effective_at->gt($now)) {
                continue; // billed by FlowSync itself: the plan price already applies, from the date told
            }

            $notice->update(['status' => SubscriptionPriceNotice::APPLIED, 'applied_at' => $now]);
            $result['applied']++;
        }

        return $result;
    }

    /** The first renewal at least `$noticeDays` away: when the new amount starts for this subscriber. */
    private function effectiveAt(Subscription $subscription, SubscriptionPlan $plan, int $noticeDays): Carbon
    {
        $months = $plan->billing_cycle === 'annual' ? 12 : 1;
        $at = ($subscription->current_period_end ?? $subscription->trial_ends_at ?? now())->copy();
        $earliest = now()->addDays($noticeDays);

        for ($i = 0; $at->lt($earliest) && $i < 60; $i++) {
            $at = $at->addMonthsNoOverflow($months);
        }

        return $at;
    }

    /**
     * Subscriptions that will be billed this plan's price again: paying or about to (trial with a card, past due).
     *
     * @return Collection<int, Subscription>
     */
    private function subscribers(SubscriptionPlan $plan): Collection
    {
        return Subscription::where('plan_id', $plan->id)
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_PAST_DUE])
            ->where('auto_renew', true)
            // Someone is actually billing them: a provider subscription or a provider payment (platform-assigned plans pay nothing).
            ->where(fn ($q) => $q->whereNotNull('provider_subscription_id')->orWhereNotNull('billing_provider'))
            ->with('tenant')->get()
            // A trial without a card on file pays whatever the price is when it converts: nothing to announce.
            ->filter(fn (Subscription $s) => $s->tenant !== null && ($s->status !== Subscription::STATUS_TRIALING || $s->provider_subscription_id !== null))->values();
    }
}
