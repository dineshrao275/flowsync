<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use IsolatesDatabase;

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->firstOrFail();
    }

    private function makeProject(): Project
    {
        $this->connectTenant('acme');

        $workspace = Workspace::create([
            'created_by' => $this->admin()->id, 'name' => 'Reports', 'slug' => 'reports-'.uniqid(),
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $this->admin()->id,
            'lead_user_id' => $this->admin()->id,
            'name' => 'Reports',
            'key' => 'RPT'.random_int(10, 99),
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

    private function makeTask(Project $project, array $overrides = []): Task
    {
        $this->connectTenant('acme');
        $project->increment('last_task_sequence');

        return Task::create(array_merge([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => 'Reportable',
            'status_id' => $project->statuses()->where('is_default', true)->first()->id,
            'position' => 1,
        ], $overrides));
    }

    public function test_overview_reports_totals_and_distributions(): void
    {
        $this->loginAs('admin@flowsync.test');
        $project = $this->makeProject();
        $this->makeTask($project);
        $this->makeTask($project);

        $this->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('totals.total', 2)
            ->assertJsonPath('range', null)
            ->assertJsonStructure([
                'totals' => ['total', 'open', 'done', 'overdue'],
                'by_status', 'by_priority', 'by_assignee', 'by_project',
            ]);
    }

    public function test_overview_window_covers_created_or_completed_tasks(): void
    {
        $this->loginAs('admin@flowsync.test');
        $project = $this->makeProject();

        $ancient = $this->makeTask($project, ['title' => 'Ancient']);
        Task::query()->whereKey($ancient->id)->update(['created_at' => now()->subDays(90)]);

        $oldDone = $this->makeTask($project, ['title' => 'Old done']);
        Task::query()->whereKey($oldDone->id)->update([
            'created_at' => now()->subDays(90),
            'completed_at' => now()->subDays(3),
        ]);

        $this->makeTask($project, ['title' => 'Fresh']);

        $from = now()->subDays(6)->toDateString();
        $to = now()->toDateString();

        $response = $this->getJson("/api/reports/overview?from={$from}&to={$to}")->assertOk();

        // Fresh (created now) + old-done (completed 3d ago); ancient
        // (created 90d ago, still open) stays out.
        $this->assertSame(2, $response->json('totals.total'));
        $this->assertSame(1, $response->json('totals.done'));
        $this->assertSame($from, $response->json('range.from'));
    }

    public function test_overview_rejects_bad_windows(): void
    {
        $this->loginAs('admin@flowsync.test');

        $this->getJson('/api/reports/overview?from=2026-05-01&to=2026-04-01')->assertUnprocessable();
        $this->getJson('/api/reports/overview?from=2024-01-01&to=2026-12-31')->assertStatus(422);
    }
}
