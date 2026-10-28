<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Support\TenantDatabaseManager;
use App\Support\Tms\TmsSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Computes live domain entity statistics from isolated tenant databases.
 */
class TenantStats
{
    public function __construct(private readonly TenantDatabaseManager $dbm) {}

    /**
     * Live domain counts read from the tenant's own database. Cached for 60 seconds.
     *
     * @return array{users: int, workspaces: int, projects: int, tasks: int}
     */
    public function forTenant(Tenant $tenant): array
    {
        return Cache::remember("tenants.stats.{$tenant->id}", 60, function () use ($tenant) {
            return $this->dbm->using($tenant, function () {
                $db = DB::connection('tenant');

                return [
                    'users' => $db->table('users')->count(),
                    // Single-product tenants hold no TMS tables: zero, not a 500.
                    'workspaces' => TmsSchema::present() ? $db->table('workspaces')->count() : 0,
                    'projects' => TmsSchema::present() ? $db->table('projects')->count() : 0,
                    'tasks' => TmsSchema::present() ? $db->table('tasks')->whereNull('deleted_at')->count() : 0,
                ];
            });
        });
    }
}
