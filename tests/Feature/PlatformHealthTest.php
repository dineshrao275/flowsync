<?php

namespace Tests\Feature;

use Tests\IsolatesDatabase;
use Tests\TestCase;

class PlatformHealthTest extends TestCase
{
    use IsolatesDatabase;

    private function loginSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    private function loginTenantAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_public_health_ping_returns_healthy(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonStructure(['status', 'timestamp']);
    }

    public function test_unauthenticated_request_to_platform_health_is_rejected(): void
    {
        $this->getJson('/api/platform/health')
            ->assertStatus(401);
    }

    public function test_tenant_user_cannot_access_platform_health(): void
    {
        $this->loginTenantAdmin();

        $this->getJson('/api/platform/health')
            ->assertStatus(403);
    }

    public function test_super_admin_receives_complete_health_report(): void
    {
        $this->loginSuperAdmin();

        $response = $this->getJson('/api/platform/health');

        $response->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('services.system_database.status', 'ok')
            ->assertJsonPath('services.tenant_databases.status', 'ok')
            ->assertJsonPath('services.cache.status', 'ok')
            ->assertJsonPath('services.storage.status', 'ok')
            ->assertJsonPath('services.queue.status', 'ok')
            ->assertJsonStructure([
                'status',
                'timestamp',
                'services' => [
                    'system_database' => ['status', 'connection', 'latency_ms'],
                    'tenant_databases' => ['status', 'sampled_count', 'total_active_tenants', 'samples'],
                    'cache' => ['status', 'driver'],
                    'storage' => ['status', 'disk', 'writable'],
                    'queue' => ['status', 'driver'],
                ],
            ]);

        // Verify alias /api/system/health also works identically
        $this->getJson('/api/system/health')
            ->assertOk()
            ->assertJsonPath('status', 'healthy');
    }
}
