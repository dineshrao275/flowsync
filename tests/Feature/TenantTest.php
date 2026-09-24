<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ImpersonationLog;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
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

    public function test_super_admin_can_filter_sort_and_paginate_tenants(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        // Default list is paginated and carries routing-based counts.
        $this->getJson('/api/tenants')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonCount(2, 'tenants');

        // q filter matches name/slug/description.
        $this->getJson('/api/tenants?q=globex')
            ->assertOk()
            ->assertJsonCount(1, 'tenants')
            ->assertJsonPath('tenants.0.slug', 'globex');

        $this->getJson('/api/tenants?q=nope')
            ->assertOk()
            ->assertJsonCount(0, 'tenants');

        // status filter.
        $this->getJson('/api/tenants?status=active')
            ->assertOk()
            ->assertJsonCount(2, 'tenants');

        $this->getJson('/api/tenants?status=suspended')
            ->assertOk()
            ->assertJsonCount(0, 'tenants');

        // pagination slice.
        $this->getJson('/api/tenants?per_page=1&sort=slug&dir=asc')
            ->assertOk()
            ->assertJsonCount(1, 'tenants')
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('tenants.0.slug', 'acme');

        $this->getJson('/api/tenants?per_page=1&sort=slug&dir=desc&page=1')
            ->assertOk()
            ->assertJsonPath('tenants.0.slug', 'globex');
    }

    public function test_super_admin_can_suspend_and_activate_tenant(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $tenant = $this->globex();

        $this->postJson("/api/tenants/{$tenant->id}/suspend")
            ->assertOk()
            ->assertJsonPath('tenant.status', 'suspended');

        $tenant->refresh();
        $this->assertSame('suspended', $tenant->status);

        $this->postJson("/api/tenants/{$tenant->id}/activate")
            ->assertOk()
            ->assertJsonPath('tenant.status', 'active');

        $tenant->refresh();
        $this->assertSame('active', $tenant->status);

        $this->assertDatabaseCount('audit_logs', 2);

        $transitions = AuditLog::where('action', 'tenant.status_changed')
            ->get()
            ->map(fn ($log) => [$log->data['from'], $log->data['to']])
            ->sortBy(0)
            ->values()
            ->all();
        $this->assertSame([
            ['active', 'suspended'],
            ['suspended', 'active'],
        ], $transitions);
    }

    public function test_super_admin_can_read_tenant_stats(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $acme = $this->acme();

        $this->getJson("/api/tenants/{$acme->id}/stats")
            ->assertOk()
            ->assertJsonStructure(['stats' => ['users', 'workspaces', 'projects', 'tasks']]);

        // Isolated-test acme has seeded users but no domain objects yet.
        $stats = json_decode($this->getJson("/api/tenants/{$acme->id}/stats")->getContent(), true)['stats'];
        $this->assertGreaterThanOrEqual(1, $stats['users']);
        $this->assertSame(0, $stats['workspaces']);
        $this->assertSame(0, $stats['projects']);
        $this->assertSame(0, $stats['tasks']);

        // A second read is served from cache — the shape stays consistent.
        $this->getJson("/api/tenants/{$acme->id}/stats")
            ->assertOk()
            ->assertJsonPath('stats.projects', 0);
    }

    public function test_super_admin_can_soft_delete_and_restore_tenant(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $tenant = $this->globex();

        $this->deleteJson("/api/tenants/{$tenant->id}")
            ->assertOk();
        $this->assertNull(Tenant::find($tenant->id));
        $this->assertNotNull(Tenant::withTrashed()->find($tenant->id));

        // Trashed tenants are excluded by default but listed with trashed=true.
        $this->getJson('/api/tenants')
            ->assertOk()
            ->assertJsonCount(1, 'tenants');

        $this->getJson('/api/tenants?trashed=1')
            ->assertOk()
            ->assertJsonCount(1, 'tenants')
            ->assertJsonPath('tenants.0.slug', 'globex');

        // A trashed tenant is not route-bound (404): no accidental re-activation.
        $this->postJson("/api/tenants/{$tenant->id}/suspend")
            ->assertNotFound();

        $this->postJson("/api/tenants/{$tenant->id}/restore")
            ->assertOk()
            ->assertJsonPath('tenant.status', $tenant->status);

        $this->assertNotNull(Tenant::find($tenant->id));
        $this->getJson('/api/tenants')
            ->assertOk()
            ->assertJsonCount(2, 'tenants');

        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant.deleted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant.restored']);
    }

    public function test_impersonation_with_tenant_id_targets_the_right_cloned_user(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $acme = $this->acme();
        $globex = $this->globex();

        // Tenant-local ids are cloned across tenant DBs: each tenant's first user (the
        // provisioned owner) is local id 1, so routing rows collide on user_id=1.
        $acmeRoute = TenantUserRouting::where('tenant_id', $acme->id)->orderBy('user_id')->first();
        $globexRoute = TenantUserRouting::where('tenant_id', $globex->id)->orderBy('user_id')->first();
        $this->assertNotNull($acmeRoute);
        $this->assertNotNull($globexRoute);
        $this->assertSame($acmeRoute->user_id, $globexRoute->user_id);
        $this->assertSame('owner@acme.test', $acmeRoute->email);
        $this->assertSame('owner@globex.test', $globexRoute->email);
        $clonedId = $acmeRoute->user_id;

        // Without tenant_id the lookup is ambiguous; the UI always sends it, so scope it.
        $this->postJson('/api/impersonate', ['user_id' => $clonedId, 'tenant_id' => $globex->id])
            ->assertOk()
            ->assertJsonPath('user.email', 'owner@globex.test')
            ->assertJsonPath('user.impersonating', true);

        $log = ImpersonationLog::where('tenant_id', $globex->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($clonedId, $log->impersonated_user_id);

        $this->postJson('/api/impersonate/stop')
            ->assertOk()
            ->assertJsonPath('user.email', 'superadmin@flowsync.test');

        // The same id against the other tenant resolves to acme's clone.
        $this->postJson('/api/impersonate', ['user_id' => $clonedId, 'tenant_id' => $acme->id])
            ->assertOk()
            ->assertJsonPath('user.email', 'owner@acme.test');

        $this->postJson('/api/impersonate/stop')
            ->assertOk()
            ->assertJsonPath('user.email', 'superadmin@flowsync.test');
    }
}
