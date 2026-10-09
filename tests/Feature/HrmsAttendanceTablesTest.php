<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.1 — the attendance tables plus `employees.shift_id`.
 *
 * The isolation trait migrates every tenant, so this asserts the migration’s
 * own contract: the FK actions, the enum CHECKs, the three uniqueness rules,
 * and that a second run is a no-op.
 *
 * The most important test here is `test_the_employees_enum_checks_survive`:
 * P3.1 added org FKs with `constrained()` inside `Schema::table()`, and
 * SQLite silently rebuilt `employees` through a `__temp__` copy that dropped
 * the column-level CHECKs — the test fast-path ended up with a weaker schema
 * than production. `shift_id` is added with raw `ADD COLUMN ... REFERENCES`
 * precisely so that never happens again, and this test would catch it if it
 * did: a bad `status` must be refused by the database itself, on either
 * grammar.
 */
class HrmsAttendanceTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant_hrms/2026_09_28_000018_create_hrms_attendance_tables.php');
    }

    public function test_the_attendance_tables_exist_on_a_fresh_tenant(): void
    {
        foreach ($this->tables() as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $this->assertTrue(Schema::hasColumn('employees', 'shift_id'));
    }

    public function test_there_is_no_tenant_id_column(): void
    {
        foreach ($this->tables() as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'tenant_id'), "{$table}.tenant_id must not exist");
        }
    }

    public function test_the_employees_enum_checks_survive_the_shift_id_alter(): void
    {
        try {
            DB::table('employees')->insert([
                'employee_code' => 'EMP-'.strtoupper(self::token()),
                'name' => 'Unchecked Person',
                'status' => 'spectral',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A made-up employee status must not insert — the CHECKs are gone.');
        } catch (QueryException) {
            // Expected on both grammars: the raw ADD COLUMN left every other
            // column exactly as it found it.
        }

        try {
            DB::table('employees')->insert([
                'employee_code' => 'EMP-'.strtoupper(self::token()),
                'name' => 'Unchecked Person',
                'status' => 'active',
                'work_mode' => 'telepathic',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A made-up work mode must not insert either.');
        } catch (QueryException) {
            // Expected.
        }

        // And a well-formed row still inserts: the alter added a column, it
        // did not change what the table accepts.
        $id = DB::table('employees')->insertGetId([
            'employee_code' => 'EMP-'.strtoupper(self::token()),
            'name' => 'Checked Person',
            'status' => 'active',
            'work_mode' => 'hybrid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($id, DB::table('employees')->where('name', 'Checked Person')->value('id'));
    }

    public function test_a_shift_code_is_unique(): void
    {
        $this->insertShift(['code' => 'general']);

        $this->expectException(QueryException::class);

        $this->insertShift(['code' => 'general']);
    }

    public function test_a_roster_is_unique_per_employee_per_start_date(): void
    {
        $employee = $this->insertEmployee();
        $shift = $this->insertShift();
        $this->insertRoster($employee, $shift, ['effective_from' => '2026-10-01']);

        $this->expectException(QueryException::class);

        $this->insertRoster($employee, $shift, ['effective_from' => '2026-10-01']);
    }

    public function test_a_day_is_unique_per_employee_per_date(): void
    {
        $employee = $this->insertEmployee();
        $this->insertDay($employee, ['work_date' => '2026-10-06']);

        $this->expectException(QueryException::class);

        $this->insertDay($employee, ['work_date' => '2026-10-06']);
    }

    public function test_deleting_an_employee_deletes_their_attendance_rows(): void
    {
        $employee = $this->insertEmployee();
        $shift = $this->insertShift();
        $this->insertRoster($employee, $shift);
        $this->insertPunch($employee);
        $this->insertDay($employee);

        DB::table('employees')->where('id', $employee)->delete();

        foreach (['attendance_rosters', 'attendance_punches', 'attendance_days'] as $table) {
            $this->assertSame(0, DB::table($table)->where('employee_id', $employee)->count(), "{$table} stranded a row.");
        }
    }

    public function test_deleting_a_shift_unassigns_rather_than_destroys(): void
    {
        // The opposite action from the employee cascade, on purpose: a shift
        // is catalogue, and deleting “Night” must leave the roster and the
        // derived days standing with a null shift — not wipe the attendance
        // history of everyone who ever worked nights.
        $employee = $this->insertEmployee();
        $shift = $this->insertShift();
        $roster = $this->insertRoster($employee, $shift);
        $day = $this->insertDay($employee, ['shift_id' => $shift, 'roster_id' => $roster]);
        DB::table('employees')->where('id', $employee)->update(['shift_id' => $shift]);

        DB::table('attendance_shifts')->where('id', $shift)->delete();

        $this->assertNull(DB::table('attendance_rosters')->where('id', $roster)->value('shift_id'));
        $this->assertNull(DB::table('attendance_days')->where('id', $day)->value('shift_id'));
        $this->assertNull(DB::table('employees')->where('id', $employee)->value('shift_id'));
    }

    public function test_deleting_an_approval_orphans_the_regularization_request(): void
    {
        $approval = DB::table('approvals')->insertGetId([
            'approvable_type' => 'attendance_regularization_requests',
            'approvable_id' => 0,
            'subject' => 'Fix a punch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $request = $this->insertRegRequest(['approval_id' => $approval]);

        DB::table('approvals')->where('id', $approval)->delete();

        $row = DB::table('attendance_regularization_requests')->where('id', $request)->first();

        $this->assertNotNull($row, 'a request must survive its approval');
        $this->assertNull($row->approval_id);
    }

    public function test_the_punch_enums_are_enforced_by_the_database(): void
    {
        try {
            $this->insertPunch($this->insertEmployee(), ['direction' => 'sideways']);
            $this->fail('A made-up punch direction must not insert.');
        } catch (QueryException) {
            // Expected on either grammar.
        }

        $this->expectException(QueryException::class);

        $this->insertPunch($this->insertEmployee(), ['source' => 'telepathy']);
    }

    public function test_the_day_and_request_status_enums_are_enforced_by_the_database(): void
    {
        try {
            $this->insertDay($this->insertEmployee(), ['status' => 'vibing']);
            $this->fail('A made-up day status must not insert.');
        } catch (QueryException) {
            // Expected on either grammar.
        }

        $this->expectException(QueryException::class);

        $this->insertRegRequest(['status' => 'maybe']);
    }

    public function test_the_pairing_and_rollup_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasIndex('attendance_punches', ['employee_id', 'punch_at']));
        $this->assertTrue(Schema::hasIndex('attendance_punches', ['punch_at']));
        $this->assertTrue(Schema::hasIndex('employees', 'employees_shift_id_index'));
    }

    /**
     * `tenants:provision` re-runs pending migrations to repair a database that
     * failed partway, so a second `up()` has to be a no-op rather than a
     * duplicate-table error that blocks the repair.
     */
    public function test_the_migration_is_safe_to_run_twice(): void
    {
        $code = 'repeatable-'.self::token();
        $shift = $this->insertShift(['code' => $code]);

        $this->migration()->up();

        $this->assertSame(1, DB::table('attendance_shifts')->where('code', $code)->count());
        $this->assertSame($shift, DB::table('attendance_shifts')->where('code', $code)->value('id'));
        $this->assertTrue(Schema::hasColumn('employees', 'shift_id'));
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return [
            'attendance_shifts',
            'attendance_rosters',
            'attendance_punches',
            'attendance_days',
            'attendance_ip_rules',
            'attendance_regularization_requests',
        ];
    }

    private function insertEmployee(array $overrides = []): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => 'EMP-'.strtoupper(self::token()),
            'name' => 'Attendance Person',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertShift(array $overrides = []): int
    {
        $token = self::token();

        return (int) DB::table('attendance_shifts')->insertGetId([
            'name' => 'Test Shift '.$token,
            'code' => 'test-shift-'.$token,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'position' => 10,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertRoster(int $employee, ?int $shift, array $overrides = []): int
    {
        return (int) DB::table('attendance_rosters')->insertGetId([
            'employee_id' => $employee,
            'shift_id' => $shift,
            'effective_from' => '2026-10-01',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertPunch(int $employee, array $overrides = []): int
    {
        return (int) DB::table('attendance_punches')->insertGetId([
            'employee_id' => $employee,
            'punch_at' => '2026-10-06 09:00:00',
            'direction' => 'in',
            'source' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertDay(int $employee, array $overrides = []): int
    {
        return (int) DB::table('attendance_days')->insertGetId([
            'employee_id' => $employee,
            'work_date' => '2026-10-06',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertRegRequest(array $overrides = []): int
    {
        $employee = $overrides['employee_id'] ?? $this->insertEmployee();
        unset($overrides['employee_id']);

        return (int) DB::table('attendance_regularization_requests')->insertGetId([
            'attendance_day_id' => $this->insertDay($employee),
            'employee_id' => $employee,
            'work_date' => '2026-10-06',
            'reason' => 'Forgot to punch out.',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private static function token(): string
    {
        return substr(bin2hex(random_bytes(6)), 0, 10);
    }
}
