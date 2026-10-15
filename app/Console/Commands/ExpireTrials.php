<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use App\Services\TenantLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Platform — expire trials whose window has passed.
 *
 * A schedule command (nightly) and an operator command: a trial that ends is
 * the one state nothing used to move, so trials ran forever. Two rows are
 * touched per candidate — the subscription flips `trialing → expired` with a
 * `trial_expired` event, and the tenant `trial → expired`, which is the gate
 * that actually locks the tenant out (`AuthController` and `SwitchTenant`
 * both refuse a non-serviceable tenant). Idempotent by construction: an
 * expired trial no longer matches the sweep, so a second run reports zero.
 *
 * Deliberately conservative on bad data: a tenant in `trial` whose
 * subscription already says `active` is a paying customer somebody mis-set —
 * it is reported and skipped, because locking out a payer is the one
 * mistake this command must not make. No grace period and no dunning yet;
 * expiry lands at `trial_ends_at` exactly.
 */
class ExpireTrials extends Command
{
    protected $signature = 'tenants:expire-trials
        {--tenant= : Central id of a single tenant}
        {--dry-run : Report trials that would expire without changing anything}';

    protected $description = 'Expire trials whose trial window has passed';

    public function handle(TenantLifecycle $lifecycle, SubscriptionService $subscriptions): int
    {
        $query = Tenant::query()
            ->where(function ($outer) {
                $outer->where(function ($q) {
                    $q->where('status', Tenant::STATUS_TRIAL)
                        ->whereNotNull('trial_ends_at')
                        ->where('trial_ends_at', '<=', now());
                })->orWhereHas('subscription', function ($q) {
                    $q->where('status', Subscription::STATUS_TRIALING)
                        ->whereNotNull('trial_ends_at')
                        ->where('trial_ends_at', '<=', now());
                });
            })
            ->with('subscription')
            ->orderBy('id');

        if (($id = $this->option('tenant')) !== null) {
            $tenant = Tenant::find($id);

            if (! $tenant) {
                $this->error('No tenant matched. Nothing to do.');

                return self::FAILURE;
            }

            $query->whereKey($tenant->id);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->info('No trials past their window. Nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $expired = 0;
        $skipped = 0;

        foreach ($tenants as $tenant) {
            $subscription = $tenant->subscription;
            $subscriptionEnds = $subscription !== null
                && $subscription->status === Subscription::STATUS_TRIALING
                && $subscription->trial_ends_at !== null
                && $subscription->trial_ends_at->lte(now());

            // The tenant's own window, or — when only the subscription ever
            // carried one — the subscription's, so a trial column left null
            // by an old provisioning path cannot strand the tenant in `trial`.
            $tenantEnds = $tenant->status === Tenant::STATUS_TRIAL
                && (($tenant->trial_ends_at !== null && $tenant->trial_ends_at->lte(now())) || $subscriptionEnds);

            // Never expire a paying customer: an `active` subscription on a
            // `trial` tenant is inconsistent data, not a lapsed trial, so the
            // tenant keeps service and the operator investigates.
            if ($tenantEnds && $subscription?->status === Subscription::STATUS_ACTIVE) {
                $skipped++;
                $rows[] = [$tenant->slug, $subscription?->plan?->slug ?? '—', 'skipped — subscription active'];

                continue;
            }

            if ($dryRun) {
                $rows[] = [
                    $tenant->slug,
                    $subscription?->plan?->slug ?? '—',
                    implode(' + ', array_filter([$tenantEnds ? 'tenant' : null, $subscriptionEnds ? 'subscription' : null])),
                ];

                continue;
            }

            if ($subscriptionEnds) {
                $subscription->update(['status' => Subscription::STATUS_EXPIRED]);

                $subscriptions->record(
                    $tenant,
                    $subscription,
                    Subscription::EVENT_TRIAL_EXPIRED,
                    fromPlanId: $subscription->plan_id,
                    toPlanId: $subscription->plan_id,
                    data: ['trial_ends_at' => $subscription->trial_ends_at?->toIso8601String()],
                );
            }

            if ($tenantEnds) {
                $lifecycle->transition($tenant, Tenant::STATUS_EXPIRED, null, ['reason' => 'trial_expired']);
            }

            $expired++;
            $rows[] = [
                $tenant->slug,
                $subscription?->plan?->slug ?? '—',
                implode(' + ', array_filter([$tenantEnds ? 'tenant' : null, $subscriptionEnds ? 'subscription' : null])),
            ];
        }

        $this->table(['Tenant', 'Plan', $dryRun ? 'Would expire' : 'Expired'], $rows);

        if ($dryRun) {
            $this->info('Dry run — nothing was written.');
        } else {
            $this->info("Expired {$expired} trial(s); skipped {$skipped}.");

            Log::info('Trials expired.', [
                'expired' => $expired,
                'skipped' => $skipped,
            ]);
        }

        return self::SUCCESS;
    }
}
