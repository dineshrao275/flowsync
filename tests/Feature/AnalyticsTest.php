<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use IsolatesDatabase;

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->firstOrFail();
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

    private function createProject(Workspace $workspace): Project
    {
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $this->admin()->id,
            'lead_user_id' => $this->admin()->id,
            'name' => 'Website',
            'key' => 'WEB',
        ]);

        $position = 0;
        foreach (config('task_statuses.statuses') as $status) {
            $position++;
            TaskStatus::create([
                'project_id' => $project->id,
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => $status['category'],
                'position' => $position,
                'color' => $status['color'],
                'is_default' => $status['slug'] === config('task_statuses.default', 'to-do'),
                'is_done' => in_array($status['category'], ['done'], true),
            ]);
        }

        $lead = ProjectRole::where('slug', 'lead')->firstOrFail();
        $project->members()->attach($this->admin()->id, ['project_role_id' => $lead->id, 'added_by' => $this->admin()->id]);

        return $project;
    }

    private function makeTask(Project $project, string $title, array $overrides = []): Task
    {
        $defaultStatus = $project->statuses()->where('is_default', true)->first();
        $project->increment('last_task_sequence');

        return Task::create(array_merge([
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

    private function makeLog(Task $task, int $minutes, ?Carbon $startedAt = null): WorkLog
    {
        $startedAt ??= Carbon::now();

        return WorkLog::create([
            'task_id' => $task->id,
            'user_id' => $this->admin()->id,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addMinutes($minutes),
            'duration_minutes' => $minutes,
            'description' => null,
        ]);
    }

    public function test_analytics_overview_reports_counts_series_and_progress(): void
    {
        $this->loginAs('admin@flowsync.test');

        $workspace = $this->makeWorkspace();
        $project = $this->createProject($workspace);
        $this->makeTask($project, 'Done task', ['completed_at' => now()]);
        $this->makeTask($project, 'Overdue', ['due_date' => now()->subDay()]);
        $this->makeTask($project, 'Due soon', ['due_date' => now()->addDays(2)]);
        $this->makeTask($project, 'Open');

        $doneTask = $project->tasks()->where('title', 'Done task')->firstOrFail();
        $overdueTask = $project->tasks()->where('title', 'Overdue')->firstOrFail();
        $this->makeLog($doneTask, 90);
        $this->makeLog($overdueTask, 120, Carbon::now()->subDay());

        $this->getJson('/api/analytics/overview')
            ->assertOk()
            ->assertJsonPath('counts.workspaces', 1)
            ->assertJsonPath('counts.projects', 1)
            ->assertJsonPath('counts.open', 3)
            ->assertJsonPath('counts.done', 1)
            ->assertJsonPath('counts.overdue', 1)
            ->assertJsonPath('counts.due_this_week', 1)
            ->assertJsonPath('counts.created_30d', 4)
            ->assertJsonFragment([
                'name' => 'Website',
                'open' => 3,
                'done' => 1,
                'total' => 4,
                'percent' => 25,
            ])
            ->assertJsonPath('work_logs.total_minutes', 210)
            ->assertJsonPath('work_logs.today_minutes', 90)
            ->assertJsonPath('work_logs.daily.13.minutes', 90)
            ->assertJsonPath('work_logs.daily.12.minutes', 120)
            ->assertJsonPath('tasks_created.daily.13.count', 4)
            ->assertJsonPath('top_contributors.0.minutes', 210)
            ->assertJsonPath('top_contributors.0.user.name', 'Admin User');
    }

    public function test_analytics_only_includes_visible_projects_for_non_managers(): void
    {
        $this->loginAs('admin@flowsync.test');

        $workspace = $this->makeWorkspace();
        $project = $this->createProject($workspace);
        $this->makeTask($project, 'Hidden task');

        // Editor is neither a workspace member nor a project member.
        $this->connectTenant('acme');
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $this->actingAs($editor)->withSession(['login.tenant_id' => $this->acme()->id]);

        $this->getJson('/api/analytics/overview')
            ->assertOk()
            ->assertJsonPath('counts.workspaces', 0)
            ->assertJsonPath('counts.projects', 0)
            ->assertJsonPath('counts.open', 0)
            ->assertJsonPath('projects_progress', []);
    }

    public function test_analytics_requires_authentication(): void
    {
        $this->getJson('/api/analytics/overview')->assertUnauthorized();
    }
}
