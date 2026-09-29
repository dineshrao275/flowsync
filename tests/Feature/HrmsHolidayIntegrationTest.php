<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\CompOff\CompOffCredit;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveType;
use App\Services\Hrms\AttendanceService;
use App\Services\Hrms\CompOff\CompOffCredits;
use App\Services\Hrms\CompOff\CompOffService;
use App\Services\Hrms\Holiday\HolidayAssignments;
use App\Services\Hrms\Leave\LeaveRequestService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P8.3 — one source of non-working days, three readers.
 *
 * Gandhi Jayanti (Friday 2026-10-02, public on the seeded IN calendar) is
 * the probe: the leave split leaves it out of the charged total, the
 * attendance merge reads it as `holiday`, the comp-off accrual banks it,
 * and redemption refuses to spend on it. Each reader goes through
 * `HolidayService`, never around it.
 */
class HrmsHolidayIntegrationTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_public_holiday_leaves_the_leave_total(): void
    {
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, [5 => 1, 6 => 1]);
        $this->assignInCalendar($employee);
        $type = $this->makeType();
        $this->ledger($employee, $type, 12);

        // Thu Oct 1 (work) + Fri Oct 2 (public holiday) + Sat Oct 3
        // (week-off): only Thursday charges.
        $request = app(LeaveRequestService::class)->request($employee, [
            'leave_type_id' => $type->id,
            'from_date' => '2026-10-01',
            'to_date' => '2026-10-03',
            'reason' => 'Around the holiday.',
        ]);

        $this->assertSame(1.0, (float) $request->total_days);

        $flags = $request->days()->orderBy('date')->get()->mapWithKeys(
            fn ($day) => [$day->date->toDateString() => ['holiday' => $day->is_holiday, 'week_off' => $day->is_week_off]],
        )->all();

        $this->assertSame(['holiday' => false, 'week_off' => false], $flags['2026-10-01']);
        $this->assertSame(['holiday' => true, 'week_off' => false], $flags['2026-10-02']);
        $this->assertSame(['holiday' => false, 'week_off' => true], $flags['2026-10-03']);
    }

    public function test_a_public_holiday_reads_as_holiday_in_attendance(): void
    {
        $employee = $this->makeEmployee();
        $this->assignInCalendar($employee);

        $attendance = app(AttendanceService::class);

        // Labor Day (Tuesday 2026-09-01, public on the default US
        // calendar): a lived date, so the grid carries it too — the month
        // only ever returns dates that already happened.
        $this->assertSame('holiday', $attendance->dayStatus($employee, '2026-09-01')->value);

        // The summary counts stored rows, so materialize the day the way
        // the nightly rollup would before asking for totals.
        $attendance->regenerate($employee, '2026-09-01');

        $month = $attendance->month($employee, 2026, 9);
        $cells = collect($month['days'])->keyBy('date');

        $this->assertSame('holiday', $cells['2026-09-01']['status']->value);
        $this->assertSame(1, $month['summary']['holiday']);
    }

    public function test_a_weekday_public_holiday_banks_comp_off(): void
    {
        // No roster here: every weekday is working, so Friday's credit can
        // only come from the holiday leg — never the weekend one.
        $employee = $this->makeEmployee();
        $this->assignInCalendar($employee);

        $result = app(CompOffCredits::class)->creditFromCalendar($employee, '2026-10-01', '2026-10-03');

        $this->assertSame(['credited' => 1, 'skipped' => 2], $result);

        $credit = CompOffCredit::query()->firstOrFail();

        $this->assertSame('2026-10-02', $credit->work_date->toDateString());
        $this->assertSame('holiday', $credit->source_type->value);
    }

    public function test_redemption_refuses_to_spend_on_a_holiday(): void
    {
        $employee = $this->makeEmployee();
        $this->assignInCalendar($employee);
        app(CompOffCredits::class)->creditManual($employee, '2026-09-20', 960);

        // Fri Oct 2 (holiday) + Sat Oct 3 (working, no roster): only
        // Saturday charges.
        $ask = app(CompOffService::class)->request($employee, [
            'from_date' => '2026-10-02',
            'to_date' => '2026-10-03',
            'reason' => 'Around the holiday.',
        ]);

        $this->assertSame(480, $ask->total_minutes);
        $this->assertCount(1, $ask->days);
    }

    // ------------------------------------------------------------ helpers

    private function assignInCalendar(Employee $employee): void
    {
        $calendar = HolidayCalendar::query()->where('country', 'IN')->firstOrFail();

        app(HolidayAssignments::class)->assign($employee, $calendar, '2026-01-01');
    }

    private function makeEmployee(string $name = 'Holiday Reader'): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-HOI-'.$sequence,
            'name' => "{$name} {$sequence}",
            'status' => EmployeeStatus::Active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeType(array $overrides = []): LeaveType
    {
        static $sequence = 0;

        $sequence++;

        return LeaveType::create([
            'name' => "Holiday Leave {$sequence}",
            'slug' => "holiday-leave-{$sequence}",
            ...$overrides,
        ]);
    }

    private function ledger(Employee $employee, LeaveType $type, float $quantity): void
    {
        LeaveAdjustment::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => 2026,
            'kind' => 'opening',
            'quantity' => $quantity,
            'created_at' => now(),
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
            'name' => "Holiday Roster Shift {$sequence}",
            'code' => "holiday-roster-shift-{$sequence}",
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'position' => $sequence * 10,
        ]);

        AttendanceRoster::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'weekly_offs' => $offs,
        ]);
    }
}
