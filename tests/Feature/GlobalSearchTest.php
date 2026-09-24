<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TenantProvisioner;
use Illuminate\Support\Facades\Hash;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->first();
    }

    private function viewer(): User
    {
        return User::where('email', 'viewer@flowsync.test')->first();
    }

    private function search(string $q)
    {
        return $this->getJson("/api/search/global?q={$q}");
    }

    private function createProject(Workspace $workspace, string $name, string $key): Project
    {
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $this->admin()->id,
            'lead_user_id' => $this->admin()->id,
            'name' => $name,
            'key' => $key,
        ]);
        $leadRole = ProjectRole::where('slug', 'lead')->first();
        $project->members()->attach($this->admin()->id, ['project_role_id' => $leadRole->id, 'added_by' => $this->admin()->id]);

        foreach (config('task_statuses.statuses') as $status) {
            TaskStatus::create([
                'project_id' => $project->id,
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => $status['category'],
                'position' => $status['position'],
                'color' => $status['color'],
                'is_done' => $status['is_done'],
                'is_default' => $status['is_default'] ?? false,
            ]);
        }

        return $project;
    }

    private function makeTask(Workspace $workspace, string $title, string $key): Task
    {
        $project = $this->createProject($workspace, "{$title} project", $key);
        $viewerRole = ProjectRole::where('slug', 'viewer')->first();
        $project->members()->attach($this->viewer()->id, ['project_role_id' => $viewerRole->id, 'added_by' => $this->admin()->id]);

        $status = TaskStatus::where('project_id', $project->id)->where('slug', 'to-do')->first();

        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'reporter_user_id' => $this->admin()->id,
            'assignee_id' => null,
            'status_id' => $status->id,
            'priority_id' => null,
            'key' => "TASK-{$key}",
            'sequence' => 1,
            'title' => $title,
            'position' => 1,
            'due_date' => null,
            'completed_at' => null,
        ]);
    }

    private function globexWorkspace(string $name, string $slug): Workspace
    {
        return $this->dbm->using($this->globex(), function () use ($name, $slug) {
            $owner = User::where('email', 'owner@globex.test')->first();
            $workspace = Workspace::create([
                'created_by' => $owner->id,
                'name' => $name,
                'slug' => $slug,
            ]);
            $workspace->members()->attach($owner->id, ['role' => 'owner', 'added_by' => $owner->id]);

            return $workspace;
        });
    }

    private function globexProject(string $name, string $key, Workspace $workspace): Project
    {
        return $this->dbm->using($this->globex(), function () use ($name, $key, $workspace) {
            $owner = User::where('email', 'owner@globex.test')->first();

            return Project::create([
                'workspace_id' => $workspace->id,
                'created_by' => $owner->id,
                'lead_user_id' => $owner->id,
                'name' => $name,
                'key' => $key,
            ]);
        });
    }

    public function test_requires_minimum_query_length(): void
    {
        $this->login('admin@flowsync.test');
        $this->search('a')->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_requires_workspaces_view_permission(): void
    {
        $user = User::create([
            'name' => 'Restricted',
            'email' => 'restricted@acme.test',
            'password' => Hash::make('password'),
        ]);
        $this->assertNotNull($user->id);

        // Newly created users must be mirrored into the central routing index
        // before the isolated login can route them to the tenant database.
        app(TenantProvisioner::class)->syncRouting($this->dbm, $this->acme());

        $this->login('restricted@acme.test');
        $this->search('anything')->assertForbidden();
    }

    public function test_tenant_user_search_is_scoped_to_tenant_and_memberships(): void
    {
        $workspace = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => 'Acme Cloud',
            'slug' => 'acme-cloud',
        ]);
        $workspace->members()->attach($this->viewer()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);
        $this->makeTask($workspace, 'Fix the login page', '100');

        $globexWorkspace = $this->globexWorkspace('Globex Billing', 'globex-billing');
        $this->globexProject('Login portal', 'GLB-LOGIN', $globexWorkspace);

        $this->login('viewer@flowsync.test');

        $response = $this->search('login')->assertOk();
        // Viewer contributes to the Acme project, so its task is visible; the
        // Globex project lives in another tenant database where the viewer has
        // no memberships and cannot be reached.
        $this->assertSame(1, count($response->json('results.tasks')));
        $this->assertSame('Fix the login page', $response->json('results.tasks.0.title'));
        $keys = array_column($response->json('results.projects'), 'key');
        $this->assertNotContains('GLB-LOGIN', $keys);

        $workspaces = $this->search('Cloud')->assertOk()->json('results.workspaces');
        $this->assertSame('Acme Cloud', $workspaces[0]['name'] ?? null);
    }

    public function test_tenant_admin_search_covers_whole_tenant(): void
    {
        $workspace = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => 'Acme Cloud',
            'slug' => 'acme-cloud',
        ]);
        $this->makeTask($workspace, 'Fix the login page', '100');

        $this->login('admin@flowsync.test');

        $response = $this->search('login')->assertOk();
        $this->assertSame(1, count($response->json('results.tasks')));
        $this->assertSame('Fix the login page', $response->json('results.tasks.0.title'));
    }

    public function test_super_admin_search_covers_all_tenants_and_users(): void
    {
        $workspace = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => 'Acme Cloud',
            'slug' => 'acme-cloud',
        ]);
        $this->makeTask($workspace, 'Fix the login page', '100');

        $this->globexWorkspace('Globex Billing', 'globex-billing');

        $this->login('superadmin@flowsync.test');

        $response = $this->search('login')->assertOk();
        $this->assertSame(1, count($response->json('results.tasks')));

        $users = $this->search('globex')->assertOk()->json('results.users');
        $this->assertNotEmpty($users);
    }

    public function test_impersonating_super_admin_is_scoped_to_target_tenant(): void
    {
        $workspace = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => 'Acme Cloud',
            'slug' => 'acme-cloud',
        ]);
        $this->makeTask($workspace, 'Fix the login page', '100');
        $this->globexWorkspace('Globex login vault', 'globex-login');

        $this->login('superadmin@flowsync.test');

        // HTTP login left the default connection on the system DB; resolve the
        // target on Acme's tenant DB before impersonating.
        $this->connectTenant('acme');

        $this->postJson('/api/impersonate', ['user_id' => $this->viewer()->id])->assertOk();

        $response = $this->search('login')->assertOk();
        $this->assertSame(1, count($response->json('results.tasks')));
        $this->assertSame([], $response->json('results.workspaces'));
    }

    public function test_users_search_gated_by_users_view_permission(): void
    {
        $this->login('viewer@flowsync.test');
        $response = $this->search('flowsync')->assertOk();
        $this->assertSame([], $response->json('results.users'));
    }
}