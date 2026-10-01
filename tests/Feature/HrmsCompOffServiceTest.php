<?php

namespace Tests\Feature;

use App\Enums\Hrms\CompOffSource;
use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Attendance\AttendancePunch;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\CompOff\CompOffCredit;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Hrms\CompOff\CompOffCredits;
use App\Services\Hrms\CompOff\CompOffService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P7.2 — banking rest days and spending them back.
 *
 * A month of rostered weekends credits idempotently; presence and approved
 * leave disqualify; expiry drops credits out of the derived balance; and
 * redemption over balance 422s with the shortfall in minutes. The accrue
 * command walks tenants with the same loop behind a dry run.
 */
class HrmsCompOffServiceTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_month_of_weekends_credits_once_no_matter_the_reruns(): void
    {
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, [5 => 1, 6 => 1]);
        [$from, $to] = $this->lastMonth();

        // Weekends only: a holiday in the window (Labor Day in September)
        // would credit through no fault of the weekend logic, and the
        // calendar flips under this test every October.
        $this->setCompOff(['from_holidays' => false]);

        $first = $this->credits()->creditFromCalendar($employee, $from, $to);
        $expected = $this->weekendCount($from, $to);

        $this->assertSame($expected, $first['credited']);
        $this->assertSame($expected, CompOffCredit::query()->count());

        $second = $this->credits()->creditFromCalendar($employee, $from, $to);

        $this->assertSame(0, $second['credited']);
        $this->assertSame($expected, CompOffCredit::query()->count());
        $this->assertTrue(CompOffCredit::query()->where('minutes', 480)->exists());
    }

    public function test_presence_and_approved_leave_disqualify(): void
    {
        $worker = $this->makeEmployee('Weekend Worker');
        $idler = $this->makeEmployee('Weekend Idler');
        $this->rosterFor($worker, [5 => 1, 6 => 1]);
        $this->rosterFor($idler, [5 => 1, 6 => 1]);
        [$from, $to] = $this->lastMonth();

        // The disqualification math below counts weekends; holidays bank
        // through a different rule and would move every expected number
        // in any month that holds one.
        $this->setCompOff(['from_holidays' => false]);

        $saturday = $this->firstSaturday($from, $to);

        // Worked Saturday stays in attendance: punches disqualify the credit.
        AttendancePunch::create([
            'employee_id' => $worker->id,
            'punch_at' => "{$saturday} 09:00:00",
            'direction' => 'in',
            'source' => 'web',
            'is_out_of_range' => false,
        ]);
        AttendancePunch::create([
            'employee_id' => $worker->id,
            'punch_at' => "{$saturday} 18:00:00",
            'direction' => 'out',
            'source' => 'web',
            'is_out_of_range' => false,
        ]);

        // Leave on a rest day was never worked either. Approved directly —
        // the merge reads rows, not chains, and the service path would
        // strand the ask at HR with nobody to approve it here.
        $type = $this->makeType();
        $this->ledger($idler, $type, 30);

        $ask = LeaveRequest::create([
            'employee_id' => $idler->id,
            'leave_type_id' => $type->id,
            'from_date' => $saturday,
            'to_date' => $saturday,
            'total_days' => 1,
            'reason' => 'Saturday off, properly.',
            'status' => 'approved',
        ]);
        $ask->days()->create(['date' => $saturday, 'is_holiday' => false, 'is_week_off' => false, 'is_half_day' => false]);

        $this->credits()->creditFromCalendar($worker, $from, $to);
        $this->credits()->creditFromCalendar($idler, $from, $to);

        $expected = $this->weekendCount($from, $to);

        $this->assertSame($expected - 1, CompOffCredit::query()->where('employee_id', $worker->id)->count());
        $this->assertSame($expected - 1, CompOffCredit::query()->where('employee_id', $idler->id)->count());
    }

    public function test_a_tenant_that_banks_nothing_credits_nothing(): void
    {
        $employee = $this->makeEmployee();
        $this->rosterFor($employee, [5 => 1, 6 => 1]);
        [$from, $to] = $this->lastMonth();

        // Nothing means nothing: weekends off is not enough in a month
        // with a bankable holiday, so both legs go off.
        $this->setCompOff(['from_weekends' => false, 'from_holidays' => false]);

        $result = $this->credits()->creditFromCalendar($employee, $from, $to);

        $this->assertSame(0, $result['credited']);
        $this->assertSame(0, CompOffCredit::query()->count());
    }

    public function test_expiry_drops_credits_out_of_the_derived_balance(): void
    {
        $employee = $this->makeEmployee();
        $this->setCompOff(['validity_months' => 1]);

        $old = today()->subMonths(3)->toDateString();
        $fresh = today()->subDays(5)->toDateString();

        $this->credits()->creditManual($employee, $old, 480, CompOffSource::Manual, 'Old grant.');
        $this->credits()->creditManual($employee, $fresh, 480, CompOffSource::Manual, 'Fresh grant.');

        // The old credit expired after a month; the fresh one spends.
        $this->assertSame(480, $this->credits()->balance($employee));
        $this->assertSame(480, $this->credits()->expiredBalance($employee));

        $this->setCompOff(['validity_months' => null]);

        $this->credits()->creditManual($employee, $fresh, 240, CompOffSource::Special, 'Top-up.');

        // Null validity stops *new* stamps from expiring; the old row keeps
        // the expiry it was granted with and stays out.
        $this->assertSame(480 + 240, $this->credits()->balance($employee));
    }

    public function test_manual_grants_validate_and_refuse_duplicates(): void
    {
        $employee = $this->makeEmployee();
        $day = today()->subDays(5)->toDateString();

        try {
            $this->credits()->creditManual($employee, $day, 0);
            $this->fail('Zero minutes must 422.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('minutes', $exception->errors());
        }

        try {
            $this->credits()->creditManual($employee, today()->addDay()->toDateString(), 480);
            $this->fail('Future banking must 422.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_date', $exception->errors());
        }

        $this->credits()->creditManual($employee, $day, 480);

        try {
            $this->credits()->creditManual($employee, $day, 480);
            $this->fail('A duplicate grant must 422.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }

        $this->assertTrue(HrmsAuditLog::query()->where('action', 'comp_off.credited')->exists());
    }

    public function test_redemption_validates_spends_and_restores(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->credits()->creditManual($report, today()->subDays(10)->toDateString(), 480);

        // Two working days cost 960; only 480 banked.
        try {
            $this->service()->request($report, [
                'from_date' => $this->nextMonday()->toDateString(),
                'to_date' => $this->nextMonday()->addDay()->toDateString(),
                'reason' => 'Too much.',
            ]);
            $this->fail('Over-balance redemption must 422.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Available 480 minutes, short by 480.'], $exception->errors()['form']);
        }

        $ask = $this->service()->request($report, [
            'from_date' => $this->nextMonday()->toDateString(),
            'to_date' => $this->nextMonday()->toDateString(),
            'reason' => 'One day back.',
        ]);

        $this->assertSame(480, $ask->total_minutes);
        $this->assertSame('submitted', $ask->status->value);

        $this->service()->approve($ask->refresh(), $manager->user);

        $this->assertSame(0, $this->credits()->balance($report));

        // Derived, not stored: cancelling restores by status alone.
        $this->service()->cancelRequest($ask->refresh(), $report->user);

        $this->assertSame(480, $this->credits()->balance($report));
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'comp_off.approved')->exists());
    }

    public function test_the_accrue_command_walks_tenants_behind_a_dry_run(): void
    {
        $employee = $this->makeEmployee('Command Earner');
        $this->rosterFor($employee, [5 => 1, 6 => 1]);
        [$from, $to] = $this->lastMonth();

        // The count below is weekends; a holiday in the window would bank
        // an extra credit through the holiday leg (see the three tests
        // above for why the month matters).
        $this->setCompOff(['from_holidays' => false]);

        $this->artisan('hrms:comp-off-accrue', [
            '--tenant' => $this->acme()->id,
            '--from' => $from,
            '--to' => $to,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, CompOffCredit::query()->count());

        $this->artisan('hrms:comp-off-accrue', [
            '--tenant' => $this->acme()->id,
            '--from' => $from,
            '--to' => $to,
        ])->assertSuccessful();

        $this->assertSame($this->weekendCount($from, $to), CompOffCredit::query()->count());

        $this->artisan('hrms:comp-off-accrue', ['--from' => $from, '--to' => $to])->assertFailed();
        $this->artisan('hrms:comp-off-accrue', [
            '--tenant' => $this->acme()->id,
            '--from' => $from,
        ])->assertFailed();
    }

    // ------------------------------------------------------------ notifications

    public function test_filing_nudges_the_manager_and_decisions_nudge_back(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->credits()->creditManual($report, today()->subDays(10)->toDateString(), 960);

        $ask = $this->service()->request($report, [
            'from_date' => $this->nextMonday()->toDateString(),
            'to_date' => $this->nextMonday()->toDateString(),
            'reason' => 'One day back.',
        ], $report->user);

        $nudged = UserNotification::query()
            ->where('user_id', $manager->user->id)
            ->where('type', 'hrms.comp_off.requested')
            ->firstOrFail();

        $this->assertSame($ask->id, $nudged->data['comp_off_request_id']);
        $this->assertSame($report->name, $nudged->data['employee_name']);

        $this->service()->approve($ask->refresh(), $manager->user);

        $decided = UserNotification::query()
            ->where('user_id', $report->user->id)
            ->where('type', 'hrms.comp_off.approved')
            ->firstOrFail();

        $this->assertSame('submitted', $decided->data['from_status']);
        $this->assertSame('approved', $decided->data['to_status']);
    }

    public function test_a_manager_filing_for_a_report_gets_no_nudge_about_it(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->credits()->creditManual($report, today()->subDays(10)->toDateString(), 960);

        // Actor and approver are the same login: skip-self, mirroring the
        // leave nudge — nobody needs a toast about their own filing.
        $this->service()->request($report, [
            'from_date' => $this->nextMonday()->toDateString(),
            'to_date' => $this->nextMonday()->toDateString(),
            'reason' => 'Filed for my report.',
        ], $manager->user);

        $this->assertSame(0, UserNotification::query()
            ->where('user_id', $manager->user->id)
            ->where('type', 'hrms.comp_off.requested')
            ->count());
    }

    // ------------------------------------------------------------ helpers

    private function service(): CompOffService
    {
        return app(CompOffService::class);
    }

    private function credits(): CompOffCredits
    {
        return app(CompOffCredits::class);
    }

    /**
     * @return array{string, string} First and last day of the previous month.
     */
    private function lastMonth(): array
    {
        $start = today()->startOfMonth()->subMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    private function weekendCount(string $from, string $to): int
    {
        $count = 0;

        for ($date = Carbon::parse($from); $date->lessThanOrEqualTo(Carbon::parse($to)); $date->addDay()) {
            if ($date->dayOfWeekIso >= 6) {
                $count++;
            }
        }

        return $count;
    }

    private function firstSaturday(string $from, string $to): string
    {
        for ($date = Carbon::parse($from); $date->lessThanOrEqualTo(Carbon::parse($to)); $date->addDay()) {
            if ($date->dayOfWeekIso === 6) {
                return $date->toDateString();
            }
        }

        $this->fail('The test window holds no Saturday.');
    }

    private function nextMonday(): Carbon
    {
        return today()->modify('next monday');
    }

    /**
     * @return array{Employee, Employee} Manager and report, each with `->user`.
     */
    private function reportingLine(): array
    {
        $managerUser = $this->makeUser();
        $manager = $this->makeEmployee('Comp Manager', ['user_id' => $managerUser->id]);
        $manager->user = $managerUser;

        $reportUser = $this->makeUser();
        $report = $this->makeEmployee('Comp Report', ['user_id' => $reportUser->id, 'manager_id' => $manager->id]);
        $report->user = $reportUser;

        return [$manager, $report];
    }

    private function makeUser(): User
    {
        static $sequence = 0;

        $sequence++;

        return User::create([
            'name' => "Comp User {$sequence}",
            'email' => "comp.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);
    }

    private function makeEmployee(string $name = 'Comp Person', array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-CMP-'.$sequence,
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
            'name' => "Comp Leave {$sequence}",
            'slug' => "comp-leave-{$sequence}",
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
            'name' => "Comp Shift {$sequence}",
            'code' => "comp-shift-{$sequence}",
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function setCompOff(array $overrides): void
    {
        $settings = HrmsSetting::current();
        $compOff = array_merge($settings->comp_off ?? [], $overrides);
        $settings->forceFill(['comp_off' => $compOff]);
        $settings->save();
    }
}
