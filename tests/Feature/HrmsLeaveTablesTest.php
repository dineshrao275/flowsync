<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P6.1 — the leave tables.
 *
 * The isolation trait migrates every tenant, so this asserts the migration's
 * own contract: the ledger/projection pair, the per-day split, the FK
 * actions (employee cascades, approval/document/user links null, type links
 * refuse), the enum CHECKs, and that a second run is a no-op.
 */
class HrmsLeaveTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant/2026_09_28_000019_create_hrms_leave_tables.php');
    }

    public function test_the_leave_tables_exist_on_a_fresh_tenant(): void
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

    public function test_a_balance_is_unique_per_employee_type_and_year(): void
    {
        $employee = $this->insertEmployee();
        $type = $this->insertType();

        $this->insertBalance($employee, $type, ['year' => 2026]);

        $this->expectException(QueryException::class);

        $this->insertBalance($employee, $type, ['year' => 2026]);
    }

    public function test_a_request_day_is_unique_per_request_and_date(): void
    {
        $request = $this->insertRequest();

        DB::table('leave_request_days')->insert(['leave_request_id' => $request, 'date' => '2026-10-06']);

        $this->expectException(QueryException::class);

        DB::table('leave_request_days')->insert(['leave_request_id' => $request, 'date' => '2026-10-06']);
    }

    public function test_deleting_an_employee_cascades_their_leave_rows(): void
    {
        $employee = $this->insertEmployee();
        $type = $this->insertType();
        $this->insertBalance($employee, $type);
        $this->insertAdjustment($employee, $type);
        $request = $this->insertRequest(['employee_id' => $employee, 'leave_type_id' => $type]);
        DB::table('leave_request_days')->insert(['leave_request_id' => $request, 'date' => '2026-10-06']);
        $this->insertExemption($employee, $type);

        DB::table('employees')->where('id', $employee)->delete();

        foreach (['leave_balances', 'leave_adjustments', 'leave_requests', 'leave_request_days', 'leave_exemption_requests'] as $table) {
            $query = $table === 'leave_request_days'
                ? DB::table($table)->where('leave_request_id', $request)
                : DB::table($table)->where('employee_id', $employee);

            $this->assertSame(0, $query->count(), "{$table} stranded a row.");
        }
    }

    public function test_deleting_a_leave_type_refuses_while_requests_point_at_it(): void
    {
        // NO ACTION, not cascade: the service refuses the delete, and the
        // database backstops the refusal instead of cascading history away.
        $request = $this->insertRequest();
        $type = DB::table('leave_requests')->where('id', $request)->value('leave_type_id');

        try {
            DB::table('leave_types')->where('id', $type)->delete();
            $this->fail('Deleting a type with requests behind it must be refused.');
        } catch (QueryException) {
            // Expected on either grammar.
        }

        $this->assertTrue(DB::table('leave_requests')->where('id', $request)->exists());
    }

    public function test_deleting_an_approval_orphans_the_request_and_the_exemption(): void
    {
        $approval = DB::table('approvals')->insertGetId([
            'approvable_type' => 'leave_requests',
            'approvable_id' => 0,
            'subject' => 'A week off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $request = $this->insertRequest(['approval_id' => $approval]);
        $exemption = $this->insertExemption(
            DB::table('leave_requests')->where('id', $request)->value('employee_id'),
            DB::table('leave_requests')->where('id', $request)->value('leave_type_id'),
            ['approval_id' => $approval],
        );

        DB::table('approvals')->where('id', $approval)->delete();

        $this->assertNull(DB::table('leave_requests')->where('id', $request)->value('approval_id'));
        $this->assertNull(DB::table('leave_exemption_requests')->where('id', $exemption)->value('approval_id'));
    }

    public function test_deleting_a_user_nulls_the_actor_links(): void
    {
        $user = DB::table('users')->insertGetId([
            'name' => 'Leaver',
            'email' => 'leaver-'.self::token().'@flowsync.test',
            'password' => 'password',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $request = $this->insertRequest(['created_by' => $user, 'decided_by_user_id' => $user]);
        $employee = DB::table('leave_requests')->where('id', $request)->value('employee_id');
        $type = DB::table('leave_requests')->where('id', $request)->value('leave_type_id');
        $adjustment = $this->insertAdjustment($employee, $type, ['actor_user_id' => $user]);

        DB::table('users')->where('id', $user)->delete();

        $this->assertNull(DB::table('leave_requests')->where('id', $request)->value('created_by'));
        $this->assertNull(DB::table('leave_requests')->where('id', $request)->value('decided_by_user_id'));
        $this->assertNull(DB::table('leave_adjustments')->where('id', $adjustment)->value('actor_user_id'));
    }

    public function test_the_leave_enums_are_enforced_by_the_database(): void
    {
        try {
            $this->insertType(['accrual_method' => 'whenever']);
            $this->fail('A made-up accrual method must not insert.');
        } catch (QueryException) {
            // Expected on either grammar.
        }

        try {
            $this->insertAdjustment($this->insertEmployee(), $this->insertType(), ['kind' => 'vibes']);
            $this->fail('A made-up ledger kind must not insert.');
        } catch (QueryException) {
            // Expected.
        }

        try {
            $this->insertRequest(['status' => 'maybe']);
            $this->fail('A made-up request status must not insert.');
        } catch (QueryException) {
            // Expected.
        }

        $this->expectException(QueryException::class);

        DB::table('leave_policies')->insert([
            'name' => 'Bad Period',
            'slug' => 'bad-period-'.self::token(),
            'accrual_period' => 'fortnightly',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_migration_is_safe_to_run_twice(): void
    {
        $slug = 'repeatable-'.self::token();
        $type = $this->insertType(['slug' => $slug]);

        $this->migration()->up();

        $this->assertSame(1, DB::table('leave_types')->where('slug', $slug)->count());
        $this->assertSame($type, DB::table('leave_types')->where('slug', $slug)->value('id'));
    }

    public function test_the_down_migration_drops_the_leave_tables(): void
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
            'leave_types',
            'leave_policies',
            'leave_policy_types',
            'leave_balances',
            'leave_adjustments',
            'leave_requests',
            'leave_request_days',
            'leave_exemption_requests',
        ];
    }

    private function insertEmployee(array $overrides = []): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => 'EMP-'.strtoupper(self::token()),
            'name' => 'Leave Person',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertType(array $overrides = []): int
    {
        return (int) DB::table('leave_types')->insertGetId([
            'name' => 'Test Leave '.self::token(),
            'slug' => 'test-leave-'.self::token(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertBalance(int $employee, int $type, array $overrides = []): int
    {
        return (int) DB::table('leave_balances')->insertGetId([
            'employee_id' => $employee,
            'leave_type_id' => $type,
            'year' => 2026,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertAdjustment(int $employee, int $type, array $overrides = []): int
    {
        return (int) DB::table('leave_adjustments')->insertGetId([
            'employee_id' => $employee,
            'leave_type_id' => $type,
            'year' => 2026,
            'kind' => 'opening',
            'quantity' => 12,
            'created_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertRequest(array $overrides = []): int
    {
        $employee = $overrides['employee_id'] ?? $this->insertEmployee();
        $type = $overrides['leave_type_id'] ?? $this->insertType();
        unset($overrides['employee_id'], $overrides['leave_type_id']);

        return (int) DB::table('leave_requests')->insertGetId([
            'employee_id' => $employee,
            'leave_type_id' => $type,
            'from_date' => '2026-10-06',
            'to_date' => '2026-10-07',
            'reason' => 'A short break.',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertExemption(int $employee, int $type, array $overrides = []): int
    {
        return (int) DB::table('leave_exemption_requests')->insertGetId([
            'employee_id' => $employee,
            'leave_type_id' => $type,
            'from_date' => '2026-10-06',
            'to_date' => '2026-10-07',
            'days' => 2,
            'reason' => 'Statutory exemption.',
            'fiscal_year' => 2026,
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
