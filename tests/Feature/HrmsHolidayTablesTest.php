<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P8.1 — the holiday tables.
 *
 * The isolation trait migrates every tenant, so this asserts the migration's
 * own contract: the per-calendar date index, the assignment triple and the
 * optional pair uniques, the cascade directions, the enum CHECKs, and that
 * a second run is a no-op.
 */
class HrmsHolidayTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant_hrms/2026_09_29_000021_create_hrms_holiday_tables.php');
    }

    public function test_the_holiday_tables_exist_on_a_fresh_tenant(): void
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

    public function test_a_calendar_slug_is_unique(): void
    {
        $this->insertCalendar(['slug' => 'national']);

        $this->expectException(QueryException::class);

        $this->insertCalendar(['slug' => 'national']);
    }

    public function test_an_assignment_is_unique_per_employee_calendar_and_start(): void
    {
        $employee = $this->insertEmployee();
        $calendar = $this->insertCalendar();

        DB::table('employee_holiday_calendars')->insert([
            'employee_id' => $employee,
            'calendar_id' => $calendar,
            'effective_from' => '2026-01-01',
        ]);

        $this->expectException(QueryException::class);

        DB::table('employee_holiday_calendars')->insert([
            'employee_id' => $employee,
            'calendar_id' => $calendar,
            'effective_from' => '2026-01-01',
        ]);
    }

    public function test_an_optional_answer_is_unique_per_employee_and_holiday(): void
    {
        $employee = $this->insertEmployee();
        $holiday = $this->insertHoliday();

        DB::table('holiday_optional_holidays')->insert([
            'employee_id' => $employee,
            'holiday_id' => $holiday,
        ]);

        $this->expectException(QueryException::class);

        DB::table('holiday_optional_holidays')->insert([
            'employee_id' => $employee,
            'holiday_id' => $holiday,
        ]);
    }

    public function test_deleting_a_calendar_cascades_its_holidays_and_assignments(): void
    {
        $calendar = $this->insertCalendar();
        $holiday = $this->insertHoliday(['calendar_id' => $calendar]);
        $employee = $this->insertEmployee();
        $this->insertAssignment($employee, $calendar);
        DB::table('holiday_optional_holidays')->insert([
            'employee_id' => $employee,
            'holiday_id' => $holiday,
        ]);

        DB::table('holiday_calendars')->where('id', $calendar)->delete();

        $this->assertSame(0, DB::table('holidays')->where('calendar_id', $calendar)->count());
        $this->assertSame(0, DB::table('employee_holiday_calendars')->where('calendar_id', $calendar)->count());
        $this->assertSame(0, DB::table('holiday_optional_holidays')->where('holiday_id', $holiday)->count());
    }

    public function test_deleting_an_employee_cascades_their_holiday_rows(): void
    {
        $employee = $this->insertEmployee();
        $calendar = $this->insertCalendar();
        $holiday = $this->insertHoliday(['calendar_id' => $calendar]);
        $this->insertAssignment($employee, $calendar);
        DB::table('holiday_optional_holidays')->insert([
            'employee_id' => $employee,
            'holiday_id' => $holiday,
        ]);

        DB::table('employees')->where('id', $employee)->delete();

        $this->assertSame(0, DB::table('employee_holiday_calendars')->where('employee_id', $employee)->count());
        $this->assertSame(0, DB::table('holiday_optional_holidays')->where('employee_id', $employee)->count());
    }

    public function test_the_holiday_enums_are_enforced_by_the_database(): void
    {
        try {
            $this->insertHoliday(['type' => 'bank']);
            $this->fail('A made-up holiday type must not insert.');
        } catch (QueryException) {
            // Expected on either grammar.
        }

        $this->expectException(QueryException::class);

        DB::table('holiday_optional_holidays')->insert([
            'employee_id' => $this->insertEmployee(),
            'holiday_id' => $this->insertHoliday(),
            'status' => 'maybe',
        ]);
    }

    public function test_the_migration_is_safe_to_run_twice(): void
    {
        $calendar = $this->insertCalendar(['slug' => 'repeatable']);

        $this->migration()->up();

        $this->assertSame(1, DB::table('holiday_calendars')->where('slug', 'repeatable')->count());
        $this->assertSame($calendar, DB::table('holiday_calendars')->where('slug', 'repeatable')->value('id'));
    }

    public function test_the_down_migration_drops_the_holiday_tables(): void
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
            'holiday_calendars',
            'holidays',
            'employee_holiday_calendars',
            'holiday_optional_holidays',
        ];
    }

    private function insertEmployee(array $overrides = []): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => 'EMP-'.strtoupper(self::token()),
            'name' => 'Holiday Person',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertCalendar(array $overrides = []): int
    {
        return (int) DB::table('holiday_calendars')->insertGetId([
            'name' => 'Test Calendar '.self::token(),
            'slug' => 'test-calendar-'.self::token(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertHoliday(array $overrides = []): int
    {
        $calendar = $overrides['calendar_id'] ?? $this->insertCalendar();
        unset($overrides['calendar_id']);

        return (int) DB::table('holidays')->insertGetId([
            'calendar_id' => $calendar,
            'name' => 'Test Holiday',
            'date' => '2026-10-02',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertAssignment(int $employee, int $calendar): int
    {
        return (int) DB::table('employee_holiday_calendars')->insertGetId([
            'employee_id' => $employee,
            'calendar_id' => $calendar,
            'effective_from' => '2026-01-01',
        ]);
    }

    private static function token(): string
    {
        return substr(bin2hex(random_bytes(6)), 0, 10);
    }
}
