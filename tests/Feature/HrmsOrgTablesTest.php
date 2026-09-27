<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P3.1 — the HRMS organization tables.
 *
 * The isolation trait migrates every tenant, so this asserts the migration's
 * own contract rather than that the tables exist: the FK actions, the decimal
 * coordinates, the free-text backfill, and — most importantly — that a second
 * run is a no-op, since `tenants:provision` re-runs tenant migrations to repair
 * a database that failed partway.
 *
 * The FK tests here and the enum-CHECK tests in HrmsEmployeeTablesTest are a
 * deliberate pair: the first proves the three `employees` columns carry working
 * `on delete set null` constraints, the second proves that adding them did not
 * cost the table its `status`/`work_mode` CHECKs. Dropping the rebuild in
 * favour of raw `ADD COLUMN ... REFERENCES` is what keeps both true, and
 * breaking it fails one test or the other.
 */
class HrmsOrgTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant/2026_09_27_000016_create_hrms_org_tables.php');
    }

    public function test_the_org_tables_exist_on_a_fresh_tenant(): void
    {
        foreach (['departments', 'designations', 'locations'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_the_employee_record_gained_the_three_org_columns(): void
    {
        foreach (['department_id', 'designation_id', 'location_id'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('employees', $column),
                "Missing employees.{$column}",
            );
        }
    }

    public function test_the_shift_column_is_still_absent(): void
    {
        // shift_id belongs to P5, with the shifts table. If this migration ever
        // adds it early, the P5 migration's ALTER would be skipped and the two
        // would drift.
        $this->assertFalse(Schema::hasColumn('employees', 'shift_id'));
    }

    public function test_there_is_no_tenant_id_column(): void
    {
        foreach (['departments', 'designations', 'locations'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'tenant_id'), "{$table}.tenant_id must not exist");
        }
    }

    public function test_a_department_slug_is_unique(): void
    {
        $id = $this->insertDepartment(['name' => 'Engineering', 'slug' => 'engineering']);

        $this->expectException(QueryException::class);

        $this->insertDepartment(['name' => 'Engineering (EU)', 'slug' => 'engineering']);
    }

    public function test_deleting_a_department_leaves_its_children_standing(): void
    {
        // The tempting cascade, and the wrong one: it would destroy an entire
        // subtree when one mid-level department is removed. Orphaning is
        // recoverable — re-parent it, or deactivate it.
        $parent = $this->insertDepartment(['name' => 'Engineering', 'slug' => 'engineering']);
        $child = $this->insertDepartment([
            'name' => 'Platform',
            'slug' => 'platform',
            'parent_id' => $parent,
        ]);
        $grandchild = $this->insertDepartment([
            'name' => 'Infrastructure',
            'slug' => 'infrastructure',
            'parent_id' => $child,
        ]);

        DB::table('departments')->where('id', $child)->delete();

        $this->assertNotNull(
            DB::table('departments')->where('id', $grandchild)->first(),
            'cascading parent_id would have deleted a whole subtree',
        );
        $this->assertNull(
            DB::table('departments')->where('id', $grandchild)->value('parent_id'),
            'the orphan should point at nobody, not at a row that no longer exists',
        );
    }

    public function test_a_deleted_head_leaves_the_department_in_place(): void
    {
        $head = $this->insertEmployee(['name' => 'Ada Lovelace']);
        $department = $this->insertDepartment([
            'name' => 'Engineering',
            'slug' => 'engineering',
            'head_employee_id' => $head,
        ]);

        DB::table('employees')->where('id', $head)->delete();

        $this->assertNotNull(DB::table('departments')->where('id', $department)->first());
        $this->assertNull(
            DB::table('departments')->where('id', $department)->value('head_employee_id'),
            'the department should be left headless, not deleted',
        );
    }

    public function test_a_deleted_department_orphans_its_employees_instead_of_deleting_them(): void
    {
        $department = $this->insertDepartment(['name' => 'Engineering', 'slug' => 'engineering']);
        $employee = $this->insertEmployee(['name' => 'Grace Hopper', 'department_id' => $department]);

        DB::table('departments')->where('id', $department)->delete();

        $this->assertNotNull(
            DB::table('employees')->where('id', $employee)->first(),
            'cascading department_id would have deleted a person from the payroll',
        );
        $this->assertNull(DB::table('employees')->where('id', $employee)->value('department_id'));
    }

    public function test_the_geo_columns_are_decimal_rather_than_float(): void
    {
        // A float cannot hold most decimal fractions exactly, so a coordinate
        // round-trips to a different value than the one stored, and a geofence
        // comparing them needs a latitude-dependent fudge factor.
        foreach (['geo_lat', 'geo_lng'] as $column) {
            $type = Schema::getColumnType('locations', $column);

            $this->assertContains(
                $type,
                ['decimal', 'numeric'],
                "locations.{$column} must be decimal, got {$type}",
            );
        }
    }

    public function test_a_coordinate_round_trips_exactly(): void
    {
        // 7 decimal places is ~1 cm; the value is chosen so a float would
        // return a visibly different number.
        $id = $this->insertLocation(['geo_lat' => '12.3456789', 'geo_lng' => '-98.7654321']);

        $row = DB::table('locations')->where('id', $id)->first();

        $this->assertSame('12.3456789', (string) $row->geo_lat);
        $this->assertSame('-98.7654321', (string) $row->geo_lng);
    }

    public function test_a_location_with_no_radius_has_no_geofence(): void
    {
        // A radius of 0 would match exactly one spot on the planet, which is
        // not what "we did not configure a geofence" should mean.
        $id = $this->insertLocation();

        $row = DB::table('locations')->where('id', $id)->first();

        $this->assertFalse((bool) $row->is_geo_fenced);
        $this->assertNull($row->geo_radius_m);
    }

    public function test_the_free_text_designation_is_normalized_where_a_matching_slug_exists(): void
    {
        $department = $this->insertDepartment(['name' => 'Engineering', 'slug' => 'engineering']);
        $designation = $this->insertDesignation([
            'name' => 'Senior Engineer',
            'slug' => 'senior-engineer',
            'department_id' => $department,
        ]);
        $employee = $this->insertEmployee(['designation' => 'Senior Engineer']);

        $this->migration()->up();

        $row = DB::table('employees')->where('id', $employee)->first();

        $this->assertSame($designation, $row->designation_id);
        // Through the matched row, not inferred from the words.
        $this->assertSame($department, $row->department_id);
    }

    public function test_the_free_text_match_is_case_and_spacing_insensitive(): void
    {
        $designation = $this->insertDesignation(['name' => 'Senior Engineer', 'slug' => 'senior-engineer']);
        $employee = $this->insertEmployee(['designation' => '  SENIOR   Engineer ']);

        $this->migration()->up();

        $this->assertSame(
            $designation,
            DB::table('employees')->where('id', $employee)->value('designation_id'),
        );
    }

    public function test_a_free_text_designation_with_no_matching_catalog_row_is_left_alone(): void
    {
        // The tenant has not catalogued "Widget Wrangler" yet. Inventing a row
        // for it would put a designation in the catalog that nobody approved.
        $this->insertDesignation(['name' => 'Senior Engineer', 'slug' => 'senior-engineer']);
        $employee = $this->insertEmployee(['designation' => 'Widget Wrangler']);

        $this->migration()->up();

        $this->assertNull(DB::table('employees')->where('id', $employee)->value('designation_id'));
    }

    public function test_the_backfill_never_overwrites_a_department_somebody_set_by_hand(): void
    {
        $matched = $this->insertDepartment(['name' => 'Engineering', 'slug' => 'engineering']);
        $handPicked = $this->insertDepartment(['name' => 'Operations', 'slug' => 'operations']);
        $designation = $this->insertDesignation([
            'name' => 'Senior Engineer',
            'slug' => 'senior-engineer',
            'department_id' => $matched,
        ]);
        $employee = $this->insertEmployee([
            'designation' => 'Senior Engineer',
            'department_id' => $handPicked,
        ]);

        $this->migration()->up();

        $row = DB::table('employees')->where('id', $employee)->first();

        $this->assertSame($designation, $row->designation_id);
        $this->assertSame(
            $handPicked,
            $row->department_id,
            'a heuristic slug match must never overwrite a deliberate assignment',
        );
    }

    public function test_the_backfill_leaves_a_trashed_employee_alone(): void
    {
        $this->insertDesignation(['name' => 'Senior Engineer', 'slug' => 'senior-engineer']);
        $employee = $this->insertEmployee(['designation' => 'Senior Engineer']);
        DB::table('employees')->where('id', $employee)->update(['deleted_at' => now()]);

        $this->migration()->up();

        $this->assertNull(
            DB::table('employees')->where('id', $employee)->value('designation_id'),
            'rewriting a deleted record is noise; the free text is still there for whoever restores it',
        );
    }

    public function test_the_backfill_does_not_invent_recency(): void
    {
        $this->insertDesignation(['name' => 'Senior Engineer', 'slug' => 'senior-engineer']);
        $employee = $this->insertEmployee(['designation' => 'Senior Engineer']);
        $before = DB::table('employees')->where('id', $employee)->value('updated_at');

        $this->migration()->up();

        $this->assertSame(
            $before,
            DB::table('employees')->where('id', $employee)->value('updated_at'),
            'nothing about these people changed — the same fact moved to a better column',
        );
    }

    public function test_a_designation_with_no_department_leaves_the_employee_without_one(): void
    {
        // "Manager" is a level, not a department, so most catalog rows have no
        // department_id. Guessing one from the job title is not available.
        $designation = $this->insertDesignation(['name' => 'Manager', 'slug' => 'manager']);
        $employee = $this->insertEmployee(['designation' => 'Manager']);

        $this->migration()->up();

        $this->assertSame(
            $designation,
            DB::table('employees')->where('id', $employee)->value('designation_id'),
        );
        $this->assertNull(DB::table('employees')->where('id', $employee)->value('department_id'));
    }

    public function test_an_empty_catalog_scans_nothing(): void
    {
        // A tenant whose designations table is still empty has nothing to match
        // against; every employee is left exactly as it was.
        $employee = $this->insertEmployee(['designation' => 'Senior Engineer']);

        $this->migration()->up();

        $this->assertNull(DB::table('employees')->where('id', $employee)->value('designation_id'));
    }

    public function test_re_running_the_migration_changes_nothing(): void
    {
        $designation = $this->insertDesignation(['name' => 'Senior Engineer', 'slug' => 'senior-engineer']);
        $employee = $this->insertEmployee(['designation' => 'Senior Engineer']);
        $this->migration()->up();

        $before = DB::table('employees')->where('id', $employee)->first();

        $this->migration()->up();
        $this->migration()->up();

        $after = DB::table('employees')->where('id', $employee)->first();

        $this->assertSame($designation, $after->designation_id);
        $this->assertEquals($before, $after, 'a re-run must be a no-op — this is the tenants:provision path');
        $this->assertSame(1, DB::table('designations')->where('id', $designation)->count());
    }

    public function test_the_down_migration_drops_the_org_tables(): void
    {
        $this->migration()->down();

        foreach (['departments', 'designations', 'locations'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} should be gone after down()");
        }

        foreach (['department_id', 'designation_id', 'location_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('employees', $column));
        }
    }

    private function insertDepartment(array $overrides = []): int
    {
        return DB::table('departments')->insertGetId([
            'name' => 'Department',
            'slug' => 'department-'.uniqid(),
            'is_active' => true,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertDesignation(array $overrides = []): int
    {
        return DB::table('designations')->insertGetId([
            'name' => 'Designation',
            'slug' => 'designation-'.uniqid(),
            'is_active' => true,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertLocation(array $overrides = []): int
    {
        return DB::table('locations')->insertGetId([
            'name' => 'Location',
            'slug' => 'location-'.uniqid(),
            'is_active' => true,
            'is_geo_fenced' => false,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertEmployee(array $overrides = []): int
    {
        static $sequence = 0;

        $sequence++;

        return DB::table('employees')->insertGetId([
            'user_id' => null,
            'employee_code' => $overrides['employee_code'] ?? "EMP-ORG{$sequence}",
            'name' => $overrides['name'] ?? "Employee {$sequence}",
            'status' => 'active',
            'work_mode' => 'office',
            ...$overrides,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
