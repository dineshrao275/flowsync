<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->first();
    }

    private function editor(): User
    {
        return User::where('email', 'editor@flowsync.test')->first();
    }

    private function viewer(): User
    {
        return User::where('email', 'viewer@flowsync.test')->first();
    }

    private function makeWorkspace(User $creator, string $name = 'Design', string $slug = 'design'): Workspace
    {
        $workspace = Workspace::create([
            'created_by' => $creator->id,
            'name' => $name,
            'slug' => $slug,
        ]);
        $workspace->members()->attach($creator->id, ['role' => 'owner', 'added_by' => $creator->id]);

        return $workspace;
    }

    public function test_non_member_cannot_list_other_workspaces(): void
    {
        $this->makeWorkspace($this->admin(), 'Secret', 'secret');
        $this->login('viewer@flowsync.test');

        $this->getJson('/api/workspaces')
            ->assertOk()
            ->assertJsonCount(0, 'workspaces');
    }

    public function test_tenant_admin_sees_all_workspaces_in_tenant(): void
    {
        $this->makeWorkspace($this->admin(), 'Alpha', 'alpha');
        $this->makeWorkspace($this->editor(), 'Beta', 'beta');

        $this->login('admin@flowsync.test');

        $this->getJson('/api/workspaces')
            ->assertOk()
            ->assertJsonCount(2, 'workspaces');
    }

    public function test_member_sees_only_workspaces_they_belong_to(): void
    {
        $ws = $this->makeWorkspace($this->admin(), 'Design', 'design');
        $this->makeWorkspace($this->admin(), 'Other', 'other');

        $ws->members()->attach($this->viewer()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);

        $this->login('viewer@flowsync.test');

        $this->getJson('/api/workspaces')
            ->assertOk()
            ->assertJsonCount(1, 'workspaces')
            ->assertJsonPath('workspaces.0.slug', 'design');
    }

    public function test_admin_can_create_workspace_and_becomes_owner(): void
    {
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson('/api/workspaces', [
            'name' => 'Products',
            'description' => 'Product delivery',
        ])->assertCreated()
            ->assertJsonPath('workspace.slug', 'products');

        $this->assertDatabaseHas('workspaces', ['name' => 'Products', 'slug' => 'products']);
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => Workspace::where('slug', 'products')->value('id'),
            'user_id' => $this->admin()->id,
            'role' => 'owner',
        ]);
    }

    public function test_editor_can_create_workspace(): void
    {
        $this->login('editor@flowsync.test');

        $this->postJson('/api/workspaces', ['name' => 'Editor Workspace'])
            ->assertCreated();
    }

    public function test_viewer_without_create_permission_gets_403(): void
    {
        $this->login('viewer@flowsync.test');

        $this->postJson('/api/workspaces', ['name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_duplicate_slug_in_same_tenant_gets_suffixed(): void
    {
        $this->makeWorkspace($this->admin(), 'Design', 'design');
        $this->login('editor@flowsync.test');

        $this->postJson('/api/workspaces', ['name' => 'Design'])
            ->assertCreated()
            ->assertJsonPath('workspace.slug', 'design-2');
    }

    public function test_owner_can_update_workspace_settings(): void
    {
        $ws = $this->makeWorkspace($this->admin());
        $this->login('admin@flowsync.test');

        $this->putJson("/api/workspaces/{$ws->id}", [
            'name' => 'Design Hub',
            'description' => 'Updated',
        ])->assertOk()
            ->assertJsonPath('workspace.name', 'Design Hub');
    }

    public function test_plain_member_cannot_update_workspace(): void
    {
        $ws = $this->makeWorkspace($this->admin());
        $ws->members()->attach($this->viewer()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);

        $this->login('viewer@flowsync.test');

        $this->putJson("/api/workspaces/{$ws->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_admin_member_can_update_workspace(): void
    {
        $ws = $this->makeWorkspace($this->admin());
        $ws->members()->attach($this->viewer()->id, ['role' => 'admin', 'added_by' => $this->admin()->id]);

        $this->login('viewer@flowsync.test');

        $this->putJson("/api/workspaces/{$ws->id}", ['name' => 'Admin Managed'])
            ->assertOk();
    }

    public function test_non_member_cannot_view_workspace(): void
    {
        $ws = $this->makeWorkspace($this->admin());
        $this->login('viewer@flowsync.test');

        $this->getJson("/api/workspaces/{$ws->id}")->assertForbidden();
    }

    public function test_owner_can_archive_and_restore_workspace(): void
    {
        $ws = $this->makeWorkspace($this->admin());
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/workspaces/{$ws->id}/archive")->assertOk();
        $this->assertNotNull($ws->fresh()->archived_at);

        $this->postJson("/api/workspaces/{$ws->id}/restore")->assertOk();
        $this->assertNull($ws->fresh()->archived_at);
    }

    public function test_member_cannot_archive_workspace(): void
    {
        $ws = $this->makeWorkspace($this->admin());
        $ws->members()->attach($this->viewer()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);

        $this->login('viewer@flowsync.test');

        $this->postJson("/api/workspaces/{$ws->id}/archive")->assertForbidden();
    }

    public function test_workspace_with_projects_cannot_be_deleted(): void
    {
        $ws = $this->makeWorkspace($this->admin());
        Project::create([
            'workspace_id' => $ws->id,
            'created_by' => $this->admin()->id,
            'name' => 'App',
            'key' => 'APP',
        ]);

        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->deleteJson("/api/workspaces/{$ws->id}")
            ->assertUnprocessable();
        $this->assertDatabaseHas('workspaces', ['id' => $ws->id]);
    }

    public function test_empty_workspace_can_be_deleted_by_owner(): void
    {
        $ws = $this->makeWorkspace($this->admin());
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->deleteJson("/api/workspaces/{$ws->id}")->assertOk();
        $this->assertDatabaseMissing('workspaces', ['id' => $ws->id]);
    }

    public function test_cross_tenant_workspace_is_not_accessible(): void
    {
        $ws = $this->makeWorkspace($this->admin());
        $this->login('owner@globex.test');

        $this->getJson("/api/workspaces/{$ws->id}")->assertNotFound();
    }

    public function test_super_admin_without_tenant_context_is_blocked(): void
    {
        $this->login('superadmin@flowsync.test');

        $this->getJson('/api/workspaces')->assertForbidden();
    }

    public function test_impersonating_super_admin_is_scoped_to_target_tenant(): void
    {
        $this->makeWorkspace($this->admin(), 'Acme Design', 'acme-design');
        $this->login('superadmin@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson('/api/impersonate', ['user_id' => $this->admin()->id])->assertOk();

        $this->getJson('/api/workspaces')
            ->assertOk()
            ->assertJsonCount(1, 'workspaces')
            ->assertJsonPath('workspaces.0.slug', 'acme-design');
    }
}
