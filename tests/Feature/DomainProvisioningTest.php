<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Priority;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Services\TenantLifecycle;
use App\Support\TenantProvisioner;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class DomainProvisioningTest extends TestCase
{
    use IsolatesDatabase;

    public function test_new_tenant_gets_catalog_priorities_and_project_roles(): void
    {
        $this->assertSame(12, Permission::count());
        $this->assertTrue(Permission::where('slug', 'workspaces.view')->exists());
        $this->assertTrue(Permission::where('slug', 'workspaces.create')->exists());
        $this->assertTrue(Permission::where('slug', 'workspaces.manage')->exists());

        $this->assertSame(5, Priority::count());
        $this->assertSame('medium', Priority::where('is_default', true)->value('slug'));

        $this->assertSame(['developer', 'lead', 'viewer'], ProjectRole::orderBy('slug')
            ->pluck('slug')
            ->all());
    }

    public function test_admin_role_inherits_star_and_new_workspace_permissions(): void
    {
        $admin = Role::where('slug', 'admin')->first();

        $this->assertSame(12, $admin->permissions()->count());
        $this->assertTrue($admin->permissions->contains('slug', 'workspaces.view'));
        $this->assertTrue($admin->permissions->contains('slug', 'workspaces.manage'));

        $editor = Role::where('slug', 'editor')->first();
        $this->assertTrue($editor->permissions->contains('slug', 'workspaces.create'));
        $this->assertFalse($editor->permissions->contains('slug', 'workspaces.manage'));

        $viewer = Role::where('slug', 'viewer')->first();
        $this->assertTrue($viewer->permissions->contains('slug', 'workspaces.view'));
        $this->assertFalse($viewer->permissions->contains('slug', 'workspaces.create'));
    }

    public function test_reprovisioning_is_idempotent_and_backfills_existing_tenants(): void
    {
        $before = [
            'permissions' => Permission::count(),
            'priorities' => Priority::count(),
            'project_roles' => ProjectRole::count(),
        ];

        $lifecycle = app(TenantLifecycle::class);

        app(TenantProvisioner::class)->provisionIsolated($this->acme(), $this->dbm, $lifecycle);
        app(TenantProvisioner::class)->provisionIsolated($this->acme(), $this->dbm, $lifecycle);

        $this->connectTenant('acme');

        $this->assertSame($before['permissions'], Permission::count());
        $this->assertSame($before['priorities'], Priority::count());
        $this->assertSame($before['project_roles'], ProjectRole::count());

        $lead = ProjectRole::where('slug', 'lead')->first();
        $this->assertSame(['*'], $lead->permissions);
        $this->assertTrue($lead->is_system);
    }

    public function test_tenants_are_isolated_for_new_catalog_data(): void
    {
        $expected = ['highest', 'high', 'medium', 'low', 'lowest'];

        $this->assertSame($expected, Priority::orderBy('position', 'desc')->pluck('slug')->all());

        $globexPriorities = $this->dbm->using(
            $this->globex(),
            fn () => Priority::orderBy('position', 'desc')->pluck('slug')->all()
        );

        $this->assertSame($expected, $globexPriorities);
        $this->assertCount(3, ProjectRole::get());
        $this->assertSame(3, $this->dbm->using($this->globex(), fn () => ProjectRole::count()));
    }
}