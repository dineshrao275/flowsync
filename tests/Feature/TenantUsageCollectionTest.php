<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\UsageMetric;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class TenantUsageCollectionTest extends TestCase
{
    use IsolatesDatabase;

    public function test_collect_usage_aggregates_metrics_into_system_db(): void
    {
        $acme = Tenant::where('slug', 'acme')->firstOrFail();
        $admin = $this->dbm->using($acme, fn () => User::where('email', 'admin@flowsync.test')->firstOrFail());

        // Create some sample data in Acme
        $this->dbm->using($acme, function () use ($admin) {
            $workspace = Workspace::create([
                'name' => 'Usage Test Workspace',
                'slug' => 'usage-test-ws',
                'created_by' => $admin->id,
            ]);

            $project = Project::create([
                'workspace_id' => $workspace->id,
                'name' => 'Usage Project',
                'key' => 'UP',
            ]);

            $task = Task::create([
                'project_id' => $project->id,
                'workspace_id' => $workspace->id,
                'created_by' => $admin->id,
                'title' => 'Usage Task 1',
                'key' => 'UP-1',
                'sequence' => 1,
                'position' => 1,
            ]);

            Attachment::create([
                'task_id' => $task->id,
                'user_id' => $admin->id,
                'stored_name' => 'test.png',
                'original_name' => 'test.png',
                'mime' => 'image/png',
                'size' => 1024,
                'disk' => 'local',
                'path' => 'tasks/UP/UP-1/test.png',
            ]);
        });

        $this->artisan('tenants:collect-usage', ['--tenant' => $acme->id])
            ->assertSuccessful();

        $today = Carbon::today()->toDateString();

        $this->assertDatabaseHas('usage_metrics', [
            'tenant_id' => $acme->id,
            'metric_key' => 'tasks',
            'period' => $today,
        ], $this->dbm->centralConnectionName());

        $this->assertDatabaseHas('usage_metrics', [
            'tenant_id' => $acme->id,
            'metric_key' => 'storage_bytes',
            'period' => $today,
        ], $this->dbm->centralConnectionName());

        $taskMetric = UsageMetric::where('tenant_id', $acme->id)
            ->where('metric_key', 'tasks')
            ->where('period', $today)
            ->firstOrFail();

        $this->assertGreaterThanOrEqual(1, $taskMetric->value);

        $storageMetric = UsageMetric::where('tenant_id', $acme->id)
            ->where('metric_key', 'storage_bytes')
            ->where('period', $today)
            ->firstOrFail();

        $this->assertGreaterThanOrEqual(1024, $storageMetric->value);
    }
}
