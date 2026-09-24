<?php

namespace Tests\Feature;

use App\Models\ImpersonationLog;
use App\Models\User;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class TenantTest extends TestCase
{
    use IsolatesDatabase;

    public function test_tenant_admin_only_sees_own_tenant_users(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $response = $this->getJson('/api/users');
        $emails = collect($response->json('users'))->pluck('email');
        $this->assertFalse($emails->contains('owner@globex.test'));
        $this->assertTrue($emails->contains('admin@flowsync.test'));

        // Each tenant's users live in its own database.
        $this->assertDatabaseHas('users', ['email' => 'admin@flowsync.test']);
        $this->dbm->using($this->globex(), function () {
            $this->assertDatabaseHas('users', ['email' => 'owner@globex.test']);
            $this->assertDatabaseMissing('users', ['email' => 'admin@flowsync.test']);
        });
    }

    public function test_tenant_admin_cannot_access_super_admin_routes(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/tenants')->assertForbidden();
        $this->postJson('/api/tenants', [
            'name' => 'Evil Corp',
            'slug' => 'evil',
        ])->assertForbidden();
    }

    public function test_super_admin_can_list_tenants(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/tenants')->assertOk()
            ->assertJsonCount(2, 'tenants');
    }

    public function test_super_admin_can_impersonate_tenant_user_and_stop(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        // HTTP login restores the system connection; resolve the target on Acme's DB.
        $this->connectTenant('acme');

        $target = User::where('email', 'admin@flowsync.test')->first();

        $this->postJson('/api/impersonate', ['user_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@flowsync.test')
            ->assertJsonPath('user.impersonating', true);

        $log = ImpersonationLog::where('impersonated_user_id', $target->id)->first();
        $this->assertNotNull($log);
        $this->assertNotNull($log->started_at);
        $this->assertNull($log->ended_at);

        $this->getJson('/api/tenants')->assertForbidden();

        $this->postJson('/api/impersonate/stop')->assertOk()
            ->assertJsonPath('user.email', 'superadmin@flowsync.test')
            ->assertJsonPath('user.impersonating', false);

        $log->refresh();
        $this->assertNotNull($log->ended_at);

        $this->getJson('/api/tenants')->assertOk();
    }

    public function test_tenant_admin_cannot_impersonate(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->connectTenant('acme');

        $target = User::where('email', 'viewer@flowsync.test')->first();

        $this->postJson('/api/impersonate', ['user_id' => $target->id])
            ->assertForbidden();
    }

    public function test_super_admin_cannot_impersonate_unknown_user(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        // Platform/system users are never routable tenants, so any non-routed
        // id (e.g. a system account) is rejected.
        $this->postJson('/api/impersonate', ['user_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }
}