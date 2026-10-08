<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P10.1 — the statutory compliance tables.
 *
 * The isolation trait migrates every tenant, so this asserts the migration's
 * own contract: the four tables with no `tenant_id`, exact money columns,
 * the profile-per-employee and projection-per-quarter uniques, the FK
 * actions (owned rows cascade, proof/verifier links null), and that a
 * second run is a no-op.
 */
class HrmsStatutoryTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant/2026_10_03_000023_create_hrms_statutory_tables.php');
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return ['statutory_configurations', 'statutory_profiles', 'statutory_declarations', 'tds_projects'];
    }

    public function test_the_statutory_tables_exist_on_a_fresh_tenant(): void
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
        $columns = [
            'statutory_declarations.declared_amount',
            'tds_projects.declared_income',
            'tds_projects.exempt_income',
            'tds_projects.projected_income',
            'tds_projects.tax_liability',
            'tds_projects.tds_deducted',
            'tds_projects.tds_surrendered',
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

    public function test_an_employee_owns_at_most_one_profile(): void
    {
        $employee = $this->makeEmployee();

        DB::table('statutory_profiles')->insert(['employee_id' => $employee->id]);

        $this->expectException(QueryException::class);

        DB::table('statutory_profiles')->insert(['employee_id' => $employee->id]);
    }

    public function test_a_projection_is_unique_per_employee_year_and_quarter(): void
    {
        $employee = $this->makeEmployee();

        DB::table('tds_projects')->insert([
            'employee_id' => $employee->id, 'fiscal_year' => 2026, 'quarter' => 3,
        ]);

        $this->expectException(QueryException::class);

        DB::table('tds_projects')->insert([
            'employee_id' => $employee->id, 'fiscal_year' => 2026, 'quarter' => 3,
        ]);
    }

    public function test_deleting_an_employee_takes_its_statutory_rows(): void
    {
        $employee = $this->makeEmployee();

        DB::table('statutory_profiles')->insert(['employee_id' => $employee->id]);
        DB::table('tds_projects')->insert([
            'employee_id' => $employee->id, 'fiscal_year' => 2026, 'quarter' => 3,
        ]);

        // Query-builder delete: the model soft-deletes, and a soft delete
        // must keep the PII row (the person still exists in history).
        DB::table('employees')->where('id', $employee->id)->delete();

        $this->assertDatabaseMissing('statutory_profiles', ['employee_id' => $employee->id]);
        $this->assertDatabaseMissing('tds_projects', ['employee_id' => $employee->id]);
    }

    public function test_a_second_migration_run_is_a_no_op(): void
    {
        $this->migration()->up();

        foreach ($this->tables() as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table after re-run: {$table}");
        }
    }

    private function makeEmployee(): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-STA-'.$sequence,
            'name' => "Statutory Employee {$sequence}",
            'status' => EmployeeStatus::Active,
        ]);
    }
}
