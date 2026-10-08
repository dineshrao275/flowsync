<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P1.8 — the HRMS shared tables.
 *
 * The isolation trait already migrates every tenant, so this asserts the
 * migration's own contract: the tables exist, the ledgers are append-only, the
 * settings row is seeded once, and re-running is a no-op (the repair path
 * `tenants:provision` depends on).
 */
class HrmsSharedTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant/2026_09_27_000014_create_hrms_shared_tables.php');
    }

    public function test_the_shared_tables_exist_on_a_fresh_tenant(): void
    {
        foreach (['approvals', 'approval_steps', 'hrms_audit_logs', 'hrms_data_access_logs', 'hrms_settings'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_the_audit_ledger_is_append_only(): void
    {
        // No updated_at: a row is never rewritten, so the table stays a ledger.
        $this->assertTrue(Schema::hasColumn('hrms_audit_logs', 'created_at'));
        $this->assertFalse(
            Schema::hasColumn('hrms_audit_logs', 'updated_at'),
            'hrms_audit_logs must not have updated_at',
        );

        $this->assertTrue(Schema::hasColumn('hrms_data_access_logs', 'created_at'));
        $this->assertFalse(Schema::hasColumn('hrms_data_access_logs', 'updated_at'));
    }

    public function test_approvals_are_soft_deletable_and_indexed(): void
    {
        $this->assertTrue(Schema::hasColumn('approvals', 'deleted_at'));

        $indexed = collect(DB::select('PRAGMA index_list(approvals)'))
            ->pluck('name')
            ->all();

        $this->assertNotEmpty($indexed);
    }

    public function test_the_settings_row_is_seeded_with_the_config_defaults(): void
    {
        $row = DB::table('hrms_settings')->find(1);

        $this->assertNotNull($row);
        $this->assertSame(1, $row->id);
        $this->assertSame(1, $row->week_start);
        $this->assertSame('UTC', $row->timezone);
        $this->assertSame('USD', $row->currency);
        $this->assertSame(1, $row->fiscal_year_start_month);
        $this->assertTrue((bool) $row->mask_sensitive);
    }

    public function test_json_settings_sections_round_trip(): void
    {
        $attendance = json_decode(DB::table('hrms_settings')->value('attendance'), true);

        $this->assertSame(480, $attendance['full_day_minutes']);
        $this->assertSame(15, $attendance['rounding_minutes']);
        $this->assertFalse($attendance['auto_derive_from_work_logs']);
    }

    public function test_there_is_exactly_one_settings_row(): void
    {
        $this->assertSame(1, DB::table('hrms_settings')->count());
    }

    public function test_re_running_the_migration_is_idempotent(): void
    {
        $migration = $this->migration();

        $before = DB::table('hrms_settings')->find(1);

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('hrms_settings')->count());
        $this->assertSame($before->week_start, DB::table('hrms_settings')->value('week_start'));
        $this->assertSame($before->country, DB::table('hrms_settings')->value('country'));
    }

    public function test_a_tenant_customised_before_the_rerun_keeps_its_values(): void
    {
        // `tenants:provision` repairs a database that failed partway by
        // re-running migrations, so the backfill must not stomp a tenant's
        // own settings.
        DB::table('hrms_settings')->where('id', 1)->update([
            'currency' => 'INR',
            'week_start' => 7,
        ]);

        $this->migration()->up();

        $row = DB::table('hrms_settings')->find(1);

        $this->assertSame('INR', $row->currency);
        $this->assertSame(7, $row->week_start);
    }

    public function test_approval_steps_cascade_with_their_approval(): void
    {
        $approvalId = DB::table('approvals')->insertGetId([
            'approvable_type' => 'App\\Models\\Hrms\\Leave\\LeaveRequest',
            'approvable_id' => 1,
            'subject' => 'Annual leave — Jane Doe',
            'status' => 'pending',
            'current_step' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('approval_steps')->insert([
            'approval_id' => $approvalId,
            'step_order' => 1,
            'approver_type' => 'manager',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('approval_steps')->where('approval_id', $approvalId)->count());

        DB::table('approvals')->where('id', $approvalId)->delete();

        $this->assertSame(0, DB::table('approval_steps')->where('approval_id', $approvalId)->count());
    }

    public function test_the_ledger_accepts_a_row_and_keeps_no_updated_at(): void
    {
        DB::table('hrms_audit_logs')->insert([
            'actor_user_id' => null,
            'subject_type' => 'App\\Models\\Hrms\\Employee\\Employee',
            'subject_id' => 1,
            'action' => 'employee.created',
            'data' => json_encode(['before' => null, 'after' => ['code' => 'EMP-1']]),
            'created_at' => now(),
        ]);

        $row = DB::table('hrms_audit_logs')->first();

        $this->assertSame('employee.created', $row->action);
        $this->assertNotNull($row->created_at);
        $this->assertArrayNotHasKey('updated_at', (array) $row);
    }

    public function test_data_access_logs_record_reads(): void
    {
        DB::table('hrms_data_access_logs')->insert([
            'model' => 'Payslip',
            'record_id' => 7,
            'action' => 'view',
            'fields' => json_encode(['gross', 'net']),
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('hrms_data_access_logs', [
            'model' => 'Payslip',
            'record_id' => 7,
            'action' => 'view',
        ]);
    }
}
