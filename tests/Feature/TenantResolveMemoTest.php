<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** The middleware chain used to read the same tenant row up to nine times per request. */
class TenantResolveMemoTest extends TestCase
{
    use IsolatesDatabase;

    public function test_an_authenticated_request_reads_the_tenant_row_at_most_once_per_lookup_path(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();

        $tenantReads = 0;
        DB::listen(function ($query) use (&$tenantReads): void {
            if (str_contains($query->sql, 'from "tenants"')) {
                $tenantReads++;
            }
        });

        $this->getJson('/api/search/global?q=ab')->assertOk();

        // One read for the whole chain; before the memo this request issued five.
        $this->assertLessThanOrEqual(1, $tenantReads);
    }

    public function test_a_plan_change_is_not_served_from_the_request_cache(): void
    {
        $tenant = Tenant::resolve(1);
        $this->assertSame($tenant, Tenant::resolve(1), 'second lookup shares the instance');

        Subscription::factory()->create(['tenant_id' => 1]);
        $this->assertNotSame($tenant, Tenant::resolve(1), 'a subscription write flushes the cache');

        $tenant = Tenant::resolve(1);
        $tenant->update(['name' => $tenant->name]);
        $this->assertNotSame($tenant, Tenant::resolve(1), 'a tenant write flushes the cache');
    }
}
