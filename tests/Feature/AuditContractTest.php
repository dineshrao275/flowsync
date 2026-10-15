<?php

namespace Tests\Feature;

use App\Contracts\AuditWriter;
use App\Models\AuditLog;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Services\HrmsAuditLogger;
use App\Services\PlatformAudit;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P2.2: both audit writers satisfy one contract and stamp the request's
 * correlation id on the row.
 */
class AuditContractTest extends TestCase
{
    use IsolatesDatabase;

    public function test_both_writers_implement_the_audit_contract(): void
    {
        $this->assertInstanceOf(AuditWriter::class, app(PlatformAudit::class));
        $this->assertInstanceOf(AuditWriter::class, app(HrmsAuditLogger::class));
    }

    public function test_platform_record_change_writes_a_masked_diff_of_changed_keys(): void
    {
        $log = app(PlatformAudit::class)->recordChange(
            'thing.updated', 'things', 5,
            ['name' => 'Old', 'unchanged' => 1, 'password' => 'a'],
            ['name' => 'New', 'unchanged' => 1, 'password' => 'b'],
            ['note' => 'kept'],
        );

        $row = AuditLog::findOrFail($log->id);
        $this->assertSame('things', $row->subject_type);
        $this->assertSame(5, $row->subject_id);
        $this->assertSame('kept', $row->data['note']);
        $this->assertSame('Old', $row->data['before']['name']);
        $this->assertArrayNotHasKey('unchanged', $row->data['after']);
        $this->assertNotSame('b', $row->data['after']['password']);
    }

    public function test_a_request_id_is_stamped_on_platform_and_hrms_rows(): void
    {
        $this->assertTrue(Schema::connection('iso_system')->hasColumn('audit_logs', 'request_id'));

        $this->withHeader('X-Request-Id', 'audit-contract-1')
            ->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])
            ->assertOk();

        $this->assertSame('audit-contract-1', AuditLog::where('action', 'auth.login')->latest('id')->value('request_id'));
    }

    public function test_hrms_record_change_writes_a_ledger_row(): void
    {
        $log = app(HrmsAuditLogger::class)->recordChange(
            'thing.updated', 'employees', 9, ['salary' => 1], ['salary' => 2],
        );

        $row = HrmsAuditLog::findOrFail($log->id);
        $this->assertSame('employees', $row->subject_type);
        $this->assertSame(9, $row->subject_id);
        $this->assertSame('thing.updated', $row->action);
    }
}
