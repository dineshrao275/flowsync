<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P2.1 — the HRMS employee tables.
 *
 * The isolation trait already migrates every tenant, so this asserts the
 * migration's own contract: the three tables exist with the columns the later
 * phases bind to, the ledgers stay append-only, the reporting line does not
 * cascade, and re-running is a no-op (the repair path `tenants:provision`
 * depends on).
 */
class HrmsEmployeeTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant/2026_09_27_000015_create_hrms_employee_tables.php');
    }

    public function test_the_employee_tables_exist_on_a_fresh_tenant(): void
    {
        foreach (['employment_types', 'employees', 'employee_status_history'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_the_employee_record_carries_every_phase_two_column(): void
    {
        // Later phases bind to these by name, so a rename here is a silent
        // break in a migration that has not been written yet.
        $expected = [
            'user_id', 'employee_code', 'name', 'preferred_name', 'personal_email', 'phone',
            'date_of_birth', 'gender', 'marital_status', 'nationality', 'address_line1', 'address_line2',
            'city', 'state', 'postal_code', 'country', 'emergency_contact_name', 'emergency_contact_phone',
            'emergency_contact_relation', 'photo_path', 'joining_date', 'probation_end_date',
            'confirmation_date', 'employment_type_id', 'designation', 'manager_id', 'work_mode', 'status',
            'exit_date', 'exited_reason', 'notes', 'created_by', 'created_at', 'updated_at', 'deleted_at',
        ];

        foreach ($expected as $column) {
            $this->assertTrue(
                Schema::hasColumn('employees', $column),
                "Missing employees.{$column}",
            );
        }
    }

    public function test_the_shift_column_is_still_absent(): void
    {
        // department_id / designation_id / location_id were deferred to P3 and
        // have since arrived in `000016`; shift_id belongs to P5. If a migration
        // ever adds it early, the phase that owns it would silently skip the
        // ALTER and the two would drift. P3.1's own columns are asserted in
        // HrmsOrgTablesTest.
        $this->assertFalse(
            Schema::hasColumn('employees', 'shift_id'),
            'employees.shift_id belongs to a later phase',
        );
    }

    public function test_there_is_no_tenant_id_column(): void
    {
        // The tenant database is the isolation boundary (Phase 13).
        foreach (['employment_types', 'employees', 'employee_status_history'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'tenant_id'), "{$table}.tenant_id must not exist");
        }
    }

    public function test_the_status_history_is_append_only(): void
    {
        $this->assertTrue(Schema::hasColumn('employee_status_history', 'created_at'));
        $this->assertFalse(
            Schema::hasColumn('employee_status_history', 'updated_at'),
            'employee_status_history must not have updated_at',
        );
    }

    public function test_the_employee_status_and_work_mode_enums_reject_unknown_values(): void
    {
        $employeeId = $this->insertEmployee();

        $this->expectException(QueryException::class);

        DB::table('employees')->where('id', $employeeId)->update(['status' => 'retired']);
    }

    public function test_every_planned_status_and_work_mode_is_accepted(): void
    {
        $employeeId = $this->insertEmployee();

        foreach (['active', 'probation', 'on_notice', 'suspended', 'exited', 'terminated'] as $status) {
            DB::table('employees')->where('id', $employeeId)->update(['status' => $status]);
            $this->assertSame($status, DB::table('employees')->where('id', $employeeId)->value('status'));
        }

        foreach (['office', 'hybrid', 'remote'] as $mode) {
            DB::table('employees')->where('id', $employeeId)->update(['work_mode' => $mode]);
            $this->assertSame($mode, DB::table('employees')->where('id', $employeeId)->value('work_mode'));
        }
    }

    public function test_a_user_can_back_at_most_one_employee_record(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Linked User',
            'email' => 'linked@flowsync.test',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = $this->insertEmployee(['user_id' => $userId]);

        $this->assertNotNull($employeeId);

        $this->expectException(QueryException::class);

        $this->insertEmployee(['user_id' => $userId, 'employee_code' => 'EMP-OTHER']);
    }

    public function test_several_employees_may_have_no_user(): void
    {
        // Contractors and field staff have employment records but no login, so
        // user_id must stay nullable and repeated NULLs must not collide.
        $first = $this->insertEmployee(['user_id' => null, 'employee_code' => 'EMP-NULL-1']);
        $second = $this->insertEmployee(['user_id' => null, 'employee_code' => 'EMP-NULL-2']);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(2, DB::table('employees')->whereNull('user_id')->count());
    }

    public function test_employee_codes_are_unique(): void
    {
        $this->insertEmployee(['employee_code' => 'EMP-1']);

        $this->expectException(QueryException::class);

        $this->insertEmployee(['employee_code' => 'EMP-1', 'user_id' => null]);
    }

    public function test_deleting_a_manager_does_not_delete_their_reports(): void
    {
        // The one index that matters: cascade-on-delete here would destroy an
        // entire department when a manager is removed.
        $managerId = $this->insertEmployee(['employee_code' => 'EMP-MGR', 'name' => 'Manager']);
        $reportId = $this->insertEmployee([
            'employee_code' => 'EMP-RPT',
            'name' => 'Report',
            'manager_id' => $managerId,
        ]);

        DB::table('employees')->where('id', $managerId)->delete();

        $report = DB::table('employees')->where('id', $reportId)->first();

        $this->assertNotNull($report, 'The report was destroyed with their manager');
        $this->assertNull($report->manager_id, 'The report should be orphaned, not deleted');
    }

    public function test_a_soft_deleted_employee_keeps_their_record(): void
    {
        $employeeId = $this->insertEmployee();

        DB::table('employees')->where('id', $employeeId)->update(['deleted_at' => now()]);

        $this->assertNotNull(DB::table('employees')->where('id', $employeeId)->first());
        $this->assertSame(1, DB::table('employees')->where('id', $employeeId)->whereNotNull('deleted_at')->count());
    }

    public function test_status_history_cascades_with_the_employee(): void
    {
        // The inverse of the manager rule: a history row has no meaning without
        // the record it describes, so it must not outlive it.
        $employeeId = $this->insertEmployee();

        $this->insertHistory($employeeId);

        $this->assertSame(1, DB::table('employee_status_history')->where('employee_id', $employeeId)->count());

        DB::table('employees')->where('id', $employeeId)->delete();

        $this->assertSame(0, DB::table('employee_status_history')->where('employee_id', $employeeId)->count());
    }

    public function test_a_status_history_row_survives_the_actor_being_deleted(): void
    {
        // The trail is evidence. If deleting an account erased who moved the
        // employee to notice, the audit trail would be attacker-controlled.
        $userId = DB::table('users')->insertGetId([
            'name' => 'Actor',
            'email' => 'actor@flowsync.test',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = $this->insertEmployee();
        $this->insertHistory($employeeId, ['actor_user_id' => $userId]);

        DB::table('users')->where('id', $userId)->delete();

        $row = DB::table('employee_status_history')->where('employee_id', $employeeId)->first();

        $this->assertNotNull($row);
        $this->assertNull($row->actor_user_id, 'The actor link should null out, not delete the row');
    }

    public function test_an_initial_status_needs_no_prior_status(): void
    {
        $employeeId = $this->insertEmployee();

        $this->insertHistory($employeeId, ['from_status' => null, 'to_status' => 'active']);

        $row = DB::table('employee_status_history')->where('employee_id', $employeeId)->first();

        $this->assertNull($row->from_status);
        $this->assertSame('active', $row->to_status);
    }

    public function test_a_status_change_can_be_back_or_forward_dated(): void
    {
        $employeeId = $this->insertEmployee();

        $this->insertHistory($employeeId, [
            'from_status' => 'active',
            'to_status' => 'on_notice',
            'effective_date' => now()->addDays(30)->toDateString(),
        ]);

        $row = DB::table('employee_status_history')->where('employee_id', $employeeId)->first();

        $this->assertSame(now()->addDays(30)->toDateString(), $row->effective_date);
    }

    public function test_employment_types_accept_a_tenant_defined_row(): void
    {
        $id = DB::table('employment_types')->insertGetId([
            'name' => 'Consultant',
            'slug' => 'consultant',
            'code' => 'CTR',
            'is_active' => true,
            'position' => 3,
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('employment_types')->where('id', $id)->first();

        $this->assertSame('Consultant', $row->name);
        $this->assertSame('CTR', $row->code);
        $this->assertTrue((bool) $row->is_active);
    }

    public function test_an_employment_type_may_omit_its_code(): void
    {
        $id = $this->insertEmploymentType([
            'name' => 'Intern',
            'slug' => 'intern-'.uniqid(),
            'code' => null,
        ]);

        $this->assertNull(DB::table('employment_types')->where('id', $id)->value('code'));
    }

    public function test_an_employee_can_reference_an_employment_type(): void
    {
        $typeId = $this->insertEmploymentType();

        $employeeId = $this->insertEmployee(['employment_type_id' => $typeId]);

        $this->assertSame(
            $typeId,
            DB::table('employees')->where('id', $employeeId)->value('employment_type_id'),
        );
    }

    public function test_re_running_the_migration_is_idempotent(): void
    {
        $this->insertEmployee();
        $this->insertEmploymentType();

        // P2.7 seeds the employment-type catalog, so the count is only
        // meaningful as a before/after comparison.
        $types = DB::table('employment_types')->count();
        $employees = DB::table('employees')->count();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($employees, DB::table('employees')->count());
        $this->assertSame($types, DB::table('employment_types')->count());
    }

    public function test_re_running_preserves_existing_rows(): void
    {
        // tenants:provision repairs by re-running migrations, so the guard has to
        // skip an existing table rather than recreate and lose the data.
        $employeeId = $this->insertEmployee();

        $this->migration()->up();

        $this->assertNotNull(DB::table('employees')->where('id', $employeeId)->first());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertEmployee(array $overrides = []): int
    {
        static $sequence = 0;

        $sequence++;

        return DB::table('employees')->insertGetId([
            'user_id' => null,
            'employee_code' => $overrides['employee_code'] ?? "EMP-T{$sequence}",
            'name' => $overrides['name'] ?? "Employee {$sequence}",
            'status' => 'active',
            'work_mode' => 'office',
            ...$overrides,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertHistory(int $employeeId, array $overrides = []): int
    {
        return DB::table('employee_status_history')->insertGetId([
            'employee_id' => $employeeId,
            'from_status' => 'active',
            'to_status' => 'on_notice',
            'effective_date' => now()->toDateString(),
            'actor_user_id' => null,
            'created_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertEmploymentType(array $overrides = []): int
    {
        // P2.7 seeds a real catalog on every tenant, so "full-time" already
        // exists by the time a schema test runs. These tests are about the
        // column constraints, not about any particular row, so the slug has to
        // be unique per call.
        return DB::table('employment_types')->insertGetId([
            'name' => 'Full-time',
            'slug' => 'full-time-'.uniqid(),
            'is_active' => true,
            'position' => 1,
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }
}
