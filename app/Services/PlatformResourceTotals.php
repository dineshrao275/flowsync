<?php

namespace App\Services;

use App\Jobs\RefreshPlatformAnalyticsJob;
use App\Models\Tenant;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Capped cross-tenant resource fan-out for the SA analytics page.
 *
 * Fresh rows live in cache for {@see self::FRESH_SECONDS}. After that the
 * last payload is still returned (stale-while-revalidate) and a queued job
 * refreshes in the background so the first SA after expiry is not a 400-query
 * stall. A cold cache still computes inline — there is nothing to show otherwise.
 */
class PlatformResourceTotals
{
    public const CACHE_KEY = 'platform.analytics.resources';

    public const LOCK_KEY = 'platform.analytics.refreshing';

    public const FRESH_SECONDS = 60;

    public const TTL_SECONDS = 600;

    public const RESOURCE_SCAN_CAP = 100;

    /**
     * @return Collection<int, array{tenant: array{id: int, name: string, slug: string, status: string}, users: int, workspaces: int, projects: int, tasks: int}>
     */
    public function get(): Collection
    {
        $payload = Cache::get(self::CACHE_KEY);

        if (! is_array($payload) || ! isset($payload['rows'], $payload['at'])) {
            return $this->computeAndStore();
        }

        $freshUntil = now()->subSeconds(self::FRESH_SECONDS)->getTimestamp();

        if ((int) $payload['at'] <= $freshUntil) {
            $this->scheduleRefresh();
        }

        return collect($payload['rows']);
    }

    /**
     * @return Collection<int, array{tenant: array{id: int, name: string, slug: string, status: string}, users: int, workspaces: int, projects: int, tasks: int}>
     */
    public function computeAndStore(): Collection
    {
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

        $computed = collect($rows)->filter()->values();

        Cache::put(self::CACHE_KEY, [
            'at' => now()->getTimestamp(),
            'rows' => $computed->all(),
        ], self::TTL_SECONDS);

        return $computed;
    }

    private function scheduleRefresh(): void
    {
        if (! Cache::add(self::LOCK_KEY, 1, 30)) {
            return;
        }

        RefreshPlatformAnalyticsJob::dispatch();
    }
}
