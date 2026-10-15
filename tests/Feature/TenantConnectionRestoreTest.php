<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Regression: leaving a `using($other)` block while the default connection is the tenant
 * connection restored the CONFIG of the previous tenant but kept the other tenant's open
 * PDO, so the next query — and any write — silently hit the wrong database.
 */
class TenantConnectionRestoreTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_write_inside_another_tenants_block_stays_in_that_tenant(): void
    {
        $acme = $this->acme();
        $globex = $this->globex();
        $row = fn () => ['uuid' => 'u-'.uniqid(), 'type' => 'x', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()];

        $this->dbm->using($globex, fn () => DB::table('domain_events')->insert($row()));

        // Back on the original tenant (acme) the row must not exist …
        $this->assertSame(0, DB::table('domain_events')->count());
        $this->assertSame(0, $this->dbm->using($acme, fn () => DB::table('domain_events')->count()));
        // … and it must still be in globex.
        $this->assertSame(1, $this->dbm->using($globex, fn () => DB::table('domain_events')->count()));
    }
}
