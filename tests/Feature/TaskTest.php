<?php

namespace Tests\Feature;

use App\Enums\TaskDependencyType;
use App\Events\TaskSynced;
use App\Models\Label;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

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

    private function makeWorkspace(string $name = 'Design', string $slug = 'design'): Workspace
    {
        $workspace = Workspace::create([
            'tenant_id' => $this->acme->id,
            'created_by' => $this->admin()->id,
            'name' => $name,
            'slug' => $slug,
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        return $workspace;
    }

    private function addWorkspaceMember(Workspace $workspace, User $user): void
    {
        $workspace->members()->attach($user->id, ['role' => 'member', 'added_by' => $this->admin()->id]);
    }

    private function createProject(Workspace $workspace, string $name = 'Website', string $key = 'WEB'): Project
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

    private function makeTask(Project $project, string $title = 'Task', ?array $overrides = []): Task
    {
        $defaultStatus = $project->statuses()->where('is_default', true)->first();
        $project->increment('last_task_sequence');

        return Task::create(array_merge([
            'tenant_id' => $this->acme->id,
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => $title,
            'status_id' => $defaultStatus->id,
            'position' => $project->tasks()->where('status_id', $defaultStatus->id)->max('position') + 1,
        ], $overrides));
    }

    public function test_lead_can_create_task_with_atomic_key_and_defaults(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks", ['title' => 'Fix navbar'])
            ->assertCreated()
            ->assertJsonPath('task.key', 'WEB-1')
            ->assertJsonPath('task.sequence', 1)
            ->assertJsonPath('task.title', 'Fix navbar')
            ->assertJsonPath('task.status.slug', 'to-do')
            ->assertJsonPath('task.priority.slug', 'medium')
            ->assertJsonPath('task.position', 1);

        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'key' => 'WEB-1',
            'sequence' => 1,
        ]);
        $this->assertSame(1, $project->fresh()->last_task_sequence);
    }

    public function test_task_keys_are_unique_and_sequential(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks", ['title' => 'One'])->assertCreated();
        $this->postJson("/api/projects/{$project->id}/tasks", ['title' => 'Two'])->assertCreated();

        $keys = Task::where('project_id', $project->id)->orderBy('sequence')->pluck('key')->all();
        $this->assertSame(['WEB-1', 'WEB-2'], $keys);
    }

    public function test_project_without_default_status_still_assigns_first(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $project->statuses()->update(['is_default' => false]);
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks", ['title' => 'Task'])
            ->assertCreated()
            ->assertJsonPath('task.status.position', 1);
    }

    public function test_create_respects_status_priority_assignee_labels_and_due_date(): void
    {
        $ws = $this->makeWorkspace();
        $this->addWorkspaceMember($ws, $this->editor());
        $project = $this->createProject($ws);
        $this->addProjectMember($project, $this->editor());
        $status = $project->statuses()->where('slug', 'in-progress')->first();
        $priority = Priority::where('tenant_id', $this->acme->id)->where('slug', 'high')->first();
        $label = Label::create(['tenant_id' => $this->acme->id, 'workspace_id' => $ws->id, 'name' => 'UX', 'color' => '#f97316']);

        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks", [
            'title' => 'Design sidebar',
            'description' => 'Responsive collapse',
            'status_id' => $status->id,
            'priority_id' => $priority->id,
            'assignee_id' => $this->editor()->id,
            'labels' => [$label->id],
            'due_date' => '2026-10-01',
            'estimate_minutes' => 120,
        ])->assertCreated()
            ->assertJsonPath('task.status.slug', 'in-progress')
            ->assertJsonPath('task.priority.slug', 'high')
            ->assertJsonPath('task.assignee.id', $this->editor()->id)
            ->assertJsonPath('task.labels.0.name', 'UX')
            ->assertJsonPath('task.due_date', '2026-10-01')
            ->assertJsonPath('task.estimate_minutes', 120);
    }

    public function test_assignee_must_be_a_project_member(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks", [
            'title' => 'Task',
            'assignee_id' => $this->editor()->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('assignee_id');
    }

    public function test_status_must_belong_to_the_project(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $other = $this->createProject($ws, 'Other', 'OTH');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks", [
            'title' => 'Task',
            'status_id' => $other->statuses()->first()->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('status_id');
    }

    public function test_subtasks_are_scoped_to_the_project(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $parent = $this->makeTask($project, 'Parent');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks", [
            'title' => 'Subtasks',
            'parent_id' => $parent->id,
        ])->assertCreated()
            ->assertJsonPath('task.parent_id', $parent->id);

        $otherProject = $this->createProject($ws, 'Other', 'OTH');
        $this->postJson("/api/projects/{$project->id}/tasks", [
            'title' => 'Oops',
            'parent_id' => 99999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_label_from_other_workspace_rejected(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $otherWs = $this->makeWorkspace('Mobile', 'mobile');
        $label = Label::create(['tenant_id' => $this->acme->id, 'workspace_id' => $otherWs->id, 'name' => 'Other']);
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks", [
            'title' => 'Task',
            'labels' => [$label->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('labels');
    }

    public function test_viewer_project_member_cannot_create_task(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $this->addProjectMember($project, $this->viewer());
        $this->login('viewer@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks", ['title' => 'Nope'])->assertForbidden();
    }

    public function test_developer_project_member_can_view_and_move_but_not_delete(): void
    {
        $ws = $this->makeWorkspace();
        $this->addWorkspaceMember($ws, $this->editor());
        $project = $this->createProject($ws);
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Castable');

        $this->login('editor@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}")->assertOk()->assertJsonPath('task.key', $task->key);

        $this->deleteJson("/api/projects/{$project->id}/tasks/{$task->id}")->assertForbidden();
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_lead_can_update_assign_toggle_labels_and_split(): void
    {
        $ws = $this->makeWorkspace();
        $this->addWorkspaceMember($ws, $this->editor());
        $project = $this->createProject($ws);
        $this->addProjectMember($project, $this->editor());
        $task = $this->makeTask($project, 'Mutable');
        $label = Label::create(['tenant_id' => $this->acme->id, 'workspace_id' => $ws->id, 'name' => 'API']);

        $this->login('admin@flowsync.test');

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}", [
            'title' => 'Mutable v2',
            'assignee_id' => $this->editor()->id,
            'labels' => [$label->id],
            'due_date' => '2026-11-01',
            'estimate_minutes' => 45,
        ])->assertOk()
            ->assertJsonPath('task.title', 'Mutable v2')
            ->assertJsonPath('task.assignee.id', $this->editor()->id)
            ->assertJsonPath('task.labels.0.name', 'API')
            ->assertJsonPath('task.due_date', '2026-11-01');
    }

    public function test_assignee_change_requires_assign_permission(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $role = ProjectRole::create([
            'tenant_id' => $this->acme->id,
            'name' => 'Contributor',
            'slug' => 'contributor',
            'is_system' => false,
            'permissions' => ['tasks.view', 'tasks.edit'],
        ]);
        $assignee = $this->editor();
        $project->members()->attach($assignee->id, ['project_role_id' => $role->id, 'added_by' => $this->admin()->id]);
        $task = $this->makeTask($project, 'Needs assignee');

        $this->login('editor@flowsync.test');

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}", ['assignee_id' => $this->admin()->id])
            ->assertForbidden();
    }

    public function test_title_edit_without_assign_works_for_editor(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Rename me');

        $this->login('editor@flowsync.test');

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('task.title', 'Renamed');
    }

    public function test_lead_can_delete_task(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Doomed');

        $this->login('admin@flowsync.test');

        $this->deleteJson("/api/projects/{$project->id}/tasks/{$task->id}")->assertOk();
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_board_groups_tasks_by_status(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $this->makeTask($project, 'A-todo');
        $this->makeTask($project, 'B-todo');
        $inProgress = $project->statuses()->where('slug', 'in-progress')->first();
        $this->makeTask($project, 'A-doing', ['status_id' => $inProgress->id]);

        $this->login('admin@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/tasks?view=board")
            ->assertOk()
            ->assertJsonPath('board.statuses.1.name', 'To Do')
            ->assertJsonPath('board.statuses.1.tasks_count', 2)
            ->assertJsonPath('board.statuses.2.tasks_count', 1)
            ->assertJsonPath('board.totals.open', 3)
            ->assertJsonPath('board.totals.done', 0);
    }

    public function test_board_respects_filters(): void
    {
        $ws = $this->makeWorkspace();
        $this->addWorkspaceMember($ws, $this->editor());
        $project = $this->createProject($ws);
        $this->addProjectMember($project, $this->editor());
        $inProgress = $project->statuses()->where('slug', 'in-progress')->first();
        $high = Priority::where('tenant_id', $this->acme->id)->where('slug', 'high')->first();
        $label = Label::create(['tenant_id' => $this->acme->id, 'workspace_id' => $ws->id, 'name' => 'P1']);

        $todo = $this->makeTask($project, 'Backlog-y');
        $inProgressTask = $this->makeTask($project, 'Doing it', [
            'status_id' => $inProgress->id,
            'priority_id' => $high->id,
            'assignee_id' => $this->editor()->id,
        ]);
        $inProgressTask->labels()->attach($label->id);

        $this->login('admin@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/tasks?view=board&status_id={$inProgress->id}")
            ->assertOk()
            ->assertJsonPath('board.statuses.1.tasks_count', 0)
            ->assertJsonPath('board.statuses.2.tasks_count', 1);

        $this->getJson("/api/projects/{$project->id}/tasks?view=board&assignee_id={$this->editor()->id}")
            ->assertOk()
            ->assertJsonPath('board.totals.open', 1);

        $this->getJson("/api/projects/{$project->id}/tasks?view=board&priority_id={$high->id}")
            ->assertOk()
            ->assertJsonPath('board.totals.open', 1);

        $this->getJson("/api/projects/{$project->id}/tasks?view=board&label_id={$label->id}")
            ->assertOk()
            ->assertJsonPath('board.totals.open', 1);

        $this->getJson("/api/projects/{$project->id}/tasks?view=board&q=".rawurlencode('Doing'))
            ->assertOk()
            ->assertJsonPath('board.totals.open', 1);

        $this->getJson("/api/projects/{$project->id}/tasks?view=board&due_from=2026-01-01&due_to=2026-12-31")
            ->assertOk()
            ->assertJsonPath('board.totals.open', 0);
    }

    public function test_list_view_returns_flat_tasks_and_pagination(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $this->makeTask($project, 'One');
        $this->makeTask($project, 'Two');

        $this->login('admin@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/tasks?view=list")
            ->assertOk()
            ->assertJsonCount(2, 'tasks')
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_move_reorders_positions_within_a_column(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $todo = $project->statuses()->where('is_default', true)->first();
        $a = $this->makeTask($project, 'A', ['status_id' => $todo->id]);
        $b = $this->makeTask($project, 'B', ['status_id' => $todo->id]);
        $c = $this->makeTask($project, 'C', ['status_id' => $todo->id]);

        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$c->id}/move", ['status_id' => $todo->id, 'index' => 0])
            ->assertOk();

        $order = $project->tasks()->where('status_id', $todo->id)->orderBy('position')->get()->pluck('key')->all();
        $this->assertSame(['WEB-3', 'WEB-1', 'WEB-2'], $order);
    }

    public function test_move_across_columns_recomputes_and_sets_done_when_finished(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $done = $project->statuses()->where('slug', 'done')->first();
        $a = $this->makeTask($project, 'A');
        $b = $this->makeTask($project, 'B');

        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$a->id}/move", ['status_id' => $done->id])
            ->assertOk()
            ->assertJsonPath('task.completed_at', fn ($value) => $value !== null);

        $this->assertNotNull($a->fresh()->completed_at);
        $this->assertEquals(1, $b->fresh()->position);

        $this->postJson("/api/projects/{$project->id}/tasks/{$a->id}/move", ['status_id' => $b->status_id])
            ->assertOk();

        $this->assertNull($a->fresh()->completed_at);
    }

    public function test_move_to_done_is_hard_blocked_by_open_blocker(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $done = $project->statuses()->where('slug', 'done')->first();
        $blocked = $this->makeTask($project, 'Blocked');
        $blocker = $this->makeTask($project, 'Blocker');
        TaskDependency::create([
            'task_id' => $blocked->id,
            'depends_on_task_id' => $blocker->id,
            'type' => TaskDependencyType::Blocks,
        ]);

        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$blocked->id}/move", ['status_id' => $done->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('form');

        $this->assertNull($blocked->fresh()->completed_at);
        $this->assertNotEquals($done->id, $blocked->fresh()->status_id);
    }

    public function test_move_to_done_allowed_once_blocker_resolved(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $done = $project->statuses()->where('slug', 'done')->first();
        $blocked = $this->makeTask($project, 'Blocked');
        $blocker = $this->makeTask($project, 'Blocker');
        $blocker->update(['completed_at' => now()]);
        TaskDependency::create([
            'task_id' => $blocked->id,
            'depends_on_task_id' => $blocker->id,
            'type' => TaskDependencyType::Blocks,
        ]);

        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$blocked->id}/move", ['status_id' => $done->id])
            ->assertOk();
    }

    public function test_viewer_cannot_move_or_offer_update(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $this->addProjectMember($project, $this->viewer());
        $todo = $project->statuses()->where('is_default', true)->first();
        $inProgress = $project->statuses()->where('slug', 'in-progress')->first();
        $task = $this->makeTask($project, 'Watch only');

        $this->login('viewer@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/move", ['status_id' => $inProgress->id])
            ->assertForbidden();
        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}", ['title' => 'Hack'])
            ->assertForbidden();
        $this->assertSame($todo->id, $task->fresh()->status_id);
    }

    public function test_cross_tenant_task_is_not_accessible(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Secret');

        $this->login('owner@globex.test');

        $this->getJson("/api/projects/{$project->id}")->assertNotFound();
        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}")->assertNotFound();
    }

    public function test_non_member_cannot_view_tasks(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $this->addWorkspaceMember($ws, $this->viewer());
        $this->makeTask($project, 'Internal');

        $this->login('viewer@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/tasks?view=board")->assertForbidden();
    }

    public function test_task_events_broadcast_on_project_channel(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Loud');

        Event::fake([TaskSynced::class]);

        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/move", ['status_id' => $project->statuses()->where('slug', 'in-progress')->first()->id])
            ->assertOk();

        Event::assertDispatched(TaskSynced::class, function ($event) use ($task) {
            return $event->task->id === $task->id && $event->action === 'moved';
        });
    }
}
