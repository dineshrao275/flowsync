<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** The dashboard presented each task with up to six lazy loads; its query count must not grow with the list. */
class DashboardQueryCountTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private int $project;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->adminId = (int) User::where('email', 'admin@flowsync.test')->value('id');
        $workspace = $this->postJson('/api/workspaces', ['name' => 'Perf WS'])->assertCreated()->json('workspace.id');
        $this->project = $this->postJson("/api/workspaces/{$workspace}/projects", ['name' => 'Perf', 'key' => 'PRF'])->assertCreated()->json('project.id');
    }

    private function addTasks(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->postJson("/api/projects/{$this->project}/tasks", ['title' => "Task {$i}", 'assignee_id' => $this->adminId, 'due_date' => now()->addDays($i % 5)->toDateString()])->assertCreated();
        }
    }

    private function queriesFor(string $uri): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $this->getJson($uri)->assertOk();

        return $count;
    }

    public function test_query_count_does_not_grow_with_the_number_of_tasks_shown(): void
    {
        $this->addTasks(1);
        $few = $this->queriesFor('/api/dashboard');

        $this->addTasks(5);
        $response = $this->getJson('/api/dashboard')->assertOk();
        $many = $this->queriesFor('/api/dashboard');

        $this->assertGreaterThanOrEqual(5, count($response->json('my_open')), 'the list really grew');
        $this->assertSame($few, $many, 'dashboard queries must not depend on how many tasks are listed');
    }

    public function test_the_payload_still_carries_the_relations_the_board_reads(): void
    {
        $this->addTasks(1);

        $task = $this->getJson('/api/dashboard')->assertOk()->json('my_open.0');

        $this->assertArrayHasKey('issue_type', $task);
        $this->assertArrayHasKey('watchers', $task);
        $this->assertArrayHasKey('components', $task);
        $this->assertSame('admin@flowsync.test', $task['assignee']['email']);
        $this->assertNotEmpty($task['assignee']['roles']);
    }

    public function test_the_project_feed_merges_task_and_project_activity_newest_first_without_a_task_id_list(): void
    {
        $this->addTasks(3);

        $binds = [];
        DB::listen(function ($query) use (&$binds): void {
            $binds[] = count($query->bindings);
        });
        $feed = $this->getJson("/api/projects/{$this->project}/activities")->assertOk()->json('activities');

        $ids = array_column($feed, 'id');
        $sorted = $ids;
        rsort($sorted);
        $this->assertNotEmpty($feed);
        $this->assertSame($sorted, $ids, 'newest first');
        $this->assertLessThan(20, max($binds), 'no per-task bind list');
    }
}

