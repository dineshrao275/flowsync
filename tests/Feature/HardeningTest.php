<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectService;
use App\Services\WorkspaceService;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();

        $this->connectTenant('acme');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->first();
    }

    private function viewer(): User
    {
        return User::where('email', 'viewer@flowsync.test')->first();
    }

    private function createProject(string $name = 'Hardening', string $key = 'HARD'): array
    {
        $workspace = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => $name.' Workspace',
            'slug' => strtolower($key.'-ws'),
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        $project = Project::create([
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

        return [$workspace, $project];
    }

    private function addProjectMember(Project $project, User $user): void
    {
        $role = ProjectRole::where('slug', 'viewer')->first();
        $project->members()->attach($user->id, ['project_role_id' => $role->id, 'added_by' => $this->admin()->id]);
    }

    private function makeTask(Project $project, string $title = 'Task'): Task
    {
        $project->increment('last_task_sequence');
        $status = $project->statuses()->where('is_default', true)->first();
        $priority = Priority::where('is_default', true)->first();

        return Task::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'reporter_id' => $this->admin()->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => $title,
            'status_id' => $status->id,
            'priority_id' => $priority->id,
            'position' => 1,
        ]);
    }

    // ---------------- N+1 regression ----------------

    public function test_workspace_list_computes_role_without_extra_queries(): void
    {
        foreach (['A', 'B', 'C'] as $i) {
            $workspace = Workspace::create(['name' => "WS $i", 'slug' => "ws-$i"]);
            $workspace->members()->attach($this->admin()->id, ['role' => 'owner']);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $admin = $this->admin();

        $workspaces = app(WorkspaceService::class)->listFor($admin);

        DB::flushQueryLog();

        foreach ($workspaces as $workspace) {
            $workspace->memberRole($admin);
        }

        $this->assertSame(0, count(DB::getQueryLog()));
    }

    public function test_project_list_computes_role_without_extra_queries(): void
    {
        foreach (['A', 'B', 'C'] as $i) {
            [$workspace, $project] = $this->createProject("P $i", "P$i");
            $workspace->members()->attach($this->viewer()->id, ['role' => 'member']);
            $this->addProjectMember($project, $this->viewer());
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $viewer = $this->viewer();

        $projects = app(ProjectService::class)->listAll($viewer);

        DB::flushQueryLog();

        foreach ($projects as $project) {
            $project->memberRole($viewer);
        }

        $queries = array_column(DB::getQueryLog(), 'query');
        $this->assertSame(0, count(array_filter($queries, fn ($q) => str_contains($q, '"project_members"'))));
    }

    // ---------------- Soft-delete sweep ----------------

    public function test_trashed_task_404s_and_is_excluded_from_all_globals(): void
    {
        [, $project] = $this->createProject();
        $this->addProjectMember($project, $this->viewer());
        $task = $this->makeTask($project);
        $task->delete();

        $this->assertNotNull($task->trashed());

        $this->login('viewer@flowsync.test');
        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}")->assertNotFound();
        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}", ['title' => 'Nope'])->assertNotFound();
        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/comments")->assertNotFound();

        $this->getJson('/api/search/tasks')->assertJsonPath('pagination.total', 0);
        $this->getJson('/api/dashboard')->assertJsonPath('counts.open', 0);
        $this->getJson('/api/reports/overview')->assertJsonPath('totals.total', 0);
    }

    public function test_trashed_subtask_is_hidden_from_parent_payload(): void
    {
        [, $project] = $this->createProject();
        $this->addProjectMember($project, $this->viewer());
        $parent = $this->makeTask($project, 'Parent');
        $sub = $this->makeTask($project, 'Subtle child');
        $sub->parent_id = $parent->id;
        $sub->save();
        $sub->delete();

        $parent->refresh()->loadMissing('subtasks');

        $this->assertCount(0, $parent->subtasks);

        $this->login('viewer@flowsync.test');
        $this->getJson("/api/projects/{$project->id}/tasks/{$parent->id}")
            ->assertOk()
            ->assertJsonPath('task.subtasks_count', 0);
    }

    public function test_deleted_comment_vanishes_and_cannot_be_mutated(): void
    {
        [, $project] = $this->createProject();
        $this->addProjectMember($project, $this->viewer());
        $task = $this->makeTask($project);

        $comment = Comment::create([
            'task_id' => $task->id,
            'user_id' => $this->viewer()->id,
            'comment' => 'Hello!',
        ]);

        $this->login('viewer@flowsync.test');
        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'comments');

        $this->deleteJson("/api/projects/{$project->id}/tasks/{$task->id}/comments/{$comment->id}")->assertOk();

        $this->assertNotNull($comment->fresh()->trashed());
        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/comments")
            ->assertOk()
            ->assertJsonCount(0, 'comments');
        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}/comments/{$comment->id}", ['comment' => 'Edited'])
            ->assertNotFound();
    }

    // ---------------- Super-admin impersonation matrix for global reads ----------------

    public function test_non_impersonating_super_admin_is_blocked_from_global_reads(): void
    {
        $this->createProject();
        $this->login('superadmin@flowsync.test');

        $this->getJson('/api/dashboard')->assertForbidden();
        $this->getJson('/api/reports/overview')->assertForbidden();
        $this->getJson('/api/search/tasks')->assertForbidden();
    }

    public function test_impersonating_super_admin_is_scoped_to_target_tenant_on_global_reads(): void
    {
        [, $project] = $this->createProject();
        $this->makeTask($project);
        $this->login('superadmin@flowsync.test');

        $target = $this->admin();
        $this->postJson('/api/impersonate', ['user_id' => $target->id])->assertOk();

        $this->getJson('/api/search/tasks')->assertOk()->assertJsonPath('pagination.total', 1);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('counts.open', 1);
        $this->getJson('/api/reports/overview')->assertOk()->assertJsonPath('totals.total', 1);
    }
}
