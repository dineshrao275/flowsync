<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class WorkspaceMemberTest extends TestCase
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

    private function makeWorkspace(): Workspace
    {
        $workspace = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => 'Design',
            'slug' => 'design',
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        return $workspace;
    }

    public function test_owner_can_add_member(): void
    {
        $ws = $this->makeWorkspace();
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/workspaces/{$ws->id}/members", [
            'user_id' => $this->editor()->id,
            'role' => 'member',
        ])->assertCreated();

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $ws->id,
            'user_id' => $this->editor()->id,
            'role' => 'member',
        ]);
    }

    public function test_member_list_shows_roles(): void
    {
        $ws = $this->makeWorkspace();
        $ws->members()->attach($this->editor()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);
        $this->login('admin@flowsync.test');

        $this->getJson("/api/workspaces/{$ws->id}/members")
            ->assertOk()
            ->assertJsonCount(2, 'members');
    }

    public function test_plain_member_cannot_add_members(): void
    {
        $ws = $this->makeWorkspace();
        $ws->members()->attach($this->viewer()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);
        $this->login('viewer@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/workspaces/{$ws->id}/members", [
            'user_id' => $this->editor()->id,
            'role' => 'member',
        ])->assertForbidden();
    }

    public function test_workspace_admin_can_add_members(): void
    {
        $ws = $this->makeWorkspace();
        $ws->members()->attach($this->viewer()->id, ['role' => 'admin', 'added_by' => $this->admin()->id]);
        $this->login('viewer@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/workspaces/{$ws->id}/members", [
            'user_id' => $this->editor()->id,
            'role' => 'member',
        ])->assertCreated();
    }

    public function test_cannot_add_user_from_different_tenant(): void
    {
        $ws = $this->makeWorkspace();
        $this->dbm->using($this->globex(), fn () => User::create([
            'name' => 'Globex User',
            'email' => 'gv@globex.test',
            'password' => 'password',
        ]));

        // Phase 13: user ids are tenant-LOCAL — a Globex id has no meaning in
        // the Acme tenant database, so any id that fails to resolve there is
        // rejected. (A naive `$globexViewer->id` can coincidentally match an
        // Acme user, so use an id that is guaranteed absent.)
        $missingId = $this->dbm->using($this->acme(), fn () => User::max('id')) + 100;

        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/workspaces/{$ws->id}/members", [
            'user_id' => $missingId,
            'role' => 'member',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_cannot_add_existing_member_again(): void
    {
        $ws = $this->makeWorkspace();
        $ws->members()->attach($this->editor()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/workspaces/{$ws->id}/members", [
            'user_id' => $this->editor()->id,
            'role' => 'member',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_owner_can_change_member_role(): void
    {
        $ws = $this->makeWorkspace();
        $ws->members()->attach($this->editor()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->putJson("/api/workspaces/{$ws->id}/members/{$this->editor()->id}", [
            'role' => 'admin',
        ])->assertOk();

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $ws->id,
            'user_id' => $this->editor()->id,
            'role' => 'admin',
        ]);
    }

    public function test_plain_member_cannot_change_roles(): void
    {
        $ws = $this->makeWorkspace();
        $ws->members()->attach($this->editor()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);
        $ws->members()->attach($this->viewer()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);
        $this->login('viewer@flowsync.test');
        $this->connectTenant('acme');

        $this->putJson("/api/workspaces/{$ws->id}/members/{$this->editor()->id}", [
            'role' => 'admin',
        ])->assertForbidden();
    }

    public function test_last_owner_cannot_be_demoted(): void
    {
        $ws = $this->makeWorkspace();
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->putJson("/api/workspaces/{$ws->id}/members/{$this->admin()->id}", [
            'role' => 'member',
        ])->assertUnprocessable();
    }

    public function test_owner_can_remove_member(): void
    {
        $ws = $this->makeWorkspace();
        $ws->members()->attach($this->editor()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->deleteJson("/api/workspaces/{$ws->id}/members/{$this->editor()->id}")->assertOk();
        $this->assertDatabaseMissing('workspace_members', [
            'workspace_id' => $ws->id,
            'user_id' => $this->editor()->id,
        ]);
    }

    public function test_last_owner_cannot_be_removed(): void
    {
        $ws = $this->makeWorkspace();
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->deleteJson("/api/workspaces/{$ws->id}/members/{$this->admin()->id}")
            ->assertUnprocessable();
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $ws->id,
            'user_id' => $this->admin()->id,
        ]);
    }

    public function test_non_member_cannot_manage_membership(): void
    {
        $ws = $this->makeWorkspace();
        $this->login('viewer@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/workspaces/{$ws->id}/members", [
            'user_id' => $this->editor()->id,
            'role' => 'member',
        ])->assertForbidden();
    }
}
