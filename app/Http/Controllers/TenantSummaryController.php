<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Platform-level numbers for the Super Admin tenants page: how many tenants are
 * enabled or disabled, where subscriptions stand, recurring revenue, and which
 * tenants need a look. Computed from the central DB only — no tenant DB is opened.
 */
class TenantSummaryController extends Controller
{
    private const ENABLED = [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL];

    public function __invoke(): JsonResponse
    {
        $byStatus = Tenant::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $count = fn (array $statuses): int => (int) collect($statuses)->sum(fn (string $s) => $byStatus[$s] ?? 0);
        $enabled = $count(self::ENABLED);
        // Switched off: a draft or in-flight tenant is neither enabled nor disabled yet.
        $disabled = $count([Tenant::STATUS_SUSPENDED, Tenant::STATUS_EXPIRED, Tenant::STATUS_DEACTIVATED, Tenant::STATUS_PROVISIONING_FAILED]);

        $subscriptions = Subscription::query()->with('plan')->get();
        $bySubscription = $subscriptions->countBy('status');

        $byPlan = $subscriptions->groupBy(fn (Subscription $s) => $s->plan?->name ?? 'Unknown')->map->count();

        // Recurring revenue of what is actually billing: active, paid, not on its way out.
        $mrr = $subscriptions
            ->filter(fn (Subscription $s) => $s->status === Subscription::STATUS_ACTIVE && $s->auto_renew && $s->plan)
            ->sum(fn (Subscription $s) => $s->plan->billing_cycle === 'annual'
                ? intdiv((int) $s->plan->price_cents, 12)
                : (int) $s->plan->price_cents);

        $soon = now()->addDays(7);
        $trialsEnding = Tenant::query()->where('status', Tenant::STATUS_TRIAL)
            ->whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', $soon)->orderBy('trial_ends_at')->get(['id', 'name', 'slug', 'trial_ends_at']);

        $attention = collect()
            ->merge($subscriptions->where('status', Subscription::STATUS_PAST_DUE)->map(fn (Subscription $s) => $this->flag($s->tenant, 'Payment past due')))
            ->merge($trialsEnding->map(fn (Tenant $t) => $this->flag($t, 'Trial ends '.$t->trial_ends_at->toFormattedDateString())))
            ->merge(Tenant::query()->where('status', Tenant::STATUS_PROVISIONING_FAILED)->get(['id', 'name', 'slug'])->map(fn (Tenant $t) => $this->flag($t, 'Provisioning failed')))
            ->merge(Tenant::query()->where('status', Tenant::STATUS_DRAFT)->get(['id', 'name', 'slug'])->map(fn (Tenant $t) => $this->flag($t, 'Setup incomplete')))
            ->filter()->values()->take(8);

        return response()->json([
            'summary' => [
                'tenants' => ['total' => (int) $byStatus->sum(), 'enabled' => $enabled, 'disabled' => $disabled, 'by_status' => $byStatus],
                'subscriptions' => ['by_status' => $bySubscription, 'by_plan' => $byPlan, 'trials_ending_7d' => $trialsEnding->count()],
                'mrr_cents' => (int) $mrr,
                'attention' => $attention,
            ],
        ]);
    }

    /** @return array{tenant_id: int, name: string, slug: string, reason: string}|null */
    private function flag(?Tenant $tenant, string $reason): ?array
    {
        return $tenant ? ['tenant_id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'reason' => $reason] : null;
    }
}
