<?php

namespace App\Billing\Plans;

use App\Billing\BillingLock;
use App\Billing\Notices\BillingNotifier;
use App\Billing\PaymentResolver;
use App\Billing\RecurringGateway;
use App\Billing\Stripe\BillingProviderException;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Upgrades and downgrades for someone who is already paying: the change waits for the end of the paid period.
 *
 * Why not "switch now": the current period is paid. Swapping the price mid-period either prorates (a second, partial
 * charge) or overlaps two plans. Queuing the change means the old plan runs to its last day, the new one starts the next
 * day on the same billing boundary, and exactly one invoice is raised per period — no overlap, no duplicate charge, no
 * proration to explain. With Stripe the queue is a subscription schedule, so Stripe itself performs the swap even if our
 * servers are down; the webhook (or the daily sweep) then mirrors it locally.
 *
 * What is NOT queued (applied at once): a trial, a free plan, an expired/cancelled subscription, or one nobody bills —
 * there is nothing paid to protect, and no charge to double.
 */
class PlanChangeScheduler
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PaymentResolver $resolver,
        private readonly BillingNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $eventData  extra audit data for the subscription event
     * @return array{mode: 'immediate'|'scheduled'|'cleared', subscription: Subscription}
     */
    public function request(Tenant $tenant, SubscriptionPlan $plan, array $eventData = []): array
    {
        return BillingLock::for($tenant, function () use ($tenant, $plan, $eventData): array {
            $product = $plan->product ?? SubscriptionPlan::PRODUCT_SUITE;
            $current = $tenant->subscriptionFor($product);

            // Choosing the plan you are already on withdraws a queued change.
            if ($current && $current->plan_id === $plan->id) {
                return ['mode' => 'cleared', 'subscription' => $current->hasPendingChange() ? $this->clear($tenant, $current) : $current];
            }

            if (! $plan->is_active) {
                throw ValidationException::withMessages(['plan_id' => __('That plan is not currently available.')]);
            }

            if (! $current || ! $this->shouldSchedule($current)) {
                if ($current?->hasPendingChange()) {
                    $this->clear($tenant, $current);
                }

                return ['mode' => 'immediate', 'subscription' => $this->subscriptions->switch($tenant, $plan, ['data' => $eventData])];
            }

            return ['mode' => 'scheduled', 'subscription' => $this->schedule($tenant, $current, $plan, $eventData)];
        });
    }

    /** Whether switching to `$plan` would be queued for the period end (vs applied at once). */
    public function wouldSchedule(Tenant $tenant, SubscriptionPlan $plan): bool
    {
        $current = $tenant->subscriptionFor($plan->product ?? SubscriptionPlan::PRODUCT_SUITE);

        return $current !== null && $current->plan_id !== $plan->id && $this->shouldSchedule($current);
    }

    /** Withdraw the queued change (a no-op when none is queued). */
    public function cancelPending(Tenant $tenant, ?string $product = null): Subscription
    {
        return BillingLock::for($tenant, function () use ($tenant, $product): Subscription {
            $subscription = $product ? $tenant->subscriptionFor($product) : $tenant->subscription()->first();
            if (! $subscription) {
                abort(422, __('Tenant has no subscription.'));
            }

            return $subscription->hasPendingChange() ? $this->clear($tenant, $subscription) : $subscription;
        });
    }

    /**
     * Daily sweep: apply changes whose date has come. Provider-queued changes are normally mirrored by the webhook; this
     * catches a missed one by asking the provider what the subscription is on now. Changes nobody bills are applied here.
     *
     * @return array{applied: int, waiting: int, failed: int}
     */
    public function applyDue(?Carbon $now = null): array
    {
        $now ??= now();
        $result = ['applied' => 0, 'waiting' => 0, 'failed' => 0];

        $due = Subscription::whereNotNull('pending_plan_id')->where('pending_change_at', '<=', $now)->with(['tenant', 'pendingPlan', 'plan'])->get();

        foreach ($due as $subscription) {
            try {
                BillingLock::for($subscription->tenant_id, function () use ($subscription, $now, &$result): void {
                    $subscription->refresh();
                    if (! $subscription->hasPendingChange()) {
                        return;
                    }

                    if ($subscription->provider_subscription_id) {
                        $remote = $this->gateway($subscription)?->retrieveSubscription($subscription->provider_subscription_id);
                        if ($remote !== null && $this->priceOf($remote) === $subscription->pendingPlan?->stripe_price_id) {
                            $this->subscriptions->applyScheduledChange($subscription, $this->ts($remote['current_period_start'] ?? $remote['items']['data'][0]['current_period_start'] ?? null), $this->ts($remote['current_period_end'] ?? $remote['items']['data'][0]['current_period_end'] ?? null));
                            $result['applied']++;
                        } else {
                            $result['waiting']++; // the provider has not performed the swap yet — never apply ahead of it
                        }

                        return;
                    }

                    $this->subscriptions->applyScheduledChange($subscription, $now->copy(), null);
                    $result['applied']++;
                });
            } catch (BillingProviderException $e) {
                $result['failed']++;
                Log::warning('Could not reconcile a scheduled plan change.', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);
            } catch (\Throwable $e) {
                $result['failed']++;
                Log::error('Scheduled plan change failed.', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /** The provider has moved the subscription to `$priceId`: mirror it locally when that is the change we queued. */
    public function mirror(Subscription $subscription, ?string $priceId, ?int $periodStart, ?int $periodEnd): bool
    {
        if (! $subscription->hasPendingChange() || ! $priceId || $subscription->pendingPlan?->stripe_price_id !== $priceId) {
            return false;
        }

        $this->subscriptions->applyScheduledChange($subscription, $this->ts($periodStart), $this->ts($periodEnd));

        return true;
    }

    private function shouldSchedule(Subscription $current): bool
    {
        return $current->status === Subscription::STATUS_ACTIVE
            && (int) ($current->plan?->price_cents ?? 0) > 0
            && $current->current_period_end !== null && $current->current_period_end->isFuture()
            && $current->isBilled();
    }

    /** @param  array<string, mixed>  $eventData */
    private function schedule(Tenant $tenant, Subscription $current, SubscriptionPlan $plan, array $eventData): Subscription
    {
        $at = $current->current_period_end;
        $scheduleId = $current->provider_schedule_id;

        if ($current->provider_subscription_id) {
            $gateway = $this->gateway($current);
            if ($gateway) {
                // Provider first: if Stripe is down nothing is recorded and the admin is told to retry — never a local
                // "scheduled" change that the provider knows nothing about.
                $scheduleId = $gateway->scheduleChange($current->provider_subscription_id, $plan, strtolower((string) ($plan->currency ?: 'usd')), $scheduleId);
            }
        }

        $current->update([
            'pending_plan_id' => $plan->id, 'pending_change_at' => $at, 'pending_requested_at' => now(), 'provider_schedule_id' => $scheduleId,
        ]);

        $this->subscriptions->record($tenant, $current, Subscription::EVENT_PLAN_CHANGE_SCHEDULED, fromPlanId: $current->plan_id, toPlanId: $plan->id, data: [...$eventData, 'effective_at' => $at->toIso8601String()]);
        $this->notifier->planChangeScheduled($tenant, $current->plan, $plan, $at);

        return $current->refresh();
    }

    private function clear(Tenant $tenant, Subscription $subscription): Subscription
    {
        if ($subscription->provider_schedule_id) {
            $this->gateway($subscription)?->releaseSchedule($subscription->provider_schedule_id);
        }

        $pending = $subscription->pending_plan_id;
        $subscription->update(['pending_plan_id' => null, 'pending_change_at' => null, 'pending_requested_at' => null, 'provider_schedule_id' => null]);
        $this->subscriptions->record($tenant, $subscription, Subscription::EVENT_PLAN_CHANGE_CANCELED, fromPlanId: $subscription->plan_id, toPlanId: $pending);

        return $subscription->refresh();
    }

    private function gateway(Subscription $subscription): ?RecurringGateway
    {
        $gateway = $this->resolver->resolve((string) ($subscription->billing_provider ?: 'stripe'));

        return $gateway instanceof RecurringGateway ? $gateway : null;
    }

    /** @param  array<string, mixed>  $remote */
    private function priceOf(array $remote): ?string
    {
        $price = $remote['items']['data'][0]['price'] ?? null;

        return is_array($price) ? ($price['id'] ?? null) : $price;
    }

    private function ts(int|string|null $timestamp): ?Carbon
    {
        return $timestamp ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }
}
