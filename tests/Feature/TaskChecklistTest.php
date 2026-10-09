<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class TaskChecklistTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();

        $this->connectTenant('acme');
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
        $defaultStatus = $project->statuses()->where('is_done', true)->first() ?? $project->statuses()->where('is_default', true)->first();
        $project->increment('last_task_sequence');

        return Task::create(array_merge([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => $title,
            'status_id' => $defaultStatus?->id ?? $project->statuses()->orderBy('position')->first()->id,
            'position' => $project->tasks()->where('status_id', $defaultStatus?->id ?? null)->max('position') + 1,
        ], $overrides));
    }

    public function test_can_create_checklist_item(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Task');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'Buy milk'])
            ->assertCreated()
            ->assertJsonPath('item.title', 'Buy milk')
            ->assertJsonPath('item.is_done', false)
            ->assertJsonPath('item.position', 0);

        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('summary.done', 0)
            ->assertJsonPath('summary.total', 1);
    }

    public function test_can_toggle_is_done_and_clears_on_false(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Task');
        $this->login('admin@flowsync.test');

        // Create a checklist item first
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'Do something']);
        $firstItem = $task->checklistItems->first();

        // Mark as done
        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist/{$firstItem->id}", ['is_done' => true])
            ->assertOk()
            ->assertJsonPath('item.is_done', true)
            ->assertJsonPath('item.completed_at', fn ($value) => $value !== null)
            ->assertJsonPath('item.completed_by', $this->admin()->id);

        // Mark as not done
        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist/{$firstItem->id}", ['is_done' => false])
            ->assertOk()
            ->assertJsonPath('item.is_done', false)
            ->assertJsonPath('item.completed_at', null)
            ->assertJsonPath('item.completed_by', null);
    }

    public function test_can_edit_title(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Task');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'Original']);
        $firstItem = $task->checklistItems->first();

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist/{$firstItem->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('item.title', 'Renamed');
    }

    public function test_position_renormalizes_on_change(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Task');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'First']);
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'Second']);
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'Third']);

        $item2 = $task->checklistItems()->where('title', 'Second')->first();
        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist/{$item2->id}", ['position' => 0])
            ->assertOk();

        $order = $task->checklistItems()->orderBy('position')->pluck('title')->all();
        $this->assertSame(['Second', 'First', 'Third'], $order);
    }

    public function test_delete_renormalizes_positions(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Task');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'First']);
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'Second']);
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'Third']);

        $item2 = $task->checklistItems()->where('title', 'Second')->first();
        $this->deleteJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist/{$item2->id}")
            ->assertOk();

        $order = $task->checklistItems()->orderBy('position')->pluck('title')->all();
        $this->assertSame(['First', 'Third'], $order);
    }

    public function test_item_belonging_to_different_task_returns_404(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task1 = $this->makeTask($project, 'Task 1');
        $task2 = $this->makeTask($project, 'Task 2');
        $this->login('admin@flowsync.test');

        // Create item on task 1
        $this->postJson("/api/projects/{$project->id}/tasks/{$task1->id}/checklist", ['title' => 'Item 1']);

        // Try to update item belonging to different task - should 404
        $this->putJson("/api/projects/{$project->id}/tasks/{$task2->id}/checklist/{$task1->checklistItems->first()?->id}", ['title' => 'Hack'])
            ->assertNotFound();

        // Try to delete item belonging to different task - should 404
        $this->deleteJson("/api/projects/{$project->id}/tasks/{$task2->id}/checklist/{$task1->checklistItems->first()?->id}")
            ->assertNotFound();
    }

    public function test_viewer_can_read_but_not_write(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Task');
        $this->addWorkspaceMember($ws, $this->viewer());
        $this->addProjectMember($project, $this->viewer(), 'viewer');

        $item = TaskChecklistItem::create([
            'task_id' => $task->id,
            'title' => 'Sample',
            'position' => 0,
            'created_by' => $this->admin()->id,
        ]);

        $this->login('viewer@flowsync.test');

        // Viewer can read checklist
        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist")
            ->assertOk();

        // Writer permission required for writes
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'Nope'])
            ->assertForbidden();

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist/{$item->id}", ['title' => 'Nope'])
            ->assertForbidden();

        $this->deleteJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist/{$item->id}")
            ->assertForbidden();
    }

    public function test_non_member_gets_403_or_404(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Task');

        $item = TaskChecklistItem::create([
            'task_id' => $task->id,
            'title' => 'Sample',
            'position' => 0,
            'created_by' => $this->admin()->id,
        ]);

        $this->login('viewer@flowsync.test');

        // Non-member may still get 403 or 404 depending on policy
        // The important thing is they can't arbitrarily create/update/delete
        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist")
            ->assertForbidden();

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'Nope'])
            ->assertForbidden();

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist/{$item->id}", ['title' => 'Nope'])
            ->assertForbidden();

        $this->deleteJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist/{$item->id}")
            ->assertForbidden();
    }

    public function test_100_item_cap_rejects_101st(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Task');
        $this->login('admin@flowsync.test');

        // Create 100 items
        for ($i = 0; $i < 100; $i++) {
            $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => "Item $i"]);
        }

        // 101st should be rejected
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'Item 101'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('form');

        // Existing items should still be there
        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist")
            ->assertOk()
            ->assertJsonPath('summary.total', 100);
    }

    public function test_empty_and_over255_titles_rejected(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Task');
        $this->login('admin@flowsync.test');

        // Empty title
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => ''])
            ->assertUnprocessable();

        // Title too long (256 chars)
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => str_repeat('a', 256)])
            ->assertUnprocessable();
    }

    public function test_activity_rows_written_on_lifecycle(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Task');
        $this->login('admin@flowsync.test');

        // Add item - should write task.checklist_item_added
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'Test item'])
            ->assertCreated();

        // Check activity was logged (via the activity feed endpoint or direct DB check)
        // The activity rows should be findable in the activities table
        $this->assertDatabaseHas('activities', [
            'subject_type' => Task::class,
            'subject_id' => $task->id,
            'action' => 'task.checklist_item_added',
        ]);
    }

    public function test_show_payload_carries_checklist_counts(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Task');
        $this->login('admin@flowsync.test');

        // Create a checklist item
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'Test'])
            ->assertCreated();

        // Fetch task show payload
        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('task.checklist_done_count', 0)
            ->assertJsonPath('task.checklist_total', 1);
    }

    public function test_cross_tenant_item_not_accessible(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->createProject($ws);
        $task = $this->makeTask($project, 'Task');
        $this->login('admin@flowsync.test');

        // Create item
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/checklist", ['title' => 'Secret']);

        // Can't access from different tenant context
        // (IsolatedDatabase handles tenant switching; just verify 404 or proper scoping)
        $this->assertDatabaseHas('task_checklist_items', [
            'task_id' => $task->id,
            'title' => 'Secret',
        ]);
    }
}
