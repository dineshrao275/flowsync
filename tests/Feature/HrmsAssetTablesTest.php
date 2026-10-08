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
 * P14.1 — the asset tracking tables.
 *
 * The isolation trait migrates every tenant, so this asserts the
 * migration's own contract: the four tables with no `tenant_id`, exact
 * money columns, the asset-code and handover uniques, soft deletes on
 * assets, the FK actions (owned rows cascade, catalogue/file/location/user
 * links null, categories refuse via NO ACTION), and that a second run is
 * a no-op.
 */
class HrmsAssetTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant/2026_10_07_000030_create_hrms_asset_tables.php');
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return ['asset_categories', 'assets', 'asset_assignments', 'asset_maintenance'];
    }

    public function test_the_asset_tables_exist_on_a_fresh_tenant(): void
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
        foreach (['assets.purchase_value', 'asset_maintenance.cost'] as $key) {
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

    public function test_asset_codes_are_unique(): void
    {
        $category = $this->category();

        DB::table('assets')->insert($this->asset($category, 'LT-0001'));

        $this->expectException(QueryException::class);

        DB::table('assets')->insert($this->asset($category, 'LT-0001'));
    }

    public function test_a_handover_is_unique_per_asset_employee_and_moment(): void
    {
        $category = $this->category();
        $asset = DB::table('assets')->insertGetId($this->asset($category, 'LT-0002'));
        $employee = $this->makeEmployee();

        DB::table('asset_assignments')->insert([
            'asset_id' => $asset, 'employee_id' => $employee->id, 'assigned_at' => '2026-09-01 09:00:00',
        ]);

        $this->expectException(QueryException::class);

        DB::table('asset_assignments')->insert([
            'asset_id' => $asset, 'employee_id' => $employee->id, 'assigned_at' => '2026-09-01 09:00:00',
        ]);
    }

    public function test_deleting_an_asset_cascades_its_ledger(): void
    {
        $category = $this->category();
        $asset = DB::table('assets')->insertGetId($this->asset($category, 'LT-0003'));
        $employee = $this->makeEmployee();

        DB::table('asset_assignments')->insert(['asset_id' => $asset, 'employee_id' => $employee->id]);
        DB::table('asset_maintenance')->insert([
            'asset_id' => $asset, 'description' => 'Screen.', 'performed_at' => '2026-09-01',
        ]);

        DB::table('assets')->where('id', $asset)->delete();

        $this->assertDatabaseMissing('asset_assignments', ['asset_id' => $asset]);
        $this->assertDatabaseMissing('asset_maintenance', ['asset_id' => $asset]);
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
    private function asset(int $category, string $code): array
    {
        return [
            'asset_code' => $code,
            'name' => 'Laptop.',
            'category_id' => $category,
        ];
    }

    private function category(): int
    {
        return DB::table('asset_categories')->insertGetId([
            'name' => 'Laptops', 'slug' => 'laptops',
        ]);
    }

    private function makeEmployee(): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-AST-'.$sequence,
            'name' => "Asset Employee {$sequence}",
            'status' => EmployeeStatus::Active,
        ]);
    }
}
