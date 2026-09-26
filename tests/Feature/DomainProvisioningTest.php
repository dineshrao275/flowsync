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
        $this->assertSame(count(config('permissions.permissions')), Permission::count());
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

        // `admin` is the `'*'` selector, so it must hold the entire catalog —
        // asserted against the catalog itself rather than a magic number, so
        // adding a permission in a later phase does not break this test.
        $this->assertSame(
            count(config('permissions.permissions')),
            $admin->permissions()->count(),
        );
        $this->assertTrue($admin->permissions->contains('slug', 'workspaces.view'));
        $this->assertTrue($admin->permissions->contains('slug', 'workspaces.manage'));
        $this->assertTrue($admin->permissions->contains('slug', 'hrms.payroll.statutory.manage'));

        $editor = Role::where('slug', 'editor')->first();
        $this->assertTrue($editor->permissions->contains('slug', 'workspaces.create'));
        $this->assertFalse($editor->permissions->contains('slug', 'workspaces.manage'));
        $this->assertTrue($editor->permissions->contains('slug', 'hrms.view'));

        $viewer = Role::where('slug', 'viewer')->first();
        $this->assertTrue($viewer->permissions->contains('slug', 'workspaces.view'));
        $this->assertFalse($viewer->permissions->contains('slug', 'workspaces.create'));
        $this->assertTrue($viewer->permissions->contains('slug', 'hrms.view'));
    }

    public function test_hrms_roles_are_provisioned_from_selectors(): void
    {
        $slugs = fn (string $role) => Role::where('slug', $role)->first()->permissions
            ->pluck('slug')->all();

        $hr = $slugs('hr_manager');
        $payroll = $slugs('payroll_manager');

        // HR manager: the whole hrms.* surface except payroll/statutory and the
        // salary mutation permission.
        $this->assertContains('hrms.attendance.manage', $hr);
        $this->assertContains('hrms.leave.approve', $hr);
        $this->assertNotContains('hrms.payroll.view', $hr);
        $this->assertNotContains('hrms.payroll.run', $hr);
        $this->assertNotContains('hrms.payroll.statutory.manage', $hr);
        $this->assertNotContains('hrms.compensation.manage', $hr);
        $this->assertContains('hrms.compensation.view', $hr);

        // Payroll manager: payroll + compensation, no org/asset management.
        // Attendance is granted in full (plan 3.2 `hrms.attendance.*`): OT and
        // short-day payroll both read attendance, and partial-granting it here
        // would force payroll to work around a missing punch.
        $this->assertContains('hrms.payroll.run', $payroll);
        $this->assertContains('hrms.payroll.statutory.manage', $payroll);
        $this->assertContains('hrms.compensation.view', $payroll);
        $this->assertContains('hrms.attendance.manage', $payroll);
        $this->assertContains('hrms.leave.view', $payroll);
        $this->assertContains('hrms.expenses.approve', $payroll);
        $this->assertNotContains('hrms.org.manage', $payroll);
        $this->assertNotContains('hrms.assets.manage', $payroll);
        $this->assertNotContains('hrms.employees.manage', $payroll);
        $this->assertNotContains('hrms.talent.manage', $payroll);

        // A newly added hrms.* permission must reach hr_manager automatically —
        // this is why roles use selectors instead of literal slug lists.
        $hrmsCatalog = collect(config('permissions.permissions'))
            ->pluck('slug')
            ->filter(fn (string $slug) => str_starts_with($slug, 'hrms.'))
            ->reject(fn (string $slug) => str_starts_with($slug, 'hrms.payroll.')
                || $slug === 'hrms.compensation.manage')
            ->values()
            ->all();

        $this->assertEmpty(array_diff($hrmsCatalog, $hr));
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
