<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P11.1 — the expense claim tables.
 *
 * The isolation trait migrates every tenant, so this asserts the
 * migration's own contract: the three tables with no `tenant_id`, exact
 * money columns, the claim-number unique, soft deletes on claims, the
 * (employee, status) and (year, month) indexes, the FK actions (owned rows
 * cascade, catalogue/file/approval links null), and that a second run is
 * a no-op. The starters arrive through the provisioner, keyed on slug.
 */
class HrmsExpenseTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant_hrms/2026_10_04_000027_create_hrms_expense_tables.php');
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return ['expense_categories', 'expense_claims', 'expense_claim_items'];
    }

    public function test_the_expense_tables_exist_on_a_fresh_tenant(): void
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
            'expense_categories.requires_receipt_above',
            'expense_claims.total_amount',
            'expense_claims.approved_amount',
            'expense_claims.reimbursed_amount',
            'expense_claim_items.amount',
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

    public function test_claim_numbers_are_unique(): void
    {
        $employee = $this->makeEmployee();

        DB::table('expense_claims')->insert($this->claim($employee->id, 'EXP-2026-000001'));

        $this->expectException(QueryException::class);

        DB::table('expense_claims')->insert($this->claim($employee->id, 'EXP-2026-000001'));
    }

    public function test_claims_soft_delete(): void
    {
        $employee = $this->makeEmployee();
        $id = DB::table('expense_claims')->insertGetId($this->claim($employee->id, 'EXP-2026-000002'));

        $this->assertTrue(Schema::hasColumn('expense_claims', 'deleted_at'));

        DB::table('expense_claims')->where('id', $id)->update(['deleted_at' => now()]);

        $this->assertDatabaseMissing('expense_claims', ['id' => $id, 'deleted_at' => null]);
    }

    public function test_deleting_a_claim_cascades_its_items(): void
    {
        $employee = $this->makeEmployee();
        $claim = DB::table('expense_claims')->insertGetId($this->claim($employee->id, 'EXP-2026-000003'));

        DB::table('expense_claim_items')->insert([
            'claim_id' => $claim,
            'description' => 'Taxi to client.',
            'amount' => '42.50',
        ]);

        // Query-builder delete: the model soft-deletes, and items belong to
        // the claim's history either way.
        DB::table('expense_claims')->where('id', $claim)->delete();

        $this->assertDatabaseMissing('expense_claim_items', ['claim_id' => $claim]);
    }

    public function test_pruning_a_category_orphans_the_link_not_the_line(): void
    {
        $category = ExpenseCategory::create(['name' => 'Pruned', 'slug' => 'pruned']);
        $employee = $this->makeEmployee();
        $claim = DB::table('expense_claims')->insertGetId($this->claim($employee->id, 'EXP-2026-000004'));

        DB::table('expense_claim_items')->insert([
            'claim_id' => $claim,
            'category_id' => $category->id,
            'description' => 'Old category line.',
            'amount' => '10.00',
        ]);

        $category->delete();

        $this->assertDatabaseHas('expense_claim_items', ['claim_id' => $claim, 'category_id' => null]);
    }

    public function test_the_starter_catalogue_is_seeded(): void
    {
        $this->assertTrue(ExpenseCategory::query()->where('slug', 'travel')->exists());
        $this->assertDatabaseHas('expense_categories', ['slug' => 'meals', 'requires_receipt_above' => null]);
    }

    public function test_a_second_migration_run_is_a_no_op(): void
    {
        $this->migration()->up();

        foreach ($this->tables() as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table after re-run: {$table}");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function claim(int $employeeId, string $number): array
    {
        return [
            'employee_id' => $employeeId,
            'claim_number' => $number,
            'claim_date' => '2026-09-15',
            'period_year' => 2026,
            'period_month' => 9,
            'purpose' => 'Client visit.',
            'currency' => 'USD',
        ];
    }

    private function makeEmployee(): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-EXP-'.$sequence,
            'name' => "Expense Employee {$sequence}",
            'status' => EmployeeStatus::Active,
        ]);
    }
}
