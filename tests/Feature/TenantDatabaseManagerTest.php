<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class TenantDatabaseManagerTest extends TestCase
{
    use IsolatesDatabase;

    private function manager(): TenantDatabaseManager
    {
        return app(TenantDatabaseManager::class);
    }

    private function makeTenant(): Tenant
    {
        return Tenant::create(['name' => 'Friendly', 'slug' => 'friendly']);
    }

    public function test_defaults_to_isolated_driver(): void
    {
        $this->assertSame('isolated', $this->manager()->driver());
    }

    public function test_dsn_builds_from_tenant_record_with_fallbacks(): void
    {
        $tenant = Tenant::create(['name' => 'Friendly', 'slug' => 'friendly']);

        $dsn = $this->manager()->dsn($tenant);

        $this->assertSame("flowsync_tenant_{$tenant->id}", $dsn['database']);
        $this->assertSame((string) env('DB_HOST', '127.0.0.1'), $dsn['host']);
        $this->assertNotEmpty($dsn['username']);
        $this->assertArrayHasKey('password', $dsn);
    }

    public function test_dsn_prefers_tenant_db_settings_when_present(): void
    {
        $tenant = Tenant::create([
            'name' => 'Friendly',
            'slug' => 'friendly',
            'db_name' => 'custom_db',
            'db_host' => '10.0.0.5',
            'db_port' => '5433',
            'db_user' => 'tenant_user',
            'db_password' => 's3cret',
        ]);

        $dsn = $this->manager()->dsn($tenant);

        $this->assertSame('custom_db', $dsn['database']);
        $this->assertSame('10.0.0.5', $dsn['host']);
        $this->assertSame('5433', $dsn['port']);
        $this->assertSame('tenant_user', $dsn['username']);
        $this->assertSame('s3cret', $dsn['password']);
    }

    public function test_db_credentials_are_encrypted_at_rest_and_decrypted_via_dsn(): void
    {
        $tenant = Tenant::create([
            'name' => 'Friendly',
            'slug' => 'friendly',
            'db_user' => 'tenant_user',
            'db_password' => 's3cret',
        ]);

        $this->dbm->connectSystem();

        $raw = DB::table('tenants')->where('id', $tenant->id)->first();

        $this->assertStringNotContainsString('s3cret', $raw->db_password);
        $this->assertStringNotContainsString('tenant_user', $raw->db_user);
        $this->assertSame('tenant_user', $tenant->db_user);
        $this->assertSame('s3cret', $tenant->db_password);
        $this->assertSame('s3cret', $this->manager()->dsn($tenant)['password']);
    }

    public function test_connect_sets_tenant_context_and_switches_connection(): void
    {
        $tenant = $this->makeTenant();

        $this->manager()->connect($tenant);

        $this->assertSame($tenant->id, app(TenantContext::class)->currentId());
        $this->assertSame('tenant', DB::getDefaultConnection());
        $this->assertSame(
            $this->manager()->tenantDatabasePath($tenant),
            Config::get('database.connections.tenant.database'),
        );
    }

    public function test_connect_system_clears_tenant_context(): void
    {
        $tenant = $this->makeTenant();
        $this->manager()->connect($tenant);

        $this->manager()->connectSystem();

        $this->assertNull(app(TenantContext::class)->currentId());
        $this->assertSame('iso_system', DB::getDefaultConnection());
    }

    public function test_using_runs_callback_and_restores_previous_context(): void
    {
        $tenant = $this->makeTenant();
        $this->dbm->connectSystem();

        [$tenantId, $connection] = $this->manager()->using($tenant, function () {
            return [app(TenantContext::class)->currentId(), DB::getDefaultConnection()];
        });

        $this->assertSame($tenant->id, $tenantId);
        $this->assertSame('tenant', $connection);
        $this->assertNull(app(TenantContext::class)->currentId());
        $this->assertSame('iso_system', DB::getDefaultConnection());
    }

    public function test_using_restores_context_even_when_callback_throws(): void
    {
        $tenant = $this->makeTenant();
        $this->dbm->connectSystem();

        try {
            $this->manager()->using($tenant, function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull(app(TenantContext::class)->currentId());
        $this->assertSame('iso_system', DB::getDefaultConnection());
    }

    public function test_sqlite_tenant_database_path_derives_from_tenant(): void
    {
        $tenant = $this->makeTenant();

        $path = $this->manager()->tenantDatabasePath($tenant);

        $this->assertSame(
            Config::get('tenancy.tenant.db_path')."/{$tenant->slug}_{$tenant->id}.sqlite",
            $path,
        );
    }
}