<?php

namespace Tests\Feature;

use App\Listeners\SwitchesTenantConnectionForQueuedJobs;
use App\Models\Activity;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * F0.3 / F0.4 — task activities carry the request correlation id and, in an
 * impersonated session, the super admin behind the change; queued jobs keep the
 * correlation id of the request that dispatched them.
 */
class AuditTraceTest extends TestCase
{
    use IsolatesDatabase;

    private function makeTask(): void
    {
        $workspace = $this->postJson('/api/workspaces', ['name' => 'Trace WS'])->assertCreated()->json('workspace.id');
        $project = $this->postJson("/api/workspaces/{$workspace}/projects", ['name' => 'Trace Project'])->assertCreated()->json('project.id');
        $this->postJson("/api/projects/{$project}/tasks", ['title' => 'Trace me'])->assertCreated();
    }

    public function test_an_activity_carries_the_request_id_and_no_impersonator_for_a_normal_user(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');

        $this->makeTask();

        $activity = Activity::query()->where('action', 'task.created')->latest('id')->firstOrFail();
        $this->assertNotEmpty($activity->request_id);
        $this->assertNull($activity->impersonator_id);
    }

    public function test_an_activity_written_while_impersonating_names_the_super_admin(): void
    {
        $this->connectTenant('acme');
        $targetId = (int) User::where('email', 'admin@flowsync.test')->value('id');

        $this->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->postJson('/api/impersonate', [
            'user_id' => $targetId,
            'tenant_id' => $this->acme()->id,
            'reason' => 'Ticket #1 — reproducing a task bug',
            'mode' => 'write',
        ])->assertOk();

        $this->makeTask();

        $superAdminId = (int) SystemUser::query()->where('email', 'superadmin@flowsync.test')->value('id');
        $activity = Activity::query()->where('action', 'task.created')->latest('id')->firstOrFail();

        $this->assertSame($targetId, (int) $activity->actor_user_id);
        $this->assertSame($superAdminId, (int) $activity->impersonator_id);
    }

    public function test_a_worker_adopts_the_dispatching_requests_id_for_the_job_and_releases_it(): void
    {
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['request_id' => 'req-abc12345']);
        $listener = app(SwitchesTenantConnectionForQueuedJobs::class);

        $listener->handleProcessing(new JobProcessing('database', $job));
        $this->assertSame('req-abc12345', RequestId::current());

        $listener->handleFinished();
        $this->assertNotSame('req-abc12345', RequestId::current());
    }

    public function test_a_malformed_request_id_in_a_payload_is_ignored(): void
    {
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['request_id' => "bad id\n<script>"]);

        app(SwitchesTenantConnectionForQueuedJobs::class)->handleProcessing(new JobProcessing('database', $job));

        $this->assertNotSame("bad id\n<script>", RequestId::current());
    }

    protected function tearDown(): void
    {
        RequestId::useForJob(null);
        parent::tearDown();
    }
}
