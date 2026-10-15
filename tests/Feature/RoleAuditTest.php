<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * R3 — role, permission and role-assignment changes leave a masked
 * before/after row in the central audit ledger, keyed by tenant.
 */
class RoleAuditTest extends TestCase
{
    use IsolatesDatabase;

    private function permissionId(string $slug): int
    {
        return (int) Permission::where('slug', $slug)->value('id');
    }

    private function lastAudit(string $action): AuditLog
    {
        return AuditLog::where('action', $action)->orderByDesc('id')->firstOrFail();
    }

    public function test_creating_a_role_is_audited(): void
    {
        $admin = $this->loginAs('admin@flowsync.test');

        $this->postJson('/api/roles', [
            'name' => 'Reporter',
            'slug' => 'reporter',
            'permissions' => [$this->permissionId('reports.view')],
        ])->assertCreated();

        $row = $this->lastAudit('role.created');
        $this->assertSame($this->acme()->id, $row->data['tenant_id']);
        $this->assertSame($admin->id, $row->data['actor_user_id']);
        $this->assertSame('reporter', $row->data['role']);
        $this->assertSame(['reports.view'], $row->data['after']['permissions']);
        $this->assertNull($row->data['before']);
    }

    public function test_updating_a_role_audits_only_what_changed(): void
    {
        $this->loginAs('admin@flowsync.test');
        $role = Role::create(['name' => 'Old', 'slug' => 'old']);
        $role->permissions()->sync([$this->permissionId('reports.view')]);

        $this->putJson("/api/roles/{$role->id}", [
            'name' => 'New',
            'permissions' => [$this->permissionId('reports.view')],
        ])->assertOk();

        $row = $this->lastAudit('role.updated');
        $this->assertSame(['name' => 'Old'], $row->data['before']);
        $this->assertSame(['name' => 'New'], $row->data['after']);
    }

    public function test_a_no_op_role_save_writes_no_audit_row(): void
    {
        $this->loginAs('admin@flowsync.test');
        $role = Role::create(['name' => 'Same', 'slug' => 'same']);
        $role->permissions()->sync([$this->permissionId('reports.view')]);

        $this->putJson("/api/roles/{$role->id}", [
            'name' => 'Same',
            'permissions' => [$this->permissionId('reports.view')],
        ])->assertOk();

        $this->assertFalse(AuditLog::where('action', 'role.updated')->exists());
    }

    public function test_changing_a_users_roles_is_audited_with_before_and_after(): void
    {
        $this->loginAs('admin@flowsync.test');
        $target = User::where('email', 'viewer@flowsync.test')->firstOrFail();

        $this->putJson("/api/users/{$target->id}/roles", ['roles' => ['editor']])->assertOk();

        $row = $this->lastAudit('user.roles_changed');
        $this->assertSame($target->id, $row->subject_id);
        $this->assertSame(['viewer'], $row->data['before']['roles']);
        $this->assertSame(['editor'], $row->data['after']['roles']);
        $this->assertSame($this->acme()->id, $row->data['tenant_id']);
    }
}
