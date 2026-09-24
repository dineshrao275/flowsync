<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $acme = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->acme = Tenant::where('slug', 'acme')->first();
    }

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
            'tenant_id' => $this->acme->id,
            'created_by' => $this->admin()->id,
            'name' => 'Design',
            'slug' => 'design',
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        return $workspace;
    }

    private function addWorkspaceMember(Workspace $workspace, User $user, string $role = 'member'): void
    {
        $workspace->members()->attach($user->id, ['role' => $role, 'added_by' => $this->admin()->id]);
    }

    public function test_admin_can_create_project_with_key_statuses_and_lead(): void
    {
        $ws = $this->makeWorkspace();
        $this->login('admin@flowsync.test');

        $this->postJson("/api/workspaces/{$ws->id}/projects", [
            'name' => 'Website Redesign',
            'description' => 'New marketing site',
        ])->assertCreated()
            ->assertJsonPath('project.key', 'WR')
            ->assertJsonPath('project.my_role', 'lead');

        $project = Project::where('workspace_id', $ws->id)->firstOrFail();
        $this->assertSame('Website Redesign', $project->name);
        $this->assertSame($this->admin()->id, $project->lead_user_id);
        $this->assertTrue($project->statuses()->count() === 5);
        $this->assertTrue($project->isMember($this->admin()));
    }

    public function test_project_key_is_unique_per_tenant(): void
    {
        $ws = $this->makeWorkspace();
        $this->login('admin@flowsync.test');

        $this->postJson("/api/workspaces/{$ws->id}/projects", ['name' => 'Website Redesign'])->assertCreated();
        $this->postJson("/api/workspaces/{$ws->id}/projects", ['name' => 'Website Redesign 2'])
            ->assertCreated()
            ->assertJsonPath('project.key', 'WR2');

        $this->assertSame(2, Project::where('workspace_id', $ws->id)->count());
    }

    public function test_workspace_manager_can_create_project(): void
    {
        $ws = $this->makeWorkspace();
        $this->addWorkspaceMember($ws, $this->editor(), 'admin');
        $this->login('editor@flowsync.test');

        $this->postJson("/api/workspaces/{$ws->id}/projects", ['name' => 'Mobile App'])
            ->assertCreated();
    }

    public function test_plain_workspace_member_cannot_create_project(): void
    {
        $ws = $this->makeWorkspace();
        $this->addWorkspaceMember($ws, $this->viewer());
        $this->login('viewer@flowsync.test');

        $this->postJson("/api/workspaces/{$ws->id}/projects", ['name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_tenant_admin_sees_all_projects_and_member_only_own(): void
    {
        $ws = $this->makeWorkspace();

        $projectA = $this->createProject($ws, 'Alpha', 'ALPHA');
        $this->addWorkspaceMember($ws, $this->viewer());
        $this->addProjectMember($projectA, $this->viewer());
        $projectB = $this->createProject($ws, 'Beta', 'BETA');

        $this->login('admin@flowsync.test');
        $this->getJson("/api/workspaces/{$ws->id}/projects")
            ->assertOk()
            ->assertJsonCount(2, 'projects');

        $this->login('viewer@flowsync.test');
        $this->getJson("/api/workspaces/{$ws->id}/projects")
            ->assertOk()
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.key', 'ALPHA');
    }

    public function test_global_projects_list_is_scoped_to_visibility(): void
    {
        $ws = $this->makeWorkspace();
        $projectA = $this->createProject($ws, 'Alpha', 'ALPHA');
        $this->addWorkspaceMember($ws, $this->viewer());
        $this->addProjectMember($projectA, $this->viewer());
        $this->createProject($ws, 'Beta', 'BETA');

        $this->login('admin@flowsync.test');
        $this->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(2, 'projects')
            ->assertJsonPath('projects.0.workspace.name', 'Design');

        $this->login('viewer@flowsync.test');
        $this->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.key', 'ALPHA');
    }

    public function test_lead_can_update_project(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws, 'Alpha', 'ALPHA');
        $this->login('admin@flowsync.test');

        $this->putJson("/api/projects/{$project->id}", [
            'name' => 'Alpha Plus',
            'description' => 'Updated',
        ])->assertOk()
            ->assertJsonPath('project.name', 'Alpha Plus');
    }

    public function test_member_with_edit_permission_can_update(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws, 'Alpha', 'ALPHA');
        $role = ProjectRole::create([
            'tenant_id' => $this->acme->id,
            'name' => 'Contributor',
            'slug' => 'contributor',
            'is_system' => false,
            'permissions' => ['projects.view', 'projects.edit'],
        ]);
        $project->members()->attach($this->editor()->id, ['project_role_id' => $role->id, 'added_by' => $this->admin()->id]);

        $this->login('editor@flowsync.test');
        $this->putJson("/api/projects/{$project->id}", ['name' => 'Edited'])
            ->assertOk();
    }

    public function test_viewer_project_member_cannot_update(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws, 'Alpha', 'ALPHA');
        $this->addProjectMember($project, $this->viewer(), 'viewer');

        $this->login('viewer@flowsync.test');
        $this->putJson("/api/projects/{$project->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_non_member_cannot_view_project(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws, 'Alpha', 'ALPHA');
        $this->login('viewer@flowsync.test');

        $this->getJson("/api/projects/{$project->id}")->assertForbidden();
    }

    public function test_lead_can_archive_restore_delete(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws, 'Alpha', 'ALPHA');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/archive")->assertOk();
        $this->assertNotNull($project->fresh()->archived_at);

        $this->postJson("/api/projects/{$project->id}/restore")->assertOk();
        $this->assertNull($project->fresh()->archived_at);

        $this->deleteJson("/api/projects/{$project->id}")->assertOk();
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_project_with_tasks_cannot_be_deleted(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws, 'Alpha', 'ALPHA');

        Task::create([
            'tenant_id' => $this->acme->id,
            'workspace_id' => $ws->id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'key' => 'ALPHA-1',
            'sequence' => 1,
            'title' => 'Task one',
            'status_id' => $project->statuses()->first()->id,
        ]);

        $this->login('admin@flowsync.test');

        $this->deleteJson("/api/projects/{$project->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('form');
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_cross_tenant_project_is_not_accessible(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws, 'Alpha', 'ALPHA');
        $this->login('owner@globex.test');

        $this->getJson("/api/projects/{$project->id}")->assertNotFound();
    }

    private function createProject(Workspace $workspace, string $name, string $key): Project
    {
        $project = Project::create([
            'tenant_id' => $this->acme->id,
            'workspace_id' => $workspace->id,
            'created_by' => $this->admin()->id,
            'lead_user_id' => $this->admin()->id,
            'name' => $name,
            'key' => $key,
        ]);
        $this->seedStatuses($project);
        $leadRole = ProjectRole::where('slug', 'lead')->first();
        $project->members()->attach($this->admin()->id, ['project_role_id' => $leadRole->id, 'added_by' => $this->admin()->id]);

        return $project;
    }

    private function addProjectMember(Project $project, User $user, string $roleSlug = 'viewer'): void
    {
        $role = ProjectRole::where('slug', $roleSlug)->first();
        $project->members()->attach($user->id, ['project_role_id' => $role->id, 'added_by' => $this->admin()->id]);
    }

    private function seedStatuses(Project $project): void
    {
        $position = 0;
        foreach (config('task_statuses.statuses') as $status) {
            $position++;
            TaskStatus::create([
                'tenant_id' => $this->acme->id,
                'project_id' => $project->id,
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => $status['category'],
                'position' => $position,
                'color' => $status['color'] ?? null,
                'is_default' => $status['is_default'] ?? false,
                'is_done' => $status['is_done'] ?? false,
            ]);
        }
    }
}
