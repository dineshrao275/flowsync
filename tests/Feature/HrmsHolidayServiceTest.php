<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Holiday\EmployeeHolidayCalendar;
use App\Models\Hrms\Holiday\Holiday;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Services\Hrms\Holiday\HolidayAssignments;
use App\Services\Hrms\Holiday\HolidayService;
use App\Services\Hrms\Holiday\HolidayYearSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P8.2 — the single source of non-working days.
 *
 * Calendars resolve per employee per year (assignments plus the tenant
 * default, recurring rows expanded by month/day); public holidays close
 * the office, restricted ones never do, and optional ones count only when
 * declared taken. The config seeder is idempotent per (calendar, name,
 * date), and unparseable entries skip with a warning instead of failing
 * every tenant's provisioning.
 */
class HrmsHolidayServiceTest extends TestCase
{
    use IsolatesDatabase;

    public function test_seed_from_config_creates_calendars_once(): void
    {
        // Provisioning already seeded the current year; a repeat seeds nothing.
        $this->assertSame(2, HolidayCalendar::query()->count());
        $this->assertSame(10, Holiday::query()->count());
        $this->assertSame('US National', HolidayCalendar::query()->default()->firstOrFail()->name);

        $again = $this->seeder()->seedFromConfig((int) today()->year);

        $this->assertSame(['calendars' => 0, 'holidays' => 0], $again);
        $this->assertSame(2, HolidayCalendar::query()->count());
        $this->assertSame(10, Holiday::query()->count());
    }

    public function test_unparseable_entries_skip_with_a_warning(): void
    {
        Config::set('hrms.holidays', [
            'XX' => [
                ['name' => 'Never Day', 'month' => 2, 'day' => 30, 'is_optional' => false],
                ['oops' => 'no keys at all'],
            ],
        ]);

        $result = $this->seeder()->seedFromConfig(2026);

        // The calendar is created; neither row is — February 30th is not a
        // date, and a keyless entry names nothing. Both skip instead of
        // throwing, or one typo would fail every tenant's provisioning.
        $this->assertSame(1, $result['calendars']);
        $this->assertSame(0, $result['holidays']);
    }

    public function test_the_calendar_merges_assigned_and_default_with_expansion(): void
    {
        $employee = $this->makeEmployee();
        $in = HolidayCalendar::query()->where('country', 'IN')->firstOrFail();
        $this->assignments()->assign($employee, $in, '2026-01-01');

        $days = $this->holidays()->calendar($employee, 2027);

        // The assigned IN calendar plus the US default, with 2026's rows
        // recurring into 2027 by month/day.
        $this->assertArrayHasKey('2027-12-25', $days);
        $this->assertArrayHasKey('2027-08-15', $days);
        $this->assertSame('Christmas Day', $days['2027-12-25']['name']);
        $this->assertSame('IN National', $days['2027-08-15']['calendar_name']);
    }

    public function test_public_closes_restricted_never_optional_when_taken(): void
    {
        $employee = $this->makeEmployee();
        $calendar = $this->makeCalendar();
        $public = $this->makeHoliday($calendar, ['name' => 'Founding Day', 'date' => '2026-10-02', 'type' => 'public']);
        $restricted = $this->makeHoliday($calendar, ['name' => 'Solemn Day', 'date' => '2026-10-03', 'type' => 'restricted']);
        $optional = $this->makeHoliday($calendar, ['name' => 'Fete Day', 'date' => '2026-10-04', 'type' => 'optional']);
        $this->assignments()->assign($employee, $calendar, '2026-01-01');

        $this->assertTrue($this->holidays()->isHoliday($employee, '2026-10-02'));
        $this->assertFalse($this->holidays()->isHoliday($employee, '2026-10-03'));
        $this->assertFalse($this->holidays()->isHoliday($employee, '2026-10-04'));

        $this->assignments()->declareOptional($employee, $optional, 'taken', '2026-10-04');

        $this->assertTrue($this->holidays()->isHoliday($employee, '2026-10-04'));

        try {
            $this->assignments()->declareOptional($employee, $public, 'taken', '2026-10-02');
            $this->fail('Declaring a public holiday must 422.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('holiday_id', $exception->errors());
        }
    }

    public function test_a_working_day_is_neither_a_week_off_nor_a_holiday(): void
    {
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, [5 => 1, 6 => 1]);
        $calendar = $this->makeCalendar();
        $this->makeHoliday($calendar, ['name' => 'Founding Day', 'date' => '2026-10-02', 'type' => 'public']);
        $this->assignments()->assign($employee, $calendar, '2026-01-01');

        // 2026-10-02 is a Friday: a working day until the holiday lands it.
        $this->assertTrue($this->holidays()->isWorkingDay($employee, '2026-10-05'));
        $this->assertFalse($this->holidays()->isWorkingDay($employee, '2026-10-02'));

        // 2026-10-03 is a Saturday: rostered off regardless of holidays.
        $this->assertFalse($this->holidays()->isWorkingDay($employee, '2026-10-03'));
    }

    public function test_assignments_refuse_overlap_and_unassign_removes(): void
    {
        $employee = $this->makeEmployee();
        $calendar = $this->makeCalendar();

        $assignment = $this->assignments()->assign($employee, $calendar, '2026-01-01', '2026-06-30');

        $this->assertTrue(HrmsAuditLog::query()->where('action', 'holiday.assigned')->exists());

        try {
            $this->assignments()->assign($employee, $calendar, '2026-05-01', '2026-12-31');
            $this->fail('An overlapping window must 422.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }

        // Adjacent windows are fine: history chains, it does not overlap.
        $this->assignments()->assign($employee, $calendar, '2026-07-01');

        $this->assignments()->unassign($assignment);

        $this->assertSame(1, EmployeeHolidayCalendar::query()->where('employee_id', $employee->id)->count());
    }

    public function test_assigning_an_inactive_calendar_is_refused(): void
    {
        $employee = $this->makeEmployee();
        $calendar = $this->makeCalendar(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->assignments()->assign($employee, $calendar, '2026-01-01');
    }

    // ------------------------------------------------------------ helpers

    private function holidays(): HolidayService
    {
        return app(HolidayService::class);
    }

    private function seeder(): HolidayYearSeeder
    {
        return app(HolidayYearSeeder::class);
    }

    private function assignments(): HolidayAssignments
    {
        return app(HolidayAssignments::class);
    }

    private function makeEmployee(string $name = 'Holiday Person'): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-HOL-'.$sequence,
            'name' => "{$name} {$sequence}",
            'status' => EmployeeStatus::Active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeCalendar(array $overrides = []): HolidayCalendar
    {
        static $sequence = 0;

        $sequence++;

        return HolidayCalendar::create([
            'name' => "Test Calendar {$sequence}",
            'slug' => "test-calendar-{$sequence}",
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeHoliday(HolidayCalendar $calendar, array $overrides = []): Holiday
    {
        return Holiday::create([
            'calendar_id' => $calendar->id,
            'name' => 'Test Holiday',
            'date' => '2026-10-02',
            ...$overrides,
        ]);
    }

    /**
     * @param  array<int, int>  $weeklyOffs  Zero-based Monday-first flags.
     */
    private function rosterFor(Employee $employee, array $weeklyOffs = []): void
    {
        static $sequence = 0;

        $sequence++;

        $offs = array_fill(0, 7, 0);

        foreach ($weeklyOffs as $index => $flag) {
            $offs[$index] = $flag;
        }

        $shift = AttendanceShift::create([
            'name' => "Holiday Shift {$sequence}",
            'code' => "holiday-shift-{$sequence}",
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'position' => $sequence * 10,
        ]);

        AttendanceRoster::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => Carbon::today()->subDays(90)->toDateString(),
            'weekly_offs' => $offs,
        ]);
    }
}
