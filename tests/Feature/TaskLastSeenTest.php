<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Opening a task records who saw it and when; the task payload lists the viewers, most recent first. */
class TaskLastSeenTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    public function test_opening_a_task_records_the_viewer_and_lists_them(): void
    {
        $this->loginAdmin();
        $workspace = $this->postJson('/api/workspaces', ['name' => 'Seen WS'])->assertCreated()->json('workspace.id');
        $project = $this->postJson("/api/workspaces/{$workspace}/projects", ['name' => 'Seen', 'key' => 'SEN'])->assertCreated()->json('project.id');
        $task = $this->postJson("/api/projects/{$project}/tasks", ['title' => 'Look at me'])->assertCreated()->json('task.id');

        $viewers = $this->getJson("/api/projects/{$project}/tasks/{$task}")->assertOk()->json('task.viewers');

        $this->assertCount(1, $viewers);
        $this->assertSame(User::where('email', 'admin@flowsync.test')->value('id'), $viewers[0]['id']);
        $this->assertNotEmpty($viewers[0]['last_seen_at']);

        // A second open refreshes the same row instead of adding another.
        $this->assertCount(1, $this->getJson("/api/projects/{$project}/tasks/{$task}")->json('task.viewers'));
    }
}
