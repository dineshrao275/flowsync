<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Services\Hrms\AttendanceService;
use App\Services\Hrms\Leave\LeaveCalendar;
use Illuminate\Support\Carbon;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P9.3a — the payroll engine's read edges.
 *
 * Rosters preload per window (never per date), absence splits punch-less
 * rows by status with overtime summed, charged leave splits paid from
 * unpaid, and the payroll settings section exists with its default rate.
 * The engine (P9.3b) composes these; this pins each one alone.
 */
class HrmsPayrollReadsTest extends TestCase
{
    use IsolatesDatabase;

    public function test_weekly_off_dates_come_from_preloaded_rosters(): void
    {
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, [5 => 1, 6 => 1]);

        $days = app(AttendanceService::class)->weeklyOffDates($employee, '2026-10-01', '2026-10-07');

        // Sat Oct 3 + Sun Oct 4 only; weekdays are working.
        $this->assertSame(['2026-10-03', '2026-10-04'], $days);
    }

    public function test_absence_detail_splits_unpunched_rows_and_sums_overtime(): void
    {
        $employee = $this->makeEmployee();
        $this->day($employee, '2026-10-06', ['status' => 'absent']);
        $this->day($employee, '2026-10-07', ['status' => 'half_day']);
        $this->day($employee, '2026-10-08', ['status' => 'present', 'first_in_at' => '2026-10-08 09:00:00', 'overtime_minutes' => 90]);

        $detail = app(AttendanceService::class)->absenceDetail($employee, '2026-10-06', '2026-10-08');

        $this->assertSame(['2026-10-06'], $detail['absent']);
        $this->assertSame(['2026-10-07'], $detail['half']);
        $this->assertSame(90, $detail['overtime_minutes']);
    }

    public function test_charged_leave_splits_paid_from_unpaid(): void
    {
        $employee = $this->makeEmployee();
        $paid = $this->makeType(['is_paid' => true]);
        $unpaid = $this->makeType(['is_paid' => false]);

        $this->ask($employee, $paid, '2026-10-06', '2026-10-07');
        $this->ask($employee, $unpaid, '2026-10-08', '2026-10-08');

        $split = app(LeaveCalendar::class)->chargedLeaveDays($employee, '2026-10-06', '2026-10-08');

        $this->assertSame(['2026-10-06', '2026-10-07', '2026-10-08'], $split['dates']);
        $this->assertSame(2.0, $split['paid_days']);
        $this->assertSame(1.0, $split['unpaid_days']);
    }

    public function test_the_payroll_settings_section_has_its_default_rate(): void
    {
        $this->assertSame(1.0, (float) HrmsSetting::current()->setting('payroll.ot_rate'));
    }

    // ------------------------------------------------------------ helpers

    private function makeEmployee(string $name = 'Payroll Reader'): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-PRD-'.$sequence,
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
            'name' => "Reads Type {$sequence}",
            'slug' => "reads-type-{$sequence}",
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function day(Employee $employee, string $date, array $overrides = []): void
    {
        AttendanceDay::create([
            'employee_id' => $employee->id,
            'work_date' => $date,
            ...$overrides,
        ]);
    }

    private function ask(Employee $employee, LeaveType $type, string $from, string $to): void
    {
        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => $from,
            'to_date' => $to,
            'total_days' => 1,
            'reason' => 'Fixture.',
            'status' => 'approved',
        ]);

        foreach ([...$this->dates($from, $to)] as $date) {
            $request->days()->create(['date' => $date, 'is_holiday' => false, 'is_week_off' => false, 'is_half_day' => false]);
        }
    }

    /**
     * @return list<string>
     */
    private function dates(string $from, string $to): array
    {
        $dates = [];

        for ($date = Carbon::parse($from); $date->lessThanOrEqualTo(Carbon::parse($to)); $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        return $dates;
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
            'name' => "Reads Shift {$sequence}",
            'code' => "reads-shift-{$sequence}",
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
