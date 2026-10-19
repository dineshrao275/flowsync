<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\UsageMetric;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F11.10 — the collector records recent activity and the platform page flags dormant tenants. */
class TenantActivityTest extends TestCase
{
    use IsolatesDatabase;

    public function test_collector_counts_distinct_recent_actors_and_the_endpoint_flags_dormant_tenants(): void
    {
        $acme = Tenant::where('slug', 'acme')->firstOrFail();
        $globex = Tenant::where('slug', 'globex')->firstOrFail();
        Tenant::whereKey([$acme->id, $globex->id])->update(['created_at' => now()->subMonths(2)]);

        $this->connectTenant('acme');
        $uid = DB::table('users')->value('id');
        foreach ([1, 2, 40] as $daysAgo) {
            DB::table('activities')->insert(['actor_user_id' => $uid, 'subject_type' => 'x', 'subject_id' => 1, 'action' => 'task.created', 'created_at' => now()->subDays($daysAgo)]);
        }

        $this->artisan('tenants:collect-usage')->assertSuccessful();

        $get = fn (Tenant $t, string $k) => (int) UsageMetric::where('tenant_id', $t->id)->where('metric_key', $k)->value('value');
        $this->assertSame(1, $get($acme, 'active_users_7d'));   // one person, two events
        $this->assertSame(1, $get($acme, 'active_users_30d'));
        $this->assertSame(2, $get($acme, 'activity_30d'));       // the 40-day-old event is outside the window
        $this->assertSame(0, $get($globex, 'active_users_30d'));

        $this->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])->assertOk();
        $res = $this->getJson('/api/system/tenant-activity')->assertOk();
        $rows = collect($res->json('tenants'))->keyBy('slug');
        $this->assertFalse($rows['acme']['dormant']);
        $this->assertTrue($rows['globex']['dormant']);
        $this->assertSame('globex', $res->json('tenants.0.slug'));   // dormant first
    }
}
