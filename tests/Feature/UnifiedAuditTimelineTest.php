<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F2.7 — one timeline over activities and the HR ledger, admin-only, filterable, exportable. */
class UnifiedAuditTimelineTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    public function test_the_timeline_merges_both_ledgers_newest_first_and_filters(): void
    {
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();

        Activity::create(['actor_user_id' => $admin->id, 'subject_type' => 'App\\Models\\Task', 'subject_id' => 1, 'action' => 'task.created', 'created_at' => now()->subMinutes(5)]);
        DB::table('hrms_audit_logs')->insert(['actor_user_id' => $admin->id, 'subject_type' => 'App\\Models\\Hrms\\Employee\\Employee', 'subject_id' => 7, 'action' => 'employee.updated', 'created_at' => now()]);

        $all = $this->getJson('/api/audit/timeline')->assertOk()->json('entries');
        $sources = array_column($all, 'source');
        $this->assertContains('activity', $sources);
        $this->assertContains('hrms', $sources);
        $this->assertSame('employee.updated', $all[0]['action']);

        $this->getJson('/api/audit/timeline?source=hrms')->assertOk()->assertJsonCount(1, 'entries');
        $this->getJson('/api/audit/timeline?action=task.cre')->assertOk()->assertJsonPath('entries.0.action', 'task.created');
        $this->get('/api/audit/timeline/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_only_tenant_managers_can_read_it(): void
    {
        $this->login('viewer@flowsync.test');
        $this->getJson('/api/audit/timeline')->assertForbidden();
    }
}
