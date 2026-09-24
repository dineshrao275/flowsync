<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class ProjectMemberTest extends TestCase
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
        $workspace->members()->attach($this->editor()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);
        $workspace->members()->attach($this->viewer()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);

        return $workspace;
    }

    private function makeProject(): Project
    {
        $ws = $this->makeWorkspace();
        $project = Project::create([
            'workspace_id' => $ws->id,
            'created_by' => $this->admin()->id,
            'lead_user_id' => $this->admin()->id,
            'name' => 'Alpha',
            'key' => 'ALPHA',
        ]);
        $leadRole = ProjectRole::where('slug', 'lead')->first();
        $project->members()->attach($this->admin()->id, ['project_role_id' => $leadRole->id, 'added_by' => $this->admin()->id]);

        return $project;
    }

    private function roleId(string $slug): int
    {
        return ProjectRole::where('slug', $slug)->firstOrFail()->id;
    }

    private function addMember(Project $project, User $user, string $role = 'viewer'): void
    {
        $project->members()->attach($user->id, ['project_role_id' => $this->roleId($role), 'added_by' => $this->admin()->id]);
    }

    public function test_lead_can_add_project_member(): void
    {
        $project = $this->makeProject();
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/projects/{$project->id}/members", [
            'user_id' => $this->viewer()->id,
            'role_id' => $this->roleId('developer'),
        ])->assertCreated();

        $this->assertTrue($project->isMember($this->viewer()));
    }

    public function test_tenant_catalog_roles_are_listed(): void
    {
        $project = $this->makeProject();
        $this->login('admin@flowsync.test');

        $this->getJson('/api/project-roles')
            ->assertOk()
            ->assertJsonCount(3, 'roles');
    }

    public function test_member_list_shows_role_names(): void
    {
        $project = $this->makeProject();
        $this->addMember($project, $this->editor(), 'developer');
        $this->login('admin@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/members")
            ->assertOk()
            ->assertJsonCount(2, 'members')
            ->assertJsonPath('members.1.role.slug', 'developer');
    }

    public function test_developer_without_members_manage_cannot_add(): void
    {
        $project = $this->makeProject();
        $this->addMember($project, $this->editor(), 'developer');
        $this->login('editor@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/projects/{$project->id}/members", [
            'user_id' => $this->viewer()->id,
            'role_id' => $this->roleId('viewer'),
        ])->assertForbidden();
    }

    public function test_member_with_members_manage_role_can_add(): void
    {
        $project = $this->makeProject();
        $project->members()->attach(
            $this->editor()->id,
            ['project_role_id' => $this->createRoleWith('members.manage') ?? $this->roleId('lead'), 'added_by' => $this->admin()->id]
        );
        $this->login('editor@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/projects/{$project->id}/members", [
            'user_id' => $this->viewer()->id,
            'role_id' => $this->roleId('viewer'),
        ])->assertCreated();
    }

    public function test_cannot_add_user_from_different_tenant(): void
    {
        $project = $this->makeProject();
        $this->dbm->using($this->globex(), fn () => User::create([
            'name' => 'Globex Owner',
            'email' => 'owner2@globex.test',
            'password' => 'password',
        ]));

        // Phase 13: user ids are tenant-LOCAL — a Globex id has no meaning in
        // the Acme tenant database, so any id that fails to resolve there is
        // rejected. (A naive `$globex->id` can coincidentally match an Acme
        // user, so use an id that is guaranteed absent.)
        $missingId = $this->dbm->using($this->acme(), fn () => User::max('id')) + 100;

        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/projects/{$project->id}/members", [
            'user_id' => $missingId,
            'role_id' => $this->roleId('viewer'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_cannot_add_user_who_is_not_a_workspace_member(): void
    {
        $project = $this->makeProject();
        $orphan = User::create([
            'name' => 'Orphan',
            'email' => 'orphan@acme.test',
            'password' => 'password',
        ]);
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/projects/{$project->id}/members", [
            'user_id' => $orphan->id,
            'role_id' => $this->roleId('viewer'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_cannot_add_existing_member_again(): void
    {
        $project = $this->makeProject();
        $this->addMember($project, $this->viewer());
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/projects/{$project->id}/members", [
            'user_id' => $this->viewer()->id,
            'role_id' => $this->roleId('viewer'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_lead_can_change_member_role_and_remove(): void
    {
        $project = $this->makeProject();
        $this->addMember($project, $this->viewer(), 'viewer');
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->putJson("/api/projects/{$project->id}/members/{$this->viewer()->id}", [
            'role_id' => $this->roleId('developer'),
        ])->assertOk();

        $role = $project->members()->where('user_id', $this->viewer()->id)->first()->pivot->project_role_id;
        $this->assertSame($this->roleId('developer'), $role);

        $this->deleteJson("/api/projects/{$project->id}/members/{$this->viewer()->id}")->assertOk();
        $this->assertFalse($project->fresh()->isMember($this->viewer()));
    }

    public function test_last_member_cannot_be_removed(): void
    {
        $project = $this->makeProject();
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->deleteJson("/api/projects/{$project->id}/members/{$this->admin()->id}")
            ->assertUnprocessable();
        $this->assertTrue($project->fresh()->isMember($this->admin()));
    }

    public function test_project_member_cannot_manage_membership_without_permission(): void
    {
        $project = $this->makeProject();
        $this->addMember($project, $this->viewer(), 'viewer');
        $this->login('viewer@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/projects/{$project->id}/members", [
            'user_id' => $this->editor()->id,
            'role_id' => $this->roleId('viewer'),
        ])->assertForbidden();
    }

    private function createRoleWith(string $permission): int
    {
        $role = ProjectRole::create([
            'name' => 'Member Manager',
            'slug' => 'member-manager',
            'is_system' => false,
            'permissions' => [$permission],
        ]);

        return $role->id;
    }
}
