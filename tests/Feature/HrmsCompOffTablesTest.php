<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P7.1 — the comp-off tables.
 *
 * The isolation trait migrates every tenant, so this asserts the migration's
 * own contract: the idempotency unique (employee, date, source), the day
 * split's unique pair, the FK actions, the enum CHECKs, and that a second
 * run is a no-op.
 */
class HrmsCompOffTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant/2026_09_29_000020_create_hrms_comp_off_tables.php');
    }

    public function test_the_comp_off_tables_exist_on_a_fresh_tenant(): void
    {
        foreach ($this->tables() as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_there_is_no_tenant_id_column(): void
    {
        foreach ($this->tables() as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'tenant_id'), "{$table}.tenant_id must not exist");
        }
    }

    public function test_a_credit_is_unique_per_employee_date_and_source(): void
    {
        $employee = $this->insertEmployee();

        $this->insertCredit($employee, ['work_date' => '2026-10-04', 'source_type' => 'weekend']);

        // Same source twice refuses — this is the idempotency a rerun leans on.
        $this->expectException(QueryException::class);

        $this->insertCredit($employee, ['work_date' => '2026-10-04', 'source_type' => 'weekend']);
    }

    public function test_a_second_source_on_the_same_date_is_a_separate_credit(): void
    {
        $employee = $this->insertEmployee();

        $this->insertCredit($employee, ['work_date' => '2026-10-04', 'source_type' => 'weekend']);
        $this->insertCredit($employee, ['work_date' => '2026-10-04', 'source_type' => 'holiday']);

        $this->assertSame(2, DB::table('comp_off_credits')->where('employee_id', $employee)->count());
    }

    public function test_a_request_day_is_unique_per_request_and_date(): void
    {
        $request = $this->insertRequest();

        DB::table('comp_off_request_days')->insert([
            'comp_off_request_id' => $request,
            'date' => '2026-10-06',
            'minutes' => 480,
        ]);

        $this->expectException(QueryException::class);

        DB::table('comp_off_request_days')->insert([
            'comp_off_request_id' => $request,
            'date' => '2026-10-06',
            'minutes' => 480,
        ]);
    }

    public function test_deleting_an_employee_cascades_their_comp_off_rows(): void
    {
        $employee = $this->insertEmployee();
        $this->insertCredit($employee);
        $request = $this->insertRequest(['employee_id' => $employee]);
        DB::table('comp_off_request_days')->insert([
            'comp_off_request_id' => $request,
            'date' => '2026-10-06',
            'minutes' => 480,
        ]);

        DB::table('employees')->where('id', $employee)->delete();

        $this->assertSame(0, DB::table('comp_off_credits')->where('employee_id', $employee)->count());
        $this->assertSame(0, DB::table('comp_off_requests')->where('employee_id', $employee)->count());
        $this->assertSame(0, DB::table('comp_off_request_days')->where('comp_off_request_id', $request)->count());
    }

    public function test_deleting_an_approval_orphans_the_request(): void
    {
        $approval = DB::table('approvals')->insertGetId([
            'approvable_type' => 'comp_off_requests',
            'approvable_id' => 0,
            'subject' => 'A day back',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $request = $this->insertRequest(['approval_id' => $approval]);

        DB::table('approvals')->where('id', $approval)->delete();

        $row = DB::table('comp_off_requests')->where('id', $request)->first();

        $this->assertNotNull($row, 'a request must survive its approval');
        $this->assertNull($row->approval_id);
    }

    public function test_the_comp_off_enums_are_enforced_by_the_database(): void
    {
        try {
            $this->insertCredit($this->insertEmployee(), ['source_type' => 'birthday']);
            $this->fail('A made-up credit source must not insert.');
        } catch (QueryException) {
            // Expected on either grammar.
        }

        $this->expectException(QueryException::class);

        $this->insertRequest(['status' => 'maybe']);
    }

    public function test_the_migration_is_safe_to_run_twice(): void
    {
        $employee = $this->insertEmployee();
        $this->insertCredit($employee, ['work_date' => '2026-10-04', 'source_type' => 'weekend']);

        $this->migration()->up();

        $this->assertSame(1, DB::table('comp_off_credits')->where('employee_id', $employee)->count());
    }

    public function test_the_down_migration_drops_the_comp_off_tables(): void
    {
        $this->migration()->down();

        foreach ($this->tables() as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} survived down().");
        }

        // And back up again, so later tests in this process still see them.
        $this->migration()->up();

        foreach ($this->tables() as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} did not come back.");
        }
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return [
            'comp_off_credits',
            'comp_off_requests',
            'comp_off_request_days',
        ];
    }

    private function insertEmployee(array $overrides = []): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => 'EMP-'.strtoupper(self::token()),
            'name' => 'Comp-off Person',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertCredit(int $employee, array $overrides = []): int
    {
        return (int) DB::table('comp_off_credits')->insertGetId([
            'employee_id' => $employee,
            'work_date' => '2026-10-04',
            'source_type' => 'weekend',
            'minutes' => 480,
            'created_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertRequest(array $overrides = []): int
    {
        $employee = $overrides['employee_id'] ?? $this->insertEmployee();
        unset($overrides['employee_id']);

        return (int) DB::table('comp_off_requests')->insertGetId([
            'employee_id' => $employee,
            'from_date' => '2026-10-06',
            'to_date' => '2026-10-06',
            'total_minutes' => 480,
            'reason' => 'Worked the weekend.',
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
