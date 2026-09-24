<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();
    }

    private function acme(): Tenant
    {
        return Tenant::where('slug', 'acme')->first();
    }

    public function test_tenant_admin_can_create_custom_role(): void
    {
        $this->login('admin@flowsync.test');

        $this->postJson('/api/project-roles', [
            'name' => 'QA Engineer',
            'permissions' => ['tasks.view', 'tasks.edit', 'comments.create'],
        ])->assertCreated()
            ->assertJsonPath('role.slug', 'qa-engineer')
            ->assertJsonPath('role.is_system', false);

        $this->assertDatabaseHas('project_roles', [
            'tenant_id' => $this->acme()->id,
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
        $role = ProjectRole::create([
            'tenant_id' => $this->acme()->id,
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
        $viewer = User::where('email', 'admin@flowsync.test')->first();
        $role = ProjectRole::create([
            'tenant_id' => $this->acme()->id,
            'name' => 'QA Engineer',
            'slug' => 'qa-engineer',
            'is_system' => false,
            'permissions' => ['tasks.view'],
        ]);
        $ws = Workspace::create([
            'tenant_id' => $this->acme()->id,
            'created_by' => $viewer->id,
            'name' => 'Design',
            'slug' => 'design',
        ]);
        $project = Project::create([
            'tenant_id' => $this->acme()->id,
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
        $role = ProjectRole::create([
            'tenant_id' => $this->acme()->id,
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
        $acmeRole = ProjectRole::where('tenant_id', $this->acme()->id)
            ->where('slug', 'lead')
            ->first();

        $this->login('owner@globex.test');

        $this->putJson("/api/project-roles/{$acmeRole->id}", [
            'name' => 'Renamed',
            'permissions' => ['tasks.view'],
        ])->assertNotFound();
    }
}
