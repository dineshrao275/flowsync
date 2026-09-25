<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cross-tenant platform analytics. Tenant-DB counters run through a capped
 * fan-out (first serviceable 100 tenants, cached 5 minutes); status/subscription
 * aggregates come straight off the central DB.
 */
class SystemAnalyticsController extends Controller
{
    private const RESOURCE_SCAN_CAP = 100;

    private const RESOURCE_CACHE_SECONDS = 300;

    public function index(): JsonResponse
    {
        $statuses = Tenant::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => ['status' => (string) $row->status, 'count' => (int) $row->total])
            ->values();

        $subscriptions = Subscription::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => ['status' => (string) $row->status, 'count' => (int) $row->total])
            ->values();

        $resources = $this->resourceTotals();

        $topTenants = $resources->map(fn (array $r) => [
            'id' => $r['tenant']['id'],
            'tenant' => $r['tenant'],
            'users' => $r['users'],
        ])->sortByDesc('users')->take(5)->values();

        return response()->json([
            'tenants' => [
                'total' => array_sum(array_column($statuses->all(), 'count')),
                'by_status' => $statuses,
                'provisioning_failed' => Tenant::where('provisioning_status', Tenant::PROVISIONING_FAILED)->count(),
                'trashed' => Tenant::onlyTrashed()->count(),
            ],
            'subscriptions' => [
                'total' => array_sum(array_column($subscriptions->all(), 'count')),
                'by_status' => $subscriptions,
            ],
            'resources' => [
                'users' => $resources->sum('users'),
                'workspaces' => $resources->sum('workspaces'),
                'projects' => $resources->sum('projects'),
                'tasks' => $resources->sum('tasks'),
            ],
            'top_tenants' => $topTenants,
            'growth' => $this->growth(),
            'plans' => $this->planDistribution(),
        ]);
    }

    /**
     * Daily signups for the last 30 days (central DB, no fan-out) so the
     * platform charts can plot a trend instead of a single snapshot.
     */
    private function growth(): array
    {
        $days = 30;
        $start = Carbon::today()->subDays($days - 1);

        $rows = Tenant::query()
            ->where('created_at', '>=', $start->copy()->startOfDay())
            ->get(['created_at'])
            ->groupBy(fn ($tenant) => Carbon::parse($tenant->created_at)->toDateString());

        return collect(range(0, $days - 1))
            ->map(function (int $offset) use ($rows, $start): array {
                $date = $start->copy()->addDays($offset)->toDateString();

                return [
                    'date' => $date,
                    'label' => Carbon::parse($date)->format('M j'),
                    'tenants' => $rows->get($date, collect())->count(),
                ];
            })
            ->all();
    }

    /**
     * Subscription counts + price rollup per plan (active + trialing only are
     * billable). Powers the plan-mix chart and the MRR figure.
     */
    private function planDistribution(): array
    {
        $counts = Subscription::query()
            ->select('plan_id', DB::raw('count(*) as total'))
            ->groupBy('plan_id')
            ->pluck('total', 'plan_id');

        $plans = SubscriptionPlan::query()->orderBy('sort_order')->orderBy('id')->get();

        $rows = $plans->map(function (SubscriptionPlan $plan) use ($counts): array {
            $subscriptions = (int) $counts->get($plan->id, 0);

            return [
                'plan' => $plan->name,
                'slug' => $plan->slug,
                'subscriptions' => $subscriptions,
                'price_cents' => (int) $plan->price_cents,
                'monthly_cents' => $plan->billing_cycle === 'annual'
                    ? (int) round($plan->price_cents / 12)
                    : (int) $plan->price_cents,
            ];
        })->values();

        return [
            'rows' => $rows,
            'monthly_cents' => (int) $rows->sum(fn (array $row) => $row['monthly_cents'] * $row['subscriptions']),
            'active_subscriptions' => (int) Subscription::query()
                ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
                ->count(),
        ];
    }

    /**
     * Per-tenant resource counts via TenantDatabaseManager (capped fan-out,
     * cached). Each row: {tenant:{id,name,slug,status}, users, workspaces,
     * projects, tasks}.
     */
    private function resourceTotals(): Collection
    {
        return Cache::remember('platform.analytics.resources', self::RESOURCE_CACHE_SECONDS, function (): Collection {
            $dbm = app(TenantDatabaseManager::class);

            $tenants = Tenant::query()
                ->orderBy('id')
                ->limit(self::RESOURCE_SCAN_CAP)
                ->get();

            $rows = $tenants->map(function (Tenant $tenant) use ($dbm): ?array {
                if (! $tenant->isServiceable()) {
                    return null;
                }

                return $dbm->using($tenant, fn (): array => [
                    'tenant' => [
                        'id' => $tenant->id,
                        'name' => $tenant->name,
                        'slug' => $tenant->slug,
                        'status' => $tenant->status,
                    ],
                    'users' => DB::table('users')->count(),
                    'workspaces' => DB::table('workspaces')->count(),
                    'projects' => DB::table('projects')->count(),
                    'tasks' => DB::table('tasks')->whereNull('deleted_at')->count(),
                ]);
            });

            return collect($rows)->filter()->values();
        });
    }
}
