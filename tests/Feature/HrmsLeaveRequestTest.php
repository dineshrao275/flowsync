<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\LeaveAdjustmentKind;
use App\Enums\Hrms\LeaveRequestStatus;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\User;
use App\Services\Hrms\AttendanceService;
use App\Services\Hrms\Leave\LeaveRequestDecisions;
use App\Services\Hrms\Leave\LeaveRequestService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P6.2c — the ask and its answer.
 *
 * Asks hold balance but post nothing; approval posts the availed row,
 * rebuilds, and flips the attendance days to `leave` through regeneration
 * plus the merge. Cancellation reverses with an offsetting row (the ledger
 * stays append-only); encashment converts posted time into the payable row
 * payroll picks up. Rejection posts nothing at all.
 */
class HrmsLeaveRequestTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_request_splits_days_opens_a_manager_step_and_posts_nothing(): void
    {
        [$manager, $report] = $this->reportingLine();
        $type = $this->makeType();
        $this->ledger($report, $type, 'opening', 12);

        $request = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(10),
            'to_date' => $this->daysAgo(8),
            'reason' => 'A short break.',
        ], $report->user);

        $this->assertSame(LeaveRequestStatus::Submitted, $request->status);
        $this->assertSame(3.0, (float) $request->total_days);
        $this->assertCount(3, $request->days);
        $this->assertSame($manager->user->id, (int) $request->approval->steps()->firstWhere('step_order', 1)->approver_user_id);

        // Submitted holds, never posts: only the opening row exists, and the
        // projection the availability check materialized still carries 12.
        $this->assertSame(0, LeaveAdjustment::query()->where('kind', LeaveAdjustmentKind::Availed->value)->count());
        $this->assertSame(12.0, (float) LeaveBalance::query()->firstOrFail()->balance);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'leave.requested')->exists());
    }

    public function test_weekends_leave_the_table_but_not_the_total(): void
    {
        [, $report] = $this->reportingLine();
        $type = $this->makeType();
        $this->ledger($report, $type, 'opening', 12);

        // Saturday to the following Tuesday, with Sat/Sun rostered off: the
        // record keeps four rows, the balance sees two days.
        $saturday = Carbon::parse('last Saturday')->toDateString();
        $tuesday = Carbon::parse($saturday)->addDays(3)->toDateString();
        $this->rosterFor($report, [5 => 1, 6 => 1]);

        $request = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $saturday,
            'to_date' => $tuesday,
            'reason' => 'A long weekend, honestly charged.',
        ]);

        $this->assertSame(2.0, (float) $request->total_days);
        $this->assertCount(4, $request->days()->get());
        $this->assertSame(2, $request->days()->where('is_week_off', true)->count());
    }

    public function test_half_days_need_a_type_that_allows_them(): void
    {
        [, $report] = $this->reportingLine();
        $half = $this->makeType(['allow_half_day' => true]);
        $whole = $this->makeType(['allow_half_day' => false]);
        $this->ledger($report, $half, 'opening', 12);
        $this->ledger($report, $whole, 'opening', 12);
        $day = $this->daysAgo(9);

        $request = $this->requests()->request($report, [
            'leave_type_id' => $half->id,
            'from_date' => $day,
            'to_date' => $day,
            'from_half' => 'first_half',
            'reason' => 'Dentist.',
        ]);

        $this->assertSame(0.5, (float) $request->total_days);
        $this->assertTrue($request->days()->firstOrFail()->is_half_day);

        try {
            $this->requests()->request($report, [
                'leave_type_id' => $whole->id,
                'from_date' => $day,
                'to_date' => $day,
                'from_half' => 'first_half',
                'reason' => 'Dentist.',
            ]);
            $this->fail('A half day on a whole-day type must 422.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('from_half', $exception->errors());
        }
    }

    public function test_an_overlap_with_any_live_ask_is_refused(): void
    {
        [, $report] = $this->reportingLine();
        $annual = $this->makeType();
        $sick = $this->makeType();
        $this->ledger($report, $annual, 'opening', 12);
        $this->ledger($report, $sick, 'opening', 12);

        $this->requests()->request($report, [
            'leave_type_id' => $annual->id,
            'from_date' => $this->daysAgo(10),
            'to_date' => $this->daysAgo(8),
            'reason' => 'First.',
        ]);

        try {
            $this->requests()->request($report, [
                'leave_type_id' => $sick->id,
                'from_date' => $this->daysAgo(9),
                'to_date' => $this->daysAgo(7),
                'reason' => 'Overlapping, other type.',
            ]);
            $this->fail('An overlapping ask must 422 whatever its type.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }
    }

    public function test_a_shortfall_names_the_number(): void
    {
        [, $report] = $this->reportingLine();
        $type = $this->makeType();

        try {
            $this->requests()->request($report, [
                'leave_type_id' => $type->id,
                'from_date' => $this->daysAgo(10),
                'to_date' => $this->daysAgo(9),
                'reason' => 'Nothing banked.',
            ]);
            $this->fail('An unfunded ask must 422.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Available 0.00 days, short by 2.00.'],
                $exception->errors()['form'],
            );
        }
    }

    public function test_minimum_and_yearly_maximum_are_enforced(): void
    {
        [, $report] = $this->reportingLine();
        $picky = $this->makeType(['min_days_per_request' => 3]);
        $capped = $this->makeType(['max_days_per_year' => 5]);
        $this->ledger($report, $picky, 'opening', 12);
        $this->ledger($report, $capped, 'opening', 12);

        try {
            $this->requests()->request($report, [
                'leave_type_id' => $picky->id,
                'from_date' => $this->daysAgo(10),
                'to_date' => $this->daysAgo(9),
                'reason' => 'Too short.',
            ]);
            $this->fail('Under-minimum must 422.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }

        $this->requests()->request($report, [
            'leave_type_id' => $capped->id,
            'from_date' => $this->daysAgo(20),
            'to_date' => $this->daysAgo(17),
            'reason' => 'Four days held.',
        ]);

        try {
            $this->requests()->request($report, [
                'leave_type_id' => $capped->id,
                'from_date' => $this->daysAgo(10),
                'to_date' => $this->daysAgo(9),
                'reason' => 'Over the yearly cap.',
            ]);
            $this->fail('Over-maximum must 422.');
        } catch (ValidationException $exception) {
            // 4 held + 2 asked > 5 allowed.
            $this->assertArrayHasKey('form', $exception->errors());
        }
    }

    public function test_approval_posts_rebuilds_and_flips_the_attendance_day(): void
    {
        [$manager, $report] = $this->reportingLine();
        $type = $this->makeType();
        $this->ledger($report, $type, 'opening', 5);
        $day = $this->daysAgo(6);

        $request = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $day,
            'to_date' => $day,
            'reason' => 'One day.',
        ], $report->user);

        $decided = $this->decisions()->approve($request, $manager->user, 'Enjoy.');

        $this->assertSame(LeaveRequestStatus::Approved, $decided->status);

        $availed = LeaveAdjustment::query()->where('kind', LeaveAdjustmentKind::Availed->value)->firstOrFail();

        $this->assertSame(-1.0, (float) $availed->quantity);
        $this->assertSame($request->id, $availed->reference_id);
        $this->assertSame(4.0, (float) LeaveBalance::query()->firstOrFail()->balance);

        // The stored row photographs `absent`; the merged read says `leave`.
        $this->assertSame('leave', app(AttendanceService::class)->dayStatus($report, $day)->value);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'leave.approved')->exists());
    }

    public function test_a_stranger_cannot_approve(): void
    {
        [$manager, $report] = $this->reportingLine();
        $type = $this->makeType();
        $this->ledger($report, $type, 'opening', 12);

        $request = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(10),
            'to_date' => $this->daysAgo(9),
            'reason' => 'Two days.',
        ]);

        $this->expectException(ValidationException::class);

        $this->decisions()->approve($request, $this->makeUser(), 'Mine now.');
    }

    public function test_rejection_needs_a_reason_and_posts_nothing(): void
    {
        [$manager, $report] = $this->reportingLine();
        $type = $this->makeType();
        $this->ledger($report, $type, 'opening', 12);

        $request = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(10),
            'to_date' => $this->daysAgo(9),
            'reason' => 'Two days.',
        ]);

        try {
            $this->decisions()->reject($request, $manager->user, '  ');
            $this->fail('A reasonless rejection must 422.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision_note', $exception->errors());
        }

        $rejected = $this->decisions()->reject($request, $manager->user, 'Blackout week.');

        $this->assertSame(LeaveRequestStatus::Rejected, $rejected->status);
        $this->assertSame(0, LeaveAdjustment::query()->where('kind', LeaveAdjustmentKind::Availed->value)->count());
    }

    public function test_cancel_pending_releases_while_cancel_approved_reverses(): void
    {
        [$manager, $report] = $this->reportingLine();
        $type = $this->makeType();
        $this->ledger($report, $type, 'opening', 5);
        $day = $this->daysAgo(6);

        $pending = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(12),
            'to_date' => $this->daysAgo(11),
            'reason' => 'Changed my mind.',
        ], $report->user);

        $cancelled = $this->decisions()->cancelRequest($pending, $report->user, 'No longer needed.');

        $this->assertSame(LeaveRequestStatus::Cancelled, $cancelled->status);
        $this->assertSame('No longer needed.', $cancelled->cancel_reason);
        $this->assertSame(0, LeaveAdjustment::query()->where('kind', LeaveAdjustmentKind::Availed->value)->count());

        $ask = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $day,
            'to_date' => $day,
            'reason' => 'One day.',
        ], $report->user);

        $this->decisions()->approve($ask, $manager->user);
        $this->assertSame(4.0, (float) LeaveBalance::query()->firstOrFail()->balance);

        $undone = $this->decisions()->cancelRequest($ask->refresh(), $report->user, 'Coming in after all.');

        $this->assertSame(LeaveRequestStatus::Cancelled, $undone->status);
        $this->assertSame(5.0, (float) LeaveBalance::query()->firstOrFail()->balance);

        // The availed row stays; an offsetting row returns the time.
        $this->assertSame(3, LeaveAdjustment::query()->count());

        // Cancelled asks leave the merge, so the day photographs absent again.
        $this->assertSame('absent', app(AttendanceService::class)->dayStatus($report, $day)->value);
    }

    public function test_cancel_by_a_stranger_is_refused_by_the_engine(): void
    {
        [, $report] = $this->reportingLine();
        $type = $this->makeType();
        $this->ledger($report, $type, 'opening', 12);

        $request = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(10),
            'to_date' => $this->daysAgo(9),
            'reason' => 'Two days.',
        ], $report->user);

        // The chain is open and the actor is not the requester: only the
        // requester may cancel; HR stops an ask by rejecting it.
        $this->expectException(ValidationException::class);

        $this->decisions()->cancelRequest($request, $this->makeUser());
    }

    public function test_encash_converts_posted_time_into_a_payable(): void
    {
        [$manager, $report] = $this->reportingLine();
        $type = $this->makeType(['encashable' => true]);
        $strict = $this->makeType(['encashable' => false]);
        $this->ledger($report, $type, 'opening', 5);
        $this->ledger($report, $strict, 'opening', 5);

        $pending = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(10),
            'to_date' => $this->daysAgo(9),
            'reason' => 'Not taken yet.',
        ]);

        try {
            $this->decisions()->encash($pending);
            $this->fail('Only approved leave encashes.');
        } catch (ValidationException) {
            // Expected.
        }

        $unencashable = $this->requests()->request($report, [
            'leave_type_id' => $strict->id,
            'from_date' => $this->daysAgo(14),
            'to_date' => $this->daysAgo(13),
            'reason' => 'Also two days.',
        ], $report->user);

        $this->decisions()->approve($unencashable, $manager->user);

        try {
            $this->decisions()->encash($unencashable->refresh());
            $this->fail('A non-encashable type must 422.');
        } catch (ValidationException) {
            // Expected.
        }

        $ask = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(6),
            'to_date' => $this->daysAgo(6),
            'reason' => 'One payable day.',
        ], $report->user);

        $this->decisions()->approve($ask, $manager->user);
        $this->assertSame(4.0, (float) LeaveBalance::query()->where('leave_type_id', $type->id)->firstOrFail()->balance);

        $this->decisions()->encash($ask->refresh(), $manager->user);

        // Reversal (+1) plus encashment (−1): the balance stays at 4 — the
        // day left as cash, not as time — and the encashed row is the
        // payable P9 picks up by reference.
        $this->assertSame(4.0, (float) LeaveBalance::query()->where('leave_type_id', $type->id)->firstOrFail()->balance);

        $payable = LeaveAdjustment::query()
            ->where('kind', LeaveAdjustmentKind::Encashment->value)
            ->firstOrFail();

        $this->assertSame(-1.0, (float) $payable->quantity);
        $this->assertSame('leave_request', $payable->reference_type);
        $this->assertSame($ask->id, $payable->reference_id);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'leave.encashed')->exists());
    }

    // ------------------------------------------------------------ helpers

    private function requests(): LeaveRequestService
    {
        return app(LeaveRequestService::class);
    }

    private function decisions(): LeaveRequestDecisions
    {
        return app(LeaveRequestDecisions::class);
    }

    /**
     * A manager with a login and one report with a login.
     *
     * @return array{Employee, Employee} Each carrying `->user`.
     */
    private function reportingLine(): array
    {
        $managerUser = $this->makeUser();
        $manager = $this->makeEmployee('Leave Manager', ['user_id' => $managerUser->id]);
        $manager->user = $managerUser;

        $reportUser = $this->makeUser();
        $report = $this->makeEmployee('Leave Report', ['user_id' => $reportUser->id, 'manager_id' => $manager->id]);
        $report->user = $reportUser;

        return [$manager, $report];
    }

    private function daysAgo(int $days): string
    {
        return Carbon::today()->subDays($days)->toDateString();
    }

    private function makeUser(): User
    {
        static $sequence = 0;

        $sequence++;

        return User::create([
            'name' => "Leave User {$sequence}",
            'email' => "leave.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);
    }

    private function makeEmployee(string $name, array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-LVR-'.$sequence,
            'name' => $name,
            'status' => EmployeeStatus::Active,
            ...$overrides,
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
            'name' => "Request Type {$sequence}",
            'slug' => "request-type-{$sequence}",
            ...$overrides,
        ]);
    }

    private function ledger(Employee $employee, LeaveType $type, string $kind, float $quantity): void
    {
        LeaveAdjustment::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => 2026,
            'kind' => $kind,
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
            'name' => "Leave Shift {$sequence}",
            'code' => "leave-shift-{$sequence}",
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'position' => $sequence * 10,
        ]);

        AttendanceRoster::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => Carbon::today()->subDays(30)->toDateString(),
            'weekly_offs' => $offs,
        ]);
    }
}
