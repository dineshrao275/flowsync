<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Hrms\PerformanceService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P1.11 — completing a task through the edit form obeys the same rules as dragging it to Done. */
class TaskCompletionRulesTest extends TestCase
{
    use IsolatesDatabase;

    private function board(): array
    {
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $workspace = Workspace::create(['created_by' => $admin->id, 'name' => 'W', 'slug' => 'w']);
        $workspace->members()->attach($admin->id, ['role' => 'owner', 'added_by' => $admin->id]);
        $project = Project::create(['workspace_id' => $workspace->id, 'created_by' => $admin->id, 'lead_user_id' => $admin->id, 'name' => 'P', 'key' => 'PP']);
        $project->members()->attach($admin->id, ['project_role_id' => ProjectRole::where('slug', 'lead')->firstOrFail()->id, 'added_by' => $admin->id]);
        foreach (config('task_statuses.statuses') as $s) {
            TaskStatus::create([
                'project_id' => $project->id, 'name' => $s['name'], 'slug' => $s['slug'], 'category' => $s['category'],
                'position' => $s['position'], 'color' => $s['color'], 'is_done' => $s['is_done'], 'is_default' => $s['is_default'] ?? false,
            ]);
        }
        $todo = TaskStatus::where('project_id', $project->id)->where('is_done', false)->orderBy('position')->first();
        $done = TaskStatus::where('project_id', $project->id)->where('is_done', true)->first();
        $make = fn (int $n) => Task::create([
            'workspace_id' => $workspace->id, 'project_id' => $project->id, 'created_by' => $admin->id, 'reporter_user_id' => $admin->id,
            'status_id' => $todo->id, 'key' => "PP-{$n}", 'sequence' => $n, 'title' => "Task {$n}", 'position' => $n,
        ]);

        return [$project, $make(1), $make(2), $done, $todo];
    }

    private function login(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
    }

    public function test_the_edit_form_cannot_complete_a_task_that_still_has_open_blockers(): void
    {
        [$project, $task, $blocker, $done] = $this->board();
        TaskDependency::create(['task_id' => $task->id, 'depends_on_task_id' => $blocker->id, 'type' => 'blocks']);
        $this->login();

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}", ['status_id' => $done->id])
            ->assertUnprocessable()->assertJsonValidationErrors('form');
        $this->assertNull($task->fresh()->completed_at);

        // Finish the blocker, and the same edit goes through.
        $blocker->update(['status_id' => $done->id, 'completed_at' => now()]);
        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}", ['status_id' => $done->id])->assertOk();
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_completing_through_the_edit_form_refreshes_linked_goals_once(): void
    {
        [$project, $task, , $done] = $this->board();
        $this->login();

        $performance = $this->mock(PerformanceService::class);
        $performance->shouldReceive('refreshTaskGoals')->once();

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}", ['status_id' => $done->id])->assertOk();
        // Saving again while already done is not a completion.
        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}", ['status_id' => $done->id, 'title' => 'Renamed'])->assertOk();
    }
}
