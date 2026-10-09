<?php

namespace App\Billing\PeriodEnd;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use App\Services\TenantLifecycle;

/**
 * P6.3 — settles subscriptions whose paid period has lapsed (trials are
 * ExpireTrials' job, failed payments are DunningService's).
 *
 *  - `canceled` (cancel-at-period-end) or `auto_renew` off  -> expired, and the
 *    tenant expires with it once nothing else keeps it running.
 *  - `auto_renew` on, billed by a recurring provider (has a provider
 *    subscription id) -> wait `renewal_grace_hours` for the provider's renewal
 *    webhook, then fall into dunning (past_due).
 *  - `auto_renew` on, paid through a one-off provider checkout -> nothing will
 *    charge it again, so it falls into dunning at once.
 *  - `auto_renew` on, billed by no provider (platform-assigned / demo / free)
 *    -> `billing.period_end.unbilled_policy`: roll the period forward (default)
 *    or expire. A free plan always rolls forward.
 *
 * Idempotent: every branch moves the subscription out of the candidate set.
 */
class PeriodEndService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly TenantLifecycle $lifecycle,
    ) {}

    /** @return array<int, array{0: string, 1: string, 2: string}> tenant slug, product, outcome */
    public function run(?int $tenantId = null, bool $dryRun = false): array
    {
        $rows = [];

        $lapsed = Subscription::query()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_CANCELED])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->with(['tenant', 'plan'])
            ->orderBy('id')
            ->get();

        foreach ($lapsed as $subscription) {
            if (! $subscription->tenant) {
                continue;
            }

            $outcome = $this->decide($subscription);
            $rows[] = [$subscription->tenant->slug, $subscription->product, $outcome];

            if (! $dryRun) {
                $this->apply($subscription->tenant, $subscription, $outcome);
            }
        }

        return $rows;
    }

    private function decide(Subscription $subscription): string
    {
        if ($subscription->isCanceled() || ! $subscription->auto_renew) {
            return 'expire';
        }

        if ($subscription->provider_subscription_id) {
            $graceEnds = $subscription->current_period_end->copy()->addHours((int) config('billing.period_end.renewal_grace_hours', 72));

            return now()->gte($graceEnds) ? 'past_due' : 'await_provider';
        }

        if ($subscription->billing_provider) {
            return 'past_due';
        }

        $free = (int) ($subscription->plan?->price_cents ?? 0) === 0;

        return $free || config('billing.period_end.unbilled_policy', 'renew') === 'renew' ? 'renew' : 'expire';
    }

    private function apply(Tenant $tenant, Subscription $subscription, string $outcome): void
    {
        match ($outcome) {
            'renew' => $this->subscriptions->renew($tenant, null, $subscription->product),
            'past_due' => $this->subscriptions->suspend($tenant, ['data' => ['reason' => 'period_end_unpaid']], null, $subscription->product),
            'expire' => $this->expire($tenant, $subscription),
            default => null,
        };
    }

    private function expire(Tenant $tenant, Subscription $subscription): void
    {
        $subscription->update(['status' => Subscription::STATUS_EXPIRED, 'auto_renew' => false]);

        $this->subscriptions->record(
            $tenant,
            $subscription,
            Subscription::EVENT_EXPIRED,
            fromPlanId: $subscription->plan_id,
            toPlanId: $subscription->plan_id,
            data: ['period_end' => $subscription->current_period_end?->toIso8601String()],
        );

        // The tenant stays up while any other product subscription still runs.
        $stillRunning = $tenant->subscriptions()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_PAST_DUE])
            ->exists();

        if (! $stillRunning && $this->lifecycle->canTransition($tenant, Tenant::STATUS_EXPIRED)) {
            $this->lifecycle->transition($tenant, Tenant::STATUS_EXPIRED, null, ['reason' => 'period_ended']);
        }
    }
}
