<?php

namespace App\Billing\Dunning;

use App\Models\DunningAttempt;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SubscriptionService;
use App\Services\TenantLifecycle;
use App\Support\TenantDatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * P6.2 — the failed-payment sequence. A subscription goes `past_due` the first
 * time a renewal charge fails (`past_due_at` anchors day 0). From then on this
 * sweep, run daily, walks `config('billing.dunning.steps')`: a reminder on day
 * 0 and 3, a final notice on day 5, and once `grace_days` have passed with no
 * payment the tenant is suspended. Paying (any path that renews or reassigns
 * the subscription) clears `past_due_at`, which ends the cycle and — through
 * SubscriptionService::renew() — reactivates a suspended tenant.
 *
 * Idempotent: every step is a `dunning_attempts` row with a unique key, so a
 * second tick on the same day, or a manual re-run, does nothing. When the
 * sweep was down for days it sends only the most recent due notice, not a
 * burst; the earlier steps are recorded as skipped.
 */
class DunningService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly TenantLifecycle $lifecycle,
        private readonly TenantDatabaseManager $tenants,
    ) {}

    /**
     * @return array<int, array{0: string, 1: string, 2: string}> tenant slug, product, what happened
     */
    public function run(?int $tenantId = null, bool $dryRun = false): array
    {
        $rows = [];

        $due = Subscription::query()
            ->where('status', Subscription::STATUS_PAST_DUE)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->with('tenant')
            ->orderBy('id')
            ->get();

        foreach ($due as $subscription) {
            $tenant = $subscription->tenant;
            if (! $tenant) {
                continue;
            }

            $subscription->past_due_at ??= $subscription->updated_at ?? now();
            if (! $dryRun && $subscription->isDirty('past_due_at')) {
                $subscription->save();
            }

            foreach ($this->actions($subscription, $tenant) as $action) {
                $rows[] = [$tenant->slug, $subscription->product, $action['label']];

                if (! $dryRun) {
                    $this->apply($tenant, $subscription, $action);
                }
            }
        }

        return $rows;
    }

    /**
     * What is due today and not yet done, oldest first.
     *
     * @return array<int, array{stage: string, day: int, label: string, notify: bool}>
     */
    private function actions(Subscription $subscription, Tenant $tenant): array
    {
        $days = (int) floor(abs($subscription->past_due_at->diffInDays(now(), false)));
        $done = DunningAttempt::where('subscription_id', $subscription->id)
            ->where('cycle', $this->cycle($subscription))
            ->get()
            ->map(fn (DunningAttempt $a) => $a->stage.':'.$a->step_day)
            ->all();

        $pending = [];
        foreach ((array) config('billing.dunning.steps', []) as $day => $stage) {
            if ($days >= (int) $day && ! in_array($stage.':'.$day, $done, true)) {
                $pending[] = ['stage' => $stage, 'day' => (int) $day, 'label' => "{$stage} (day {$day})", 'notify' => true];
            }
        }

        // Only the newest notice is actually sent; earlier overdue ones are recorded as skipped.
        foreach (array_slice(array_keys($pending), 0, -1) as $i) {
            $pending[$i]['notify'] = false;
            $pending[$i]['label'] .= ' skipped';
        }

        $grace = (int) config('billing.dunning.grace_days', 7);
        if ($days >= $grace && $tenant->isServiceable() && ! in_array('suspend:'.$grace, $done, true)) {
            $pending[] = ['stage' => 'suspend', 'day' => $grace, 'label' => 'suspend tenant', 'notify' => true];
        }

        return $pending;
    }

    /** @param  array{stage: string, day: int, label: string, notify: bool}  $action */
    private function apply(Tenant $tenant, Subscription $subscription, array $action): void
    {
        try {
            DunningAttempt::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'cycle' => $this->cycle($subscription),
                'step_day' => $action['day'],
                'stage' => $action['stage'],
                'outcome' => $action['notify'] ? 'done' : 'skipped',
                'executed_at' => now(),
            ]);
        } catch (QueryException) {
            return; // a concurrent sweep already recorded this step
        }

        if ($action['stage'] === 'suspend') {
            $this->lifecycle->transition($tenant, Tenant::STATUS_SUSPENDED, null, ['reason' => 'payment_failed']);
            $this->subscriptions->record($tenant, $subscription, Subscription::EVENT_PAUSED, fromPlanId: $subscription->plan_id, toPlanId: $subscription->plan_id, data: ['reason' => 'dunning_suspended']);
        }

        if ($action['notify']) {
            $this->notifyAdmins($tenant, $subscription, $action['stage']);
        }
    }

    private function cycle(Subscription $subscription): string
    {
        return $subscription->past_due_at->format('YmdHis');
    }

    /** In-app notice to every tenant admin, inside the tenant's own DB. Never blocks the sweep. */
    private function notifyAdmins(Tenant $tenant, Subscription $subscription, string $stage): void
    {
        $type = match ($stage) {
            'suspend' => 'billing.suspended',
            'final_notice' => 'billing.final_notice',
            default => 'billing.payment_failed',
        };

        try {
            if (! $tenant->isProvisioned()) {
                return;
            }

            $this->tenants->using($tenant, function () use ($type, $subscription, $stage): void {
                $deadline = $subscription->past_due_at->copy()->addDays((int) config('billing.dunning.grace_days', 7));

                User::whereHas('roles', fn ($q) => $q->where('slug', 'admin'))->get()->each(
                    fn (User $admin) => app(NotificationService::class)->notify($admin, $type, [
                        'product' => $subscription->product,
                        'plan' => $subscription->plan?->name,
                        'stage' => $stage,
                        'suspends_at' => $deadline->toIso8601String(),
                    ]),
                );
            });
        } catch (Throwable $e) {
            Log::warning('Dunning notification failed.', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
        }
    }
}
