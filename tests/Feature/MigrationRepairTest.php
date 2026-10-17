<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * F11.7 — `tenants:provision` re-runs migrations that failed part-way, so every migration has to be safe on a database
 * that already holds the schema AND data. Forgetting the most recent migrations on a populated tenant and running the
 * set again must change nothing and must not throw.
 */
class MigrationRepairTest extends TestCase
{
    use IsolatesDatabase;

    public function test_rerunning_recent_migrations_on_a_populated_tenant_is_a_no_op(): void
    {
        $tenant = Tenant::where('slug', 'acme')->firstOrFail();
        $dbm = app(TenantDatabaseManager::class);

        $before = $dbm->using($tenant, function () {
            $counts = collect(['users', 'roles', 'permissions', 'priorities'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);
            // Forget the last 25 recorded migrations (across all sets) so the migrator runs them again.
            $ids = DB::table('migrations')->orderByDesc('id')->limit(25)->pluck('id');
            DB::table('migrations')->whereIn('id', $ids)->delete();

            return $counts;
        });

        $dbm->migrateTenant($tenant);

        $after = $dbm->using($tenant, fn () => collect(['users', 'roles', 'permissions', 'priorities'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]));

        $this->assertEquals($before->all(), $after->all());
        $this->assertGreaterThanOrEqual(25, $dbm->using($tenant, fn () => DB::table('migrations')->count()));
    }
}
