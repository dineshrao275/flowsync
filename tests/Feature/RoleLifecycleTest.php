<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\TenantLifecycle;
use App\Support\TenantProvisioner;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * R2/R6 — built-in roles are read-only; customising means cloning; custom roles
 * can be deleted (never while assigned); a repair never revokes.
 */
class RoleLifecycleTest extends TestCase
{
    use IsolatesDatabase;

    private function role(string $slug): Role
    {
        return Role::where('slug', $slug)->firstOrFail();
    }

    public function test_the_config_roles_are_flagged_as_system_roles(): void
    {
        $this->connectTenant('acme');

        foreach (array_keys(config('permissions.roles')) as $slug) {
            $this->assertTrue($this->role($slug)->is_system, "{$slug} should be a system role");
        }
    }

    public function test_a_system_role_cannot_be_edited_or_deleted_even_by_an_admin(): void
    {
        $this->loginAs('admin@flowsync.test');
        $editor = $this->role('editor');

        $this->putJson("/api/roles/{$editor->id}", [
            'name' => 'Editor',
            'permissions' => $editor->permissions()->pluck('permissions.id')->all(),
        ])->assertStatus(422)->assertJsonValidationErrors('form');

        $this->deleteJson("/api/roles/{$editor->id}")->assertStatus(422)->assertJsonValidationErrors('form');
    }

    public function test_cloning_a_role_makes_an_editable_custom_copy(): void
    {
        $this->loginAs('admin@flowsync.test');
        $editor = $this->role('editor');

        $this->postJson("/api/roles/{$editor->id}/clone", ['name' => 'Editor Plus', 'slug' => 'editor-plus'])
            ->assertCreated();

        $copy = $this->role('editor-plus');
        $this->assertFalse($copy->is_system);
        $this->assertEqualsCanonicalizing(
            $editor->permissions()->pluck('slug')->all(),
            $copy->permissions()->pluck('slug')->all(),
        );

        $row = AuditLog::where('action', 'role.created')->orderByDesc('id')->firstOrFail();
        $this->assertSame('editor', $row->data['cloned_from']);

        // And the copy, unlike the original, can be changed.
        $this->putJson("/api/roles/{$copy->id}", [
            'name' => 'Editor Plus',
            'permissions' => [Permission::where('slug', 'reports.view')->value('id')],
        ])->assertOk();
    }

    public function test_a_clone_cannot_exceed_the_actors_own_permissions(): void
    {
        $this->connectTenant('acme');
        $ops = Role::create(['name' => 'Ops', 'slug' => 'ops']);
        $ops->permissions()->sync(Permission::whereIn('slug', ['roles.view', 'roles.manage'])->pluck('id'));
        $user = User::create(['name' => 'Ops', 'email' => 'ops@acme.test', 'password' => 'password']);
        $user->roles()->sync([$ops->id]);
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);

        $payroll = $this->role('payroll_manager');
        $this->postJson("/api/roles/{$payroll->id}/clone", ['name' => 'Copy', 'slug' => 'copy'])
            ->assertStatus(422)->assertJsonValidationErrors('permissions');
    }

    public function test_a_custom_role_can_be_deleted_only_while_unassigned(): void
    {
        $this->loginAs('admin@flowsync.test');
        $custom = Role::create(['name' => 'Temp', 'slug' => 'temp']);
        $viewer = User::where('email', 'viewer@flowsync.test')->firstOrFail();
        $viewer->roles()->attach($custom->id);

        $this->deleteJson("/api/roles/{$custom->id}")->assertStatus(422)->assertJsonValidationErrors('form');
        $this->assertTrue(Role::where('slug', 'temp')->exists());

        $viewer->roles()->detach($custom->id);
        $this->deleteJson("/api/roles/{$custom->id}")->assertOk();
        $this->assertFalse(Role::where('slug', 'temp')->exists());
        $this->assertTrue(AuditLog::where('action', 'role.deleted')->exists());
    }

    public function test_reprovisioning_never_revokes_what_a_tenant_holds(): void
    {
        $this->connectTenant('acme');

        // A permission the default editor role does not ship with, granted by
        // the tenant before roles became read-only. A plain sync() removed it
        // on every repair.
        $extra = Permission::where('slug', 'billing.manage')->firstOrFail();
        $this->role('editor')->permissions()->attach($extra->id);

        $custom = Role::create(['name' => 'Keep', 'slug' => 'keep']);
        $custom->permissions()->sync([Permission::where('slug', 'billing.view')->value('id')]);

        app(TenantProvisioner::class)->provisionIsolated($this->acme(), $this->dbm, app(TenantLifecycle::class));

        $this->connectTenant('acme');
        $this->assertTrue($this->role('editor')->permissions->contains('slug', 'billing.manage'));
        $this->assertSame(['billing.view'], $this->role('keep')->permissions()->pluck('slug')->all());
        $this->assertFalse($this->role('keep')->is_system);
    }
}
