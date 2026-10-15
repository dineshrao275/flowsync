<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P9.1 — the payroll core tables.
 *
 * The isolation trait migrates every tenant, so this asserts the migration's
 * own contract: the money columns are decimal (never float), the period and
 * pair uniques, the FK actions (owned rows cascade, catalogue links refuse,
 * user/file/approval links null), the enum CHECKs, and that a second run is
 * a no-op.
 */
class HrmsPayrollTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant_hrms/2026_09_30_000022_create_hrms_payroll_tables.php');
    }

    public function test_the_payroll_tables_exist_on_a_fresh_tenant(): void
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

    public function test_money_columns_are_decimal_not_float(): void
    {
        // Float drift in payroll totals is a correctness bug, not a
        // rounding preference (0.6) — so the schema itself is asserted,
        // not just the values written through it. Both platforms spell
        // fixed-point differently (PostgreSQL `numeric`, SQLite-mapped
        // `decimal`/`numeric`), and either is exact — a float is neither.
        $columns = [
            'salary_components.default_value',
            'salary_structure_components.value',
            'employee_salary_structures.ctc_annual',
            'employee_salary_structures.monthly_ctc',
            'employee_salary_structures.gross_monthly',
            'salary_revisions.from_ctc',
            'salary_revisions.to_ctc',
            'payslips.gross_pay',
            'payslips.total_deductions',
            'payslips.net_pay',
            'payslip_adjustments.amount',
        ];

        foreach ($columns as $key) {
            [$table, $column] = explode('.', $key);
            $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);

            $this->assertNotNull($definition, "{$key} is missing.");
            $this->assertContains(
                $definition['type_name'],
                ['decimal', 'numeric'],
                "{$key} is not exact fixed-point.",
            );
        }
    }

    public function test_a_payroll_run_is_unique_per_period(): void
    {
        $this->insertRun(['period_year' => 2026, 'period_month' => 9]);

        $this->expectException(QueryException::class);

        $this->insertRun(['period_year' => 2026, 'period_month' => 9]);
    }

    public function test_a_payslip_is_unique_per_run_and_employee(): void
    {
        $run = $this->insertRun();
        $employee = $this->insertEmployee();
        $this->insertPayslip($run, $employee);

        $this->expectException(QueryException::class);

        $this->insertPayslip($run, $employee);
    }

    public function test_an_assignment_is_unique_per_employee_and_start(): void
    {
        $employee = $this->insertEmployee();
        $structure = $this->insertStructure();

        $this->insertAssignment($employee, $structure, ['effective_from' => '2026-04-01']);

        $this->expectException(QueryException::class);

        $this->insertAssignment($employee, $structure, ['effective_from' => '2026-04-01']);
    }

    public function test_deleting_a_run_cascades_its_payslips_and_adjustments(): void
    {
        $run = $this->insertRun();
        $payslip = $this->insertPayslip($run, $this->insertEmployee());
        DB::table('payslip_adjustments')->insert([
            'payslip_id' => $payslip,
            'kind' => 'earning',
            'label' => 'Bonus',
            'amount' => 5000,
            'created_at' => now(),
        ]);

        DB::table('payroll_runs')->where('id', $run)->delete();

        $this->assertSame(0, DB::table('payslips')->where('payroll_run_id', $run)->count());
        $this->assertSame(0, DB::table('payslip_adjustments')->where('payslip_id', $payslip)->count());
    }

    public function test_deleting_an_employee_cascades_their_payroll_rows(): void
    {
        $employee = $this->insertEmployee();
        $run = $this->insertRun();
        // The payslip helper provisions the assignment row itself.
        $this->insertPayslip($run, $employee);
        DB::table('salary_revisions')->insert([
            'employee_id' => $employee,
            'from_ctc' => 600000,
            'to_ctc' => 660000,
            'effective_from' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employees')->where('id', $employee)->delete();

        $this->assertSame(0, DB::table('employee_salary_structures')->where('employee_id', $employee)->count());
        $this->assertSame(0, DB::table('salary_revisions')->where('employee_id', $employee)->count());
        $this->assertSame(0, DB::table('payslips')->where('employee_id', $employee)->count());
    }

    public function test_deleting_a_structure_in_use_is_refused(): void
    {
        // NO ACTION, not cascade: the service refuses the delete, and the
        // database backstops the refusal instead of cascading history away.
        $structure = $this->insertStructure();
        $this->insertAssignment($this->insertEmployee(), $structure);

        try {
            DB::table('salary_structures')->where('id', $structure)->delete();
            $this->fail('Deleting a structure with assignments behind it must be refused.');
        } catch (QueryException) {
            // Expected on either grammar.
        }

        $this->assertTrue(DB::table('employee_salary_structures')->where('structure_id', $structure)->exists());
    }

    public function test_deleting_an_approval_orphans_the_revision(): void
    {
        $approval = DB::table('approvals')->insertGetId([
            'approvable_type' => 'salary_revisions',
            'approvable_id' => 0,
            'subject' => 'A raise',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $revision = DB::table('salary_revisions')->insertGetId([
            'employee_id' => $this->insertEmployee(),
            'from_ctc' => 600000,
            'to_ctc' => 660000,
            'effective_from' => '2026-04-01',
            'approval_id' => $approval,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('approvals')->where('id', $approval)->delete();

        $row = DB::table('salary_revisions')->where('id', $revision)->first();

        $this->assertNotNull($row, 'a revision must survive its approval');
        $this->assertNull($row->approval_id);
    }

    public function test_the_payroll_enums_are_enforced_by_the_database(): void
    {
        try {
            DB::table('salary_components')->insert([
                'name' => 'Weird',
                'slug' => 'weird-'.self::token(),
                'type' => 'vibes',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A made-up component type must not insert.');
        } catch (QueryException) {
            // Expected on either grammar.
        }

        try {
            $this->insertRun(['status' => 'eventually']);
            $this->fail('A made-up run status must not insert.');
        } catch (QueryException) {
            // Expected.
        }

        $this->expectException(QueryException::class);

        DB::table('payslip_adjustments')->insert([
            'payslip_id' => $this->insertPayslip($this->insertRun(), $this->insertEmployee()),
            'kind' => 'vibes',
            'label' => 'Mystery',
            'amount' => 100,
            'created_at' => now(),
        ]);
    }

    public function test_the_migration_is_safe_to_run_twice(): void
    {
        $slug = 'repeatable-'.self::token();
        $component = DB::table('salary_components')->insertGetId([
            'name' => 'Repeatable',
            'slug' => $slug,
            'type' => 'earning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertSame(1, DB::table('salary_components')->where('slug', $slug)->count());
        $this->assertSame($component, DB::table('salary_components')->where('slug', $slug)->value('id'));
    }

    public function test_the_down_migration_drops_the_payroll_tables(): void
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
            'salary_components',
            'salary_structures',
            'salary_structure_components',
            'employee_salary_structures',
            'salary_revisions',
            'payslip_templates',
            'payroll_runs',
            'payslips',
            'payslip_adjustments',
        ];
    }

    private function insertEmployee(array $overrides = []): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => 'EMP-'.strtoupper(self::token()),
            'name' => 'Payroll Person',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertStructure(array $overrides = []): int
    {
        return (int) DB::table('salary_structures')->insertGetId([
            'name' => 'Test Structure '.self::token(),
            'slug' => 'test-structure-'.self::token(),
            'effective_from' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertAssignment(int $employee, int $structure, array $overrides = []): int
    {
        return (int) DB::table('employee_salary_structures')->insertGetId([
            'employee_id' => $employee,
            'structure_id' => $structure,
            'ctc_annual' => 600000,
            'monthly_ctc' => 50000,
            'gross_monthly' => 45000,
            'effective_from' => '2026-04-01',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertRun(array $overrides = []): int
    {
        return (int) DB::table('payroll_runs')->insertGetId([
            'period_year' => 2026,
            'period_month' => 8,
            'pay_period_start' => '2026-08-01',
            'pay_period_end' => '2026-08-31',
            'pay_date' => '2026-09-05',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertPayslip(int $run, int $employee, array $overrides = []): int
    {
        $structure = $overrides['employee_salary_structure_id']
            ?? $this->insertAssignment($employee, $this->insertStructure());
        unset($overrides['employee_salary_structure_id']);

        return (int) DB::table('payslips')->insertGetId([
            'payroll_run_id' => $run,
            'employee_id' => $employee,
            'employee_salary_structure_id' => $structure,
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
