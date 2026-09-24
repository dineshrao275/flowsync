<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\TenantDatabaseManager;
use Illuminate\Http\JsonResponse;
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
        ]);
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
