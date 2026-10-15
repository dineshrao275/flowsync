<?php

namespace Tests\Feature;

use App\Events\TaskSynced;
use App\Listeners\SwitchesTenantConnectionForQueuedJobs;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class QueuedJobTenantContextTest extends TestCase
{
    use IsolatesDatabase;

    private function taskFor(User $user): Task
    {
        $workspace = Workspace::create([
            'created_by' => $user->id,
            'name' => 'Eng',
            'slug' => 'eng',
        ]);
        $workspace->members()->attach($user->id, ['role' => 'owner', 'added_by' => $user->id]);

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'lead_user_id' => $user->id,
            'name' => 'Backend',
            'key' => 'be',
        ]);

        $status = TaskStatus::create([
            'project_id' => $project->id,
            'name' => 'To Do',
            'slug' => 'to-do',
            'position' => 1,
            'category' => 'todo',
        ]);

        return Task::create([
            'project_id' => $project->id,
            'workspace_id' => $workspace->id,
            'status_id' => $status->id,
            'created_by' => $user->id,
            'title' => 'Queued broadcast',
            'key' => 'BE-1',
            'sequence' => 1,
            'position' => 1,
        ]);
    }

    public function test_queued_broadcast_jobs_carry_the_tenant_id_in_their_payload(): void
    {
        $user = User::where('email', 'admin@flowsync.test')->first();
        $this->taskFor($user);

        $tenantId = $this->app->make(TenantContext::class)->currentId();
        $this->assertSame(1, $tenantId);

        // The queue must run against the ISOLATED central database, never the
        // real `system` connection the dev/host environment points at.
        $central = $this->dbm->centralConnectionName();

        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => $central,
            'queue.connections.database.queue' => 'default',
        ]);

        Queue::connection('database')->push(fn () => null, '', 'default');

        $row = DB::connection($central)->table(config('queue.connections.database.table', 'jobs'))->latest('id')->first();
        $payload = json_decode($row->payload, true);

        $this->assertSame($tenantId, $payload['tenant_id']);
    }

    public function test_worker_switches_to_the_tenant_connection_before_a_job_runs(): void
    {
        $user = User::where('email', 'admin@flowsync.test')->first();
        $task = $this->taskFor($user);

        $tenancy = $this->app->make(TenantDatabaseManager::class);
        $central = $tenancy->centralConnectionName();

        // Emulate a fresh worker: central connection, no tenant context.
        $tenancy->connectSystem();
        $this->app->make(TenantContext::class)->setTenantId(null);
        $this->assertSame($central, DB::getDefaultConnection());

        $payload = [
            'uuid' => (string) str()->uuid(),
            'tenant_id' => 1,
            'displayName' => TaskSynced::class,
            'job' => 'Illuminate\\Queue\\CallQueuedListener@call',
            'data' => ['commandName' => TaskSynced::class],
        ];

        $job = new class($payload)
        {
            public function __construct(private array $payload) {}

            public function payload(): array
            {
                return $this->payload;
            }
        };

        $listener = $this->app->make(SwitchesTenantConnectionForQueuedJobs::class);
        $listener->handleProcessing(new JobProcessing('database', $job));

        $this->assertSame(config('tenancy.tenant.connection'), DB::getDefaultConnection());
        $this->assertSame(1, $this->app->make(TenantContext::class)->currentId());
        // The restored model is readable again on the tenant connection.
        $this->assertSame('Queued broadcast', Task::find($task->id)?->title);

        $listener->handleFinished();
        $this->assertSame($central, DB::getDefaultConnection());
        $this->assertNull($this->app->make(TenantContext::class)->currentId());
    }

    public function test_jobs_without_a_tenant_payload_leave_the_connection_alone(): void
    {
        $tenancy = $this->app->make(TenantDatabaseManager::class);
        $this->app->make(TenantContext::class)->setTenantId(1);
        $tenancy->connectSystem();
        $central = DB::getDefaultConnection();

        $job = new class
        {
            public function payload(): array
            {
                return ['uuid' => 'x', 'displayName' => 'App\\Jobs\\ProvisionTenantJob'];
            }
        };

        $listener = $this->app->make(SwitchesTenantConnectionForQueuedJobs::class);
        $listener->handleProcessing(new JobProcessing('database', $job));

        $this->assertSame($central, DB::getDefaultConnection());
    }
}
