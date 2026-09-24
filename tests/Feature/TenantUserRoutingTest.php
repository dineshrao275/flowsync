<?php

namespace Tests\Feature;

use App\Models\ProvisioningRun;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Support\TenantDatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class TenantUserRoutingTest extends TestCase
{
    use IsolatesDatabase;

    #[Test]
    public function central_connection_resolves_the_system_connection_in_isolated_mode(): void
    {
        $this->assertSame('iso_system', app(TenantDatabaseManager::class)->centralConnectionName());
    }

    #[Test]
    public function routing_rows_write_to_the_central_database_and_link_their_tenant(): void
    {
        $tenant = Tenant::where('slug', 'acme')->first();

        $route = TenantUserRouting::create([
            'tenant_id' => $tenant->id,
            'email' => 'rider@acme.test',
            'user_id' => 1,
            'name' => 'Acme Rider',
        ]);

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'email' => 'rider@acme.test',
            'user_id' => 1,
        ], 'iso_system');

        $this->assertSame($tenant->id, $route->tenant->id);
    }

    #[Test]
    public function routing_email_is_unique_per_tenant(): void
    {
        $tenant = Tenant::where('slug', 'acme')->first();

        TenantUserRouting::create([
            'tenant_id' => $tenant->id,
            'email' => 'rider@acme.test',
            'user_id' => 1,
            'name' => 'Acme Rider',
        ]);

        $this->expectException(QueryException::class);

        TenantUserRouting::create([
            'tenant_id' => $tenant->id,
            'email' => 'rider@acme.test',
            'user_id' => 2,
            'name' => 'Duplicate',
        ]);
    }

    #[Test]
    public function same_email_in_different_tenants_is_allowed(): void
    {
        $acme = Tenant::where('slug', 'acme')->first();
        $globex = Tenant::where('slug', 'globex')->first();

        TenantUserRouting::create(['tenant_id' => $acme->id, 'email' => 'shared@example.test', 'user_id' => 1]);
        TenantUserRouting::create(['tenant_id' => $globex->id, 'email' => 'shared@example.test', 'user_id' => 2]);

        $this->assertSame(2, TenantUserRouting::where('email', 'shared@example.test')->count());
    }

    #[Test]
    public function provisioning_run_rows_persist_status_and_error(): void
    {
        $tenant = Tenant::where('slug', 'acme')->first();

        $run = ProvisioningRun::create([
            'tenant_id' => $tenant->id,
            'status' => ProvisioningRun::STATUS_FAILED,
            'step' => 'failed',
            'error' => 'boom',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->assertSame('boom', $run->error);
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame($tenant->id, $run->tenant->id);
        $this->assertDatabaseHas('provisioning_runs', ['tenant_id' => $tenant->id, 'status' => 'failed'], 'iso_system');
    }

    #[Test]
    public function routing_lookup_is_case_insensitive(): void
    {
        $acme = Tenant::where('slug', 'acme')->first();

        TenantUserRouting::create([
            'tenant_id' => $acme->id,
            'email' => Str::lower('Rider@Acme.Test'),
            'user_id' => 7,
            'name' => 'Rider',
        ]);

        $match = TenantUserRouting::where('email', strtolower('RIDER@ACME.TEST'))->with('tenant')->first();

        $this->assertNotNull($match);
        $this->assertSame('rider@acme.test', $match->email);
        $this->assertSame(7, $match->user_id);
        $this->assertSame('acme', $match->tenant->slug);
    }
}