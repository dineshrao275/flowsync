<?php

namespace Tests\Feature;

use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\NotificationService;
use Database\Seeders\ScaleDataSeeder;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class PerformanceFollowupsTest extends TestCase
{
    use IsolatesDatabase;

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
        $this->connectTenant('acme');

        return User::where('email', 'admin@flowsync.test')->first();
    }

    private function makeWorkspace(string $name = 'Perf WS'): Workspace
    {
        $ws = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
        ]);
        $ws->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        return $ws;
    }

    private function makeProject(Workspace $ws, string $name = 'Perf Project', string $key = 'PERF'): Project
    {
        $project = Project::create([
            'workspace_id' => $ws->id,
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
                'is_default' => $status['is_default'] ?? false,
                'is_done' => (bool) $status['is_done'],
            ]);
        }

        return $project;
    }

    public function test_board_column_limit_caps_tasks_while_reporting_total_and_has_more(): void
    {
        $this->login('admin@flowsync.test');
        $ws = $this->makeWorkspace('Board Limits WS');
        $project = $this->makeProject($ws, 'Board Project', 'BLP');

        $todoStatus = $project->statuses()->where('slug', 'to-do')->first();
        $priority = Priority::where('slug', 'medium')->first();

        // Create 10 tasks in To Do
        for ($i = 1; $i <= 10; $i++) {
            Task::create([
                'workspace_id' => $ws->id,
                'project_id' => $project->id,
                'status_id' => $todoStatus->id,
                'priority_id' => $priority->id,
                'created_by' => $this->admin()->id,
                'reporter_id' => $this->admin()->id,
                'key' => "BLP-{$i}",
                'sequence' => $i,
                'title' => "Task {$i}",
                'position' => $i,
            ]);
        }

        // 1. Without column_limit: returns all 10 tasks
        $resAll = $this->getJson("/api/projects/{$project->id}/tasks?view=board")->assertOk();
        $todoColumnAll = collect($resAll->json('board.statuses'))->firstWhere('slug', 'to-do');
        $this->assertCount(10, $todoColumnAll['tasks']);
        $this->assertSame(10, $todoColumnAll['tasks_count']);

        // 2. With column_limit=4: returns only 4 tasks, with has_more = true
        $resCapped = $this->getJson("/api/projects/{$project->id}/tasks?view=board&column_limit=4")->assertOk();
        $todoColumnCapped = collect($resCapped->json('board.statuses'))->firstWhere('slug', 'to-do');
        $this->assertCount(4, $todoColumnCapped['tasks']);
        $this->assertSame(10, $todoColumnCapped['tasks_count']);
        $this->assertTrue($todoColumnCapped['has_more']);
        $this->assertSame(4, $todoColumnCapped['column_limit']);
    }

    public function test_notifications_endpoint_supports_after_cursor_for_incremental_fetching(): void
    {
        $this->login('admin@flowsync.test');
        $admin = $this->admin();
        $service = app(NotificationService::class);

        $n1 = $service->notify($admin, 'test.event1', ['msg' => 'first']);
        $n2 = $service->notify($admin, 'test.event2', ['msg' => 'second']);
        $n3 = $service->notify($admin, 'test.event3', ['msg' => 'third']);

        // Fetch without cursor
        $res = $this->getJson('/api/notifications')->assertOk();
        $this->assertGreaterThanOrEqual(3, count($res->json('notifications')));

        // Fetch with cursor ?after=ID of n1
        $cursorRes = $this->getJson("/api/notifications?after={$n1->id}")->assertOk();
        $cursorNotifications = $cursorRes->json('notifications');

        $this->assertCount(2, $cursorNotifications);
        $ids = collect($cursorNotifications)->pluck('id')->all();
        $this->assertNotContains($n1->id, $ids);
        $this->assertContains($n2->id, $ids);
        $this->assertContains($n3->id, $ids);
        $this->assertSame($n3->id, $cursorRes->json('latest_id'));
    }

    public function test_global_search_caps_results_per_category(): void
    {
        $this->login('admin@flowsync.test');
        $ws = $this->makeWorkspace('Alpha Alpha Work');
        $project = $this->makeProject($ws, 'Alpha Alpha Project', 'AAP');
        $status = $project->statuses()->first();
        $priority = Priority::first();

        for ($i = 1; $i <= 5; $i++) {
            Task::create([
                'workspace_id' => $ws->id,
                'project_id' => $project->id,
                'status_id' => $status->id,
                'priority_id' => $priority->id,
                'created_by' => $this->admin()->id,
                'reporter_id' => $this->admin()->id,
                'key' => "AAP-{$i}",
                'sequence' => $i,
                'title' => "Alpha task {$i}",
                'position' => $i,
            ]);
        }

        // Default search
        $res = $this->getJson('/api/search/global?q=Alpha')->assertOk();
        $this->assertGreaterThanOrEqual(1, count($res->json('results.workspaces')));
        $this->assertGreaterThanOrEqual(1, count($res->json('results.tasks')));

        // Request with task_limit=2
        $cappedRes = $this->getJson('/api/search/global?q=Alpha&task_limit=2&workspace_limit=1')->assertOk();
        $this->assertLessThanOrEqual(2, count($cappedRes->json('results.tasks')));
        $this->assertLessThanOrEqual(1, count($cappedRes->json('results.workspaces')));
    }

    public function test_scale_seeder_backfills_activities_for_created_tasks(): void
    {
        $seeder = app(ScaleDataSeeder::class);
        $acme = $this->acme();

        $this->dbm->using($acme, function () use ($seeder) {
            $ws = $this->makeWorkspace('Scale Seed WS');
            $project = $this->makeProject($ws, 'Scale Project', 'SCP');
            $users = [$this->admin()];
            $statusConfig = collect(config('task_statuses.statuses'));
            $priorityIds = Priority::pluck('id');

            // Seed 6 tasks with related = true
            $reflection = new \ReflectionClass($seeder);
            $seedTasksMethod = $reflection->getMethod('seedTasks');
            $seedTasksMethod->setAccessible(true);
            $seedTasksMethod->invoke($seeder, $ws, $project, $users, $statusConfig, $priorityIds, 6, true);

            // Verify activities table has task audit entries
            $activityCount = DB::table('activities')->where('subject_type', 'App\\Models\\Task')->count();
            $this->assertGreaterThan(0, $activityCount);
        });
    }
}
