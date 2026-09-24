<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class ProjectRoleTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_tenant_admin_can_create_custom_role(): void
    {
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson('/api/project-roles', [
            'name' => 'QA Engineer',
            'permissions' => ['tasks.view', 'tasks.edit', 'comments.create'],
        ])->assertCreated()
            ->assertJsonPath('role.slug', 'qa-engineer')
            ->assertJsonPath('role.is_system', false);

        $this->assertDatabaseHas('project_roles', [
            'slug' => 'qa-engineer',
            'is_system' => false,
        ]);
    }

    public function test_custom_role_permissions_must_be_in_catalog(): void
    {
        $this->login('admin@flowsync.test');

        $this->postJson('/api/project-roles', [
            'name' => 'Sneaky',
            'permissions' => ['delete.all', 'tasks.move'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_non_admin_cannot_create_custom_role(): void
    {
        $this->login('editor@flowsync.test');

        $this->postJson('/api/project-roles', [
            'name' => 'Sneaky',
            'permissions' => ['tasks.view'],
        ])->assertForbidden();
    }

    public function test_owner_can_update_custom_role_permissions(): void
    {
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');
        $role = ProjectRole::create([
            'name' => 'QA Engineer',
            'slug' => 'qa-engineer',
            'is_system' => false,
            'permissions' => ['tasks.view'],
        ]);

        $this->putJson("/api/project-roles/{$role->id}", [
            'name' => 'QA Lead',
            'permissions' => ['tasks.view', 'tasks.edit'],
        ])->assertOk()
            ->assertJsonPath('role.name', 'QA Lead')
            ->assertJsonPath('role.permissions', ['tasks.view', 'tasks.edit']);
    }

    public function test_system_role_cannot_be_modified_or_deleted(): void
    {
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');
        $lead = ProjectRole::where('slug', 'lead')->first();

        $this->putJson("/api/project-roles/{$lead->id}", [
            'name' => 'Renamed',
            'permissions' => ['tasks.view'],
        ])->assertUnprocessable();

        $this->deleteJson("/api/project-roles/{$lead->id}")->assertUnprocessable();
    }

    public function test_role_assigned_to_members_cannot_be_deleted(): void
    {
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');
        $viewer = User::where('email', 'admin@flowsync.test')->first();
        $role = ProjectRole::create([
            'name' => 'QA Engineer',
            'slug' => 'qa-engineer',
            'is_system' => false,
            'permissions' => ['tasks.view'],
        ]);
        $ws = Workspace::create([
            'created_by' => $viewer->id,
            'name' => 'Design',
            'slug' => 'design',
        ]);
        $project = Project::create([
            'workspace_id' => $ws->id,
            'created_by' => $viewer->id,
            'lead_user_id' => $viewer->id,
            'name' => 'Alpha',
            'key' => 'ALPHA',
        ]);
        $project->members()->attach($viewer->id, ['project_role_id' => $role->id, 'added_by' => $viewer->id]);

        $this->deleteJson("/api/project-roles/{$role->id}")->assertUnprocessable();
        $this->assertDatabaseHas('project_roles', ['id' => $role->id]);
    }

    public function test_unused_custom_role_can_be_deleted(): void
    {
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');
        $role = ProjectRole::create([
            'name' => 'Temp',
            'slug' => 'temp',
            'is_system' => false,
            'permissions' => ['tasks.view'],
        ]);

        $this->deleteJson("/api/project-roles/{$role->id}")->assertOk();
        $this->assertDatabaseMissing('project_roles', ['id' => $role->id]);
    }

    public function test_cross_tenant_role_is_not_accessible(): void
    {
        // Phase 13: ids are per-tenant-local (both tenants have a "lead" with the
        // same id), so prove isolation with a role that only exists in Acme.
        $acmeRole = ProjectRole::create([
            'name' => 'Acme only',
            'slug' => 'acme-only',
            'is_system' => false,
            'permissions' => ['tasks.view'],
        ]);

        $this->login('owner@globex.test');

        $this->putJson("/api/project-roles/{$acmeRole->id}", [
            'name' => 'Renamed',
            'permissions' => ['tasks.view'],
        ])->assertNotFound();
    }
}
