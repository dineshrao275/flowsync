<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Attendance\AttendanceIpRule;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Org\Location;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Services\Hrms\AttendanceService;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.2 — the punch service and the day computation.
 *
 * The migration’s contract is covered in `HrmsAttendanceTablesTest`. What is
 * worth protecting *here* is the math and the admission rules: pairing,
 * break subtraction, late/early/overtime against the settings rounding,
 * the duplicate and window refusals, the flag-never-block range checks, and
 * the weekly-off merge. All times are UTC — the seeded tenant zone — so the
 * expectations below are exact rather than zone-dependent.
 */
class HrmsAttendanceServiceTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_full_day_computes_present_with_break_subtracted(): void
    {
        $service = app(AttendanceService::class);
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, $this->makeShift(['break_minutes' => 60]));

        $service->punch($employee, 'in', 'web', ['punch_at' => '2026-10-06 09:00:00']);
        $service->punch($employee, 'out', 'web', ['punch_at' => '2026-10-06 18:00:00']);

        $day = $this->day($employee, '2026-10-06');

        // Nine hours on the clock, one for lunch: eight counted.
        $this->assertSame('present', $day->status->value);
        $this->assertSame(480, $day->worked_minutes);
        $this->assertSame(60, $day->break_minutes);
        $this->assertSame(0, $day->late_by_minutes);
        $this->assertSame('2026-10-06 09:00:00', $day->first_in_at->toDateTimeString());
        $this->assertSame('2026-10-06 18:00:00', $day->last_out_at->toDateTimeString());
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'attendance.punched')->exists());
    }

    public function test_lateness_rounds_down_to_the_settings_step(): void
    {
        $service = app(AttendanceService::class);
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, $this->makeShift(['grace_minutes' => 15, 'break_minutes' => 0]));

        // Twenty minutes past the 09:00 start, five past the 09:15 grace:
        // five late minutes floor to zero on a 15-minute rounding step.
        $service->punch($employee, 'in', 'web', ['punch_at' => '2026-10-06 09:20:00']);
        $service->punch($employee, 'out', 'web', ['punch_at' => '2026-10-06 18:00:00']);

        $day = $this->day($employee, '2026-10-06');

        $this->assertSame(0, $day->late_by_minutes);
        $this->assertSame('present', $day->status->value);

        $late = $this->makeEmployee();
        $this->rosterFor($late, $this->makeShift(['grace_minutes' => 15, 'break_minutes' => 0]));

        // Thirty-five past the hour is twenty past grace: fifteen counted.
        $service->punch($late, 'in', 'web', ['punch_at' => '2026-10-06 09:35:00']);
        $service->punch($late, 'out', 'web', ['punch_at' => '2026-10-06 18:00:00']);

        $day = $this->day($late, '2026-10-06');

        $this->assertSame(15, $day->late_by_minutes);
        $this->assertSame('late', $day->status->value);
    }

    public function test_early_departure_and_overtime(): void
    {
        $service = app(AttendanceService::class);
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, $this->makeShift(['break_minutes' => 0]));

        $service->punch($employee, 'in', 'web', ['punch_at' => '2026-10-06 09:00:00']);
        $service->punch($employee, 'out', 'web', ['punch_at' => '2026-10-06 17:30:00']);

        $this->assertSame(30, $this->day($employee, '2026-10-06')->early_by_minutes);

        $nightOwl = $this->makeEmployee();
        $this->rosterFor($nightOwl, $this->makeShift(['break_minutes' => 0]));

        $service->punch($nightOwl, 'in', 'web', ['punch_at' => '2026-10-06 09:00:00']);
        $service->punch($nightOwl, 'out', 'web', ['punch_at' => '2026-10-06 20:00:00']);

        $day = $this->day($nightOwl, '2026-10-06');

        // Eleven hours on the clock, less the eight-hour threshold: three.
        $this->assertSame(180, $day->overtime_minutes);
        $this->assertSame('present', $day->status->value);
    }

    public function test_a_short_day_is_a_half_day_not_an_absence(): void
    {
        $service = app(AttendanceService::class);
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, $this->makeShift(['break_minutes' => 0]));

        $service->punch($employee, 'in', 'web', ['punch_at' => '2026-10-06 09:00:00']);
        $service->punch($employee, 'out', 'web', ['punch_at' => '2026-10-06 12:00:00']);

        $day = $this->day($employee, '2026-10-06');

        // Three hours with punches beside it: the person showed up, and
        // `absent` next to punch rows would be two answers to one question.
        $this->assertSame('half_day', $day->status->value);
        $this->assertSame(180, $day->worked_minutes);
    }

    public function test_no_punches_means_absent(): void
    {
        $day = app(AttendanceService::class)->computeDay($this->makeEmployee(), '2026-10-06');

        $this->assertSame('absent', $day->status->value);
        $this->assertNull($day->first_in_at);
    }

    public function test_a_duplicate_press_is_refused_not_recorded_twice(): void
    {
        $service = app(AttendanceService::class);
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, $this->makeShift());

        $service->punch($employee, 'in', 'web', ['punch_at' => '2026-10-06 09:00:00']);

        try {
            $service->punch($employee, 'in', 'web', ['punch_at' => '2026-10-06 09:02:00']);
            $this->fail('A double-tap must be refused, not stored as a second session.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('punch_at', $exception->errors());
        }

        $this->assertSame(1, $employee->fresh()->attendancePunches()->count());
    }

    public function test_a_punch_outside_shift_hours_is_refused(): void
    {
        $service = app(AttendanceService::class);
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, $this->makeShift(['grace_minutes' => 15]));

        try {
            $service->punch($employee, 'in', 'web', ['punch_at' => '2026-10-06 03:00:00']);
            $this->fail('A 3am punch against a day shift must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('punch_at', $exception->errors());
        }
    }

    public function test_a_night_shift_pairs_across_midnight(): void
    {
        $service = app(AttendanceService::class);
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, $this->makeShift([
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'is_night' => true,
            'break_minutes' => 0,
        ]));

        $service->punch($employee, 'in', 'web', ['punch_at' => '2026-10-06 22:00:00']);
        $service->punch($employee, 'out', 'web', ['punch_at' => '2026-10-07 06:00:00']);

        $day = $this->day($employee, '2026-10-06');

        // The 05:00–06:00 hour belongs to the night it closed, not to the
        // morning it happens to fall in.
        $this->assertSame(480, $day->worked_minutes);
        $this->assertSame('present', $day->status->value);
    }

    public function test_an_out_of_range_punch_is_flagged_never_blocked(): void
    {
        $service = app(AttendanceService::class);
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, $this->makeShift());

        AttendanceIpRule::create(['cidr' => '10.0.0.0/8', 'label' => 'Office']);

        $flagged = $service->punch($employee, 'in', 'web', [
            'punch_at' => '2026-10-06 09:00:00',
            'ip' => '192.168.1.1',
        ]);

        $this->assertTrue($flagged->is_out_of_range);
        $this->assertStringContainsString('192.168.1.1', $flagged->out_of_range_reason);

        $clean = $service->punch($employee, 'out', 'web', [
            'punch_at' => '2026-10-06 18:00:00',
            'ip' => '10.1.2.3',
        ]);

        $this->assertFalse($clean->is_out_of_range);
    }

    public function test_a_punch_far_from_the_fence_is_flagged(): void
    {
        $service = app(AttendanceService::class);
        $location = Location::create([
            'name' => 'Fenced Site',
            'slug' => 'fenced-site',
            'geo_lat' => '12.9716',
            'geo_lng' => '77.5946',
            'geo_radius_m' => 200,
            'is_geo_fenced' => true,
            'is_active' => true,
            'position' => 10,
        ]);
        $employee = $this->makeEmployee(['location_id' => $location->id]);
        $this->rosterFor($employee, $this->makeShift());

        // Chennai is roughly 290 km from Bengaluru: nowhere near a 200 m fence.
        $flagged = $service->punch($employee, 'in', 'web', [
            'punch_at' => '2026-10-06 09:00:00',
            'lat' => 13.0827,
            'lng' => 80.2707,
        ]);

        $this->assertTrue($flagged->is_out_of_range);
        $this->assertStringContainsString('m outside', $flagged->out_of_range_reason);

        // And a punch with no coordinates cannot be placed, so it is not
        // flagged: absence of evidence, switched off by the same logic that
        // refuses to block on a maybe.
        $unplaced = $service->punch($employee, 'out', 'web', ['punch_at' => '2026-10-06 18:00:00']);

        $this->assertFalse($unplaced->is_out_of_range);
    }

    public function test_a_weekly_off_merges_but_punches_win(): void
    {
        $service = app(AttendanceService::class);
        $employee = $this->makeEmployee();
        // Sunday off: index 6 of the Monday-first array.
        $this->rosterFor($employee, $this->makeShift(), ['weekly_offs' => [0, 0, 0, 0, 0, 0, 1]]);

        $this->assertSame('week_off', $service->dayStatus($employee, '2026-10-11')->value);

        $service->punch($employee, 'in', 'web', ['punch_at' => '2026-10-11 09:00:00']);
        $service->punch($employee, 'out', 'web', ['punch_at' => '2026-10-11 18:00:00']);

        // Someone who clocked in on a Sunday worked, whatever the roster says.
        $this->assertSame('present', $service->dayStatus($employee, '2026-10-11')->value);
    }

    public function test_regenerate_picks_up_a_roster_change(): void
    {
        $service = app(AttendanceService::class);
        $employee = $this->makeEmployee();
        $morning = $this->makeShift(['start_time' => '09:00:00', 'end_time' => '18:00:00', 'break_minutes' => 0]);
        $evening = $this->makeShift(['start_time' => '14:00:00', 'end_time' => '22:00:00', 'break_minutes' => 0]);
        $roster = $this->rosterFor($employee, $morning);

        $service->punch($employee, 'in', 'web', ['punch_at' => '2026-10-06 09:30:00']);
        $service->punch($employee, 'out', 'web', ['punch_at' => '2026-10-06 18:00:00']);

        // Against the morning shift this is a half-hour-late arrival with a
        // full day behind it.
        $this->assertSame('late', $this->day($employee, '2026-10-06')->status->value);

        $roster->update(['shift_id' => $evening->id]);
        $day = $service->regenerate($employee, '2026-10-06');

        // Same punches, evening shift: the 09:30 arrival predates the shift
        // entirely, so nothing is late — and the row now names the new shift.
        // Regenerate deliberately does not re-validate the window: the
        // punches were legal when recorded, and a roster edit must not
        // retroactively criminalise them.
        $this->assertSame('present', $day->status->value);
        $this->assertSame($evening->id, $day->shift_id);
    }

    public function test_summary_totals_a_range(): void
    {
        $service = app(AttendanceService::class);
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, $this->makeShift(['break_minutes' => 0]));

        $service->punch($employee, 'in', 'web', ['punch_at' => '2026-10-06 09:00:00']);
        $service->punch($employee, 'out', 'web', ['punch_at' => '2026-10-06 18:00:00']);
        $service->computeDay($employee, '2026-10-07');

        $summary = $service->summary($employee, '2026-10-06', '2026-10-07');

        $this->assertSame([
            'days' => 2, 'present' => 1, 'absent' => 1, 'half_day' => 0, 'late' => 0,
            'leave' => 0, 'holiday' => 0, 'week_off' => 0,
            'worked_minutes' => 540, 'late_minutes' => 0, 'overtime_minutes' => 60,
        ], $summary);
    }

    // ------------------------------------------------------------ helpers

    private function makeEmployee(array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-ATT-'.$sequence,
            'name' => "Attendance Person {$sequence}",
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }

    private function makeShift(array $overrides = []): AttendanceShift
    {
        static $sequence = 0;

        $sequence++;

        return AttendanceShift::create([
            'name' => "Test Shift {$sequence}",
            'code' => "test-shift-{$sequence}",
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'position' => $sequence * 10,
            ...$overrides,
        ]);
    }

    private function rosterFor(Employee $employee, AttendanceShift $shift, array $overrides = []): AttendanceRoster
    {
        return AttendanceRoster::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-10-01',
            ...$overrides,
        ]);
    }

    private function day(Employee $employee, string $date): AttendanceDay
    {
        return AttendanceDay::where('employee_id', $employee->id)->whereDate('work_date', $date)->firstOrFail();
    }
}
