<?php

namespace Tests\Feature;

use App\Models\Role;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F10.6 — role template gallery: list, apply into an ordinary custom role, respect the admin's own ceiling. */
class RoleTemplateTest extends TestCase
{
    use IsolatesDatabase;

    public function test_an_admin_can_list_and_apply_a_template(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();

        $templates = $this->getJson('/api/roles/templates')->assertOk()->json('templates');
        $this->assertContains('read_only', array_column($templates, 'key'));

        $this->postJson('/api/roles/templates/read_only', ['name' => 'Observers'])->assertCreated()->assertJsonPath('role.slug', 'observers');
        $this->connectTenant('acme');
        $role = Role::where('slug', 'observers')->firstOrFail();
        $this->assertEqualsCanonicalizing(['dashboard.view', 'workspaces.view', 'reports.view'], $role->permissions()->pluck('slug')->all());
        $this->assertFalse((bool) $role->is_system);
    }

    public function test_an_unknown_template_is_404_and_viewers_cannot_apply(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->postJson('/api/roles/templates/nope')->assertNotFound();

        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'viewer@flowsync.test', 'password' => 'password'])->assertOk();
        $this->postJson('/api/roles/templates/read_only')->assertForbidden();
    }
}
