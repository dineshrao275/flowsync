<?php

namespace Tests\Feature;

use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TenantContext;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->acme = Tenant::where('slug', 'acme')->first();
        app(TenantContext::class)->setTenantId($this->acme->id);
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
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

    private function createProject(string $name, string $key, string $workspaceSlug): Project
    {
        $workspace = Workspace::create([
            'tenant_id' => $this->acme->id,
            'created_by' => $this->admin()->id,
            'name' => $name,
            'slug' => $workspaceSlug,
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        $project = Project::create([
            'tenant_id' => $this->acme->id,
            'workspace_id' => $workspace->id,
            'created_by' => $this->admin()->id,
            'lead_user_id' => $this->admin()->id,
            'name' => $name,
            'key' => $key,
        ]);
        $leadRole = ProjectRole::where('slug', 'lead')->first();
        $project->members()->attach($this->admin()->id, ['project_role_id' => $leadRole->id, 'added_by' => $this->admin()->id]);

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

        return $project;
    }

    private function addProjectMember(Project $project, User $user): void
    {
        $role = ProjectRole::where('slug', 'viewer')->first();
        $project->members()->attach($user->id, ['project_role_id' => $role->id, 'added_by' => $this->admin()->id]);
    }

    private function makeTask(Project $project, string $title, ?string $statusSlug = null, array $extra = []): Task
    {
        $project->increment('last_task_sequence');
        $status = $statusSlug
            ? $project->statuses()->where('slug', $statusSlug)->first()
            : $project->statuses()->where('is_default', true)->first();
        $defaultPriority = Priority::where('tenant_id', $this->acme->id)->where('is_default', true)->first();

        return Task::create(array_merge([
            'tenant_id' => $this->acme->id,
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'reporter_id' => $this->admin()->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => $title,
            'status_id' => $status->id,
            'priority_id' => $defaultPriority->id,
            'position' => 1,
            'completed_at' => $status->is_done ? now() : null,
        ], $extra));
    }

    // ---------------- Search ----------------

    public function test_search_returns_matching_tasks_only_from_visible_projects(): void
    {
        $a = $this->createProject('Alfa', 'ALF', 'alfa-ws');
        $b = $this->createProject('Bravo', 'BRA', 'bravo-ws');
        $this->addProjectMember($a, $this->viewer());
        $taskA = $this->makeTask($a, 'Fix the login page');
        $taskB = $this->makeTask($b, 'Fix the login page');
        $this->login('viewer@flowsync.test');

        $this->getJson('/api/search/tasks?q=login')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('tasks.0.id', $taskA->id)
            ->assertJsonPath('tasks.0.project.id', $a->id)
            ->assertJsonPath('tasks.0.workspace.id', $a->workspace_id);

        $this->assertSame($taskB->id, $b->tasks()->first()->id);
    }

    public function test_admin_search_sees_all_tenant_tasks(): void
    {
        $a = $this->createProject('Alfa', 'ALF', 'alfa-ws');
        $b = $this->createProject('Bravo', 'BRA', 'bravo-ws');
        $this->makeTask($a, 'Billing export');
        $this->makeTask($b, 'Billing export');
        $this->login('admin@flowsync.test');

        $this->getJson('/api/search/tasks?q=Billing')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonCount(2, 'filters.projects')
            ->assertJsonCount(2, 'filters.statuses')
            ->assertJsonCount(1, 'filters.priorities')
            ->assertJsonCount(2, 'filters.workspaces');
    }

    public function test_search_filters_by_status_priority_assignee_project_workspace_and_label(): void
    {
        $project = $this->createProject('Filter', 'FIL', 'filter-ws');
        $this->addProjectMember($project, $this->viewer());
        $this->makeTask($project, 'One');
        $this->makeTask($project, 'Two', 'done', ['assignee_id' => $this->viewer()->id, 'due_date' => '2026-03-01']);
        $priority = Priority::where('tenant_id', $this->acme->id)->where('is_default', true)->first();
        $status = $project->statuses()->where('slug', 'done')->first();
        $this->login('viewer@flowsync.test');

        $this->getJson("/api/search/tasks?status_id={$status->id}")
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('tasks.0.title', 'Two');

        $this->getJson("/api/search/tasks?assignee_id={$this->viewer()->id}")
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        $this->getJson("/api/search/tasks?project_id={$project->id}")
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);

        $this->getJson("/api/search/tasks?workspace_id={$project->workspace_id}")
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);

        $this->getJson('/api/search/tasks?due_from=2026-03-01&due_to=2026-03-31')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        $this->getJson('/api/search/tasks?assignee=me')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        $this->getJson("/api/search/tasks?priority_id={$priority->id}")
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
    }

    // ---------------- Dashboard ----------------

    public function test_dashboard_reports_my_tasks_and_counts(): void
    {
        $project = $this->createProject('Dash', 'DASH', 'dash-ws');
        $this->addProjectMember($project, $this->viewer());
        $overdue = $this->makeTask($project, 'Overdue task', 'to-do', ['assignee_id' => $this->viewer()->id, 'due_date' => '2020-01-01']);
        $dueSoon = $this->makeTask($project, 'Due soon', 'to-do', ['assignee_id' => $this->viewer()->id, 'due_date' => now()->addDays(2)->toDateString()]);
        $inProgress = $this->makeTask($project, 'In progress', 'in-progress', ['assignee_id' => $this->viewer()->id]);
        $this->makeTask($project, 'Assigned to admin', 'to-do', ['assignee_id' => $this->admin()->id]);
        $completed = $this->makeTask($project, 'Done task', 'done', ['assignee_id' => $this->viewer()->id]);
        $this->login('viewer@flowsync.test');

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('counts.my_open', 3)
            ->assertJsonPath('counts.my_overdue', 1)
            ->assertJsonPath('counts.my_due_soon', 1)
            ->assertJsonPath('counts.in_progress', 1)
            ->assertJsonPath('counts.open', 4)
            ->assertJsonPath('counts.done', 1)
            ->assertJsonPath('my_overdue.0.id', $overdue->id)
            ->assertJsonPath('my_due_soon.0.id', $dueSoon->id)
            ->assertJsonPath('in_progress.0.id', $inProgress->id);

        $this->assertNotContains($completed->id, collect($this->getJson('/api/dashboard')->json('my_open'))->pluck('id')->all());
    }

    public function test_dashboard_is_membership_scoped(): void
    {
        $project = $this->createProject('Dash', 'DASH', 'dash-ws');
        $this->makeTask($project, 'Not visible', 'to-do');
        $this->login('viewer@flowsync.test');

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('counts.open', 0)
            ->assertJsonCount(0, 'recent');
    }

    // ---------------- Reports ----------------

    public function test_reports_overview_builds_distributions(): void
    {
        $project = $this->createProject('Report', 'REP', 'report-ws');
        $this->addProjectMember($project, $this->viewer());
        $this->makeTask($project, 'Open task', 'to-do', ['assignee_id' => $this->viewer()->id]);
        $this->makeTask($project, 'Done task', 'done', ['assignee_id' => $this->viewer()->id]);
        $this->makeTask($project, 'Overdue task', 'to-do', ['assignee_id' => $this->viewer()->id, 'due_date' => '2020-01-01']);
        $this->login('viewer@flowsync.test');

        $this->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('totals.total', 3)
            ->assertJsonPath('totals.open', 2)
            ->assertJsonPath('totals.done', 1)
            ->assertJsonPath('totals.overdue', 1)
            ->assertJsonPath('by_status.0.count', 2)
            ->assertJsonPath('by_status.0.label', 'To Do')
            ->assertJsonPath('by_assignee.0.label', 'Viewer User')
            ->assertJsonPath('by_project.0.label', 'Report')
            ->assertJsonPath('by_project.0.count', 3);
    }

    public function test_reports_overview_is_membership_scoped(): void
    {
        $project = $this->createProject('Hidden', 'HID', 'hidden-ws');
        $this->makeTask($project, 'Hidden task', 'to-do');
        $this->login('viewer@flowsync.test');

        $this->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('totals.total', 0);
    }

    // ---------------- Permission gating ----------------

    public function test_discovery_endpoints_respect_permission_gates(): void
    {
        $reportsView = $this->acme->permissions()->where('slug', 'reports.view')->first();
        $role = Role::where('slug', 'editor')->where('tenant_id', $this->acme->id)->first();
        $role->permissions()->detach($reportsView->id);
        $this->login('editor@flowsync.test');

        $this->getJson('/api/reports/overview')->assertForbidden();
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/search/tasks')->assertOk();
    }
}
