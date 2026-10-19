<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F11.1 — the platform queue console: depth, failed jobs, retry and discard. */
class SystemQueueTest extends TestCase
{
    use IsolatesDatabase;

    private function central()
    {
        return DB::connection(config('tenancy.system.connection'));
    }

    private function failedJob(array $payload = []): int
    {
        return $this->central()->table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode($payload + ['displayName' => 'App\\Jobs\\ProvisionTenantJob', 'tenant_id' => 7, 'request_id' => 'req-12345678']),
            'exception' => "RuntimeException: boom\n#0 /secret/path/Job.php(12)",
            'failed_at' => now(),
        ]);
    }

    private function superAdmin(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])->assertOk();
    }

    public function test_the_console_reports_depth_and_failed_jobs_without_leaking_traces(): void
    {
        $this->central()->table('jobs')->insert([
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time() - 120],
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => time(), 'available_at' => time(), 'created_at' => time() - 30],
        ]);
        $this->failedJob();

        $this->superAdmin();

        $data = $this->getJson('/api/system/queue')->assertOk()->json();
        $this->assertSame(1, $data['queue']['pending']);
        $this->assertSame(1, $data['queue']['reserved']);
        $this->assertSame(1, $data['queue']['failed']);
        $this->assertGreaterThanOrEqual(119, $data['queue']['oldest_age_seconds']);
        $this->assertSame('App\\Jobs\\ProvisionTenantJob', $data['failed_jobs'][0]['job']);
        $this->assertSame('RuntimeException: boom', $data['failed_jobs'][0]['error']);
        $this->assertStringNotContainsString('/secret/path', json_encode($data));
    }

    public function test_retry_requeues_the_original_payload_and_removes_the_failure(): void
    {
        // A real database queue on the central connection, so the pushed row can be inspected.
        config(['queue.connections.database.connection' => config('tenancy.system.connection')]);
        $id = $this->failedJob();
        $this->superAdmin();

        $this->postJson("/api/system/queue/failed/{$id}/retry")->assertOk();

        $queued = $this->central()->table('jobs')->get();
        $this->assertCount(1, $queued);
        $this->assertStringContainsString('ProvisionTenantJob', $queued[0]->payload);
        $this->assertSame(0, $this->central()->table('failed_jobs')->count());
        $this->postJson("/api/system/queue/failed/{$id}/retry")->assertNotFound();
    }

    public function test_a_failed_job_can_be_discarded(): void
    {
        $id = $this->failedJob();
        $this->superAdmin();

        $this->deleteJson("/api/system/queue/failed/{$id}")->assertOk();
        $this->assertSame(0, $this->central()->table('failed_jobs')->count());
        $this->deleteJson("/api/system/queue/failed/{$id}")->assertNotFound();
    }

    public function test_tenant_users_cannot_reach_the_console(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();

        $this->getJson('/api/system/queue')->assertForbidden();
    }
}
