<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\LeaveRequestStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeavePolicy;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Services\Hrms\Leave\LeaveBalanceService;
use App\Services\Hrms\Leave\LeaveCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P6.2b — accruals, projections, and availability.
 *
 * The three rules under test: the ledger is the truth (the projection is a
 * re-sum, never a hand edit), accruals are idempotent per period (a crashed
 * run is re-run, not repaired), and years are leave years (the default
 * policy's start month moves the boundary).
 */
class HrmsLeaveBalanceTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_monthly_accrual_credits_once_per_month_and_rebuilds(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeType(['accrual_method' => 'monthly', 'accrual_rate' => 1.5]);

        $first = $this->balances()->accrue($employee, $type, 2026, Carbon::parse('2026-03-10'));
        $second = $this->balances()->accrue($employee, $type, 2026, Carbon::parse('2026-03-20'));

        // The second pass of the same period returns the same row — a rerun
        // is a no-op, not a double credit.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LeaveAdjustment::query()->count());

        $balance = LeaveBalance::query()->firstOrFail();

        $this->assertSame(1.5, (float) $balance->accrued);
        $this->assertSame(1.5, (float) $balance->balance);

        $april = $this->balances()->accrue($employee, $type, 2026, Carbon::parse('2026-04-05'));

        $this->assertNotSame($first->id, $april->id);
        $this->assertSame(3.0, (float) LeaveBalance::query()->firstOrFail()->balance);
    }

    public function test_an_annual_accrual_credits_the_full_rate_once(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeType(['accrual_method' => 'annual', 'accrual_rate' => 30]);

        $this->balances()->accrue($employee, $type, 2026, Carbon::parse('2026-01-05'));
        $this->balances()->accrue($employee, $type, 2026, Carbon::parse('2026-11-05'));

        $this->assertSame(1, LeaveAdjustment::query()->count());
        $this->assertSame(30.0, (float) LeaveBalance::query()->firstOrFail()->balance);
    }

    public function test_unscheduled_methods_skip_the_accrual(): void
    {
        $employee = $this->makeEmployee();

        foreach (['none', 'per_payroll'] as $method) {
            $type = $this->makeType(['accrual_method' => $method, 'accrual_rate' => 5]);

            $this->assertNull($this->balances()->accrue($employee, $type, 2026, Carbon::parse('2026-03-10')));
        }

        $this->assertSame(0, LeaveAdjustment::query()->count());
    }

    public function test_an_accrual_outside_the_leave_year_is_refused(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeType(['accrual_method' => 'monthly', 'accrual_rate' => 1]);

        try {
            $this->balances()->accrue($employee, $type, 2026, Carbon::parse('2027-01-05'));
            $this->fail('A January date must not credit leave year 2026.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('year', $exception->errors());
        }
    }

    public function test_rebuild_sums_families_caps_and_floors(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeType(['max_balance' => 10]);

        $this->ledger($employee, $type, 'opening', 4);
        $this->ledger($employee, $type, 'accrual', 8);
        $this->ledger($employee, $type, 'availed', -3);

        // Raw families stay readable while the balance caps: 4 + 8 − 3 = 9.
        $balance = $this->balances()->rebuildBalance($employee, $type, 2026);

        $this->assertSame(4.0, (float) $balance->opening);
        $this->assertSame(8.0, (float) $balance->accrued);
        $this->assertSame(-3.0, (float) $balance->availed);
        $this->assertSame(9.0, (float) $balance->balance);

        $this->ledger($employee, $type, 'accrual', 5);

        // 14 uncapped, 10 stored: the columns keep the full story.
        $balance = $this->balances()->rebuildBalance($employee, $type, 2026);

        $this->assertSame(13.0, (float) $balance->accrued);
        $this->assertSame(10.0, (float) $balance->balance);
    }

    public function test_a_negative_balance_floors_unless_the_type_allows_it(): void
    {
        $employee = $this->makeEmployee();
        $strict = $this->makeType();
        $loose = $this->makeType(['allow_negative_balance' => true]);

        $this->ledger($employee, $strict, 'availed', -3);
        $this->ledger($employee, $loose, 'availed', -3);

        $this->assertSame(0.0, (float) $this->balances()->rebuildBalance($employee, $strict, 2026)->balance);
        $this->assertSame(-3.0, (float) $this->balances()->rebuildBalance($employee, $loose, 2026)->balance);
    }

    public function test_available_days_reserves_pending_but_not_posted_asks(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeType();
        $this->ledger($employee, $type, 'opening', 12);

        // No asks: the whole balance is free, and reading it materializes
        // the missing projection row rather than reporting a stale zero.
        $this->assertSame(12.0, $this->balances()->availableDays($employee, $type, '2026-10-06', '2026-10-07'));
        $this->assertTrue(LeaveBalance::query()->exists());

        $this->ask($employee, $type, '2026-10-06', '2026-10-07', 2, LeaveRequestStatus::Pending);

        $this->assertSame(10.0, $this->balances()->availableDays($employee, $type, '2026-10-06', '2026-10-10'));

        // An approved ask already posted its availed row into the balance —
        // counting it again here would charge twice. (Approvals rebuild
        // synchronously in production, so the test rebuilds after posting.)
        $this->ledger($employee, $type, 'availed', -2);
        $this->balances()->rebuildBalance($employee, $type, 2026);
        $this->ask($employee, $type, '2026-10-06', '2026-10-07', 2, LeaveRequestStatus::Approved);

        $this->assertSame(8.0, $this->balances()->availableDays($employee, $type, '2026-10-06', '2026-10-10'));
    }

    public function test_the_leave_year_follows_the_default_policys_start_month(): void
    {
        LeavePolicy::query()->default()->update(['start_month' => 4]);

        $calendar = app(LeaveCalendar::class);

        $this->assertSame(2025, $calendar->leaveYearFor(Carbon::parse('2026-03-31')));
        $this->assertSame(2026, $calendar->leaveYearFor(Carbon::parse('2026-04-01')));
    }

    public function test_an_accrual_writes_an_audit_row(): void
    {
        $employee = $this->makeEmployee();
        $type = $this->makeType(['accrual_method' => 'monthly', 'accrual_rate' => 1]);

        $this->balances()->accrue($employee, $type, 2026, Carbon::parse('2026-03-10'));

        $this->assertTrue(HrmsAuditLog::query()->where('action', 'leave.accrued')->exists());
    }

    // ------------------------------------------------------------ helpers

    private function balances(): LeaveBalanceService
    {
        return app(LeaveBalanceService::class);
    }

    private function makeEmployee(): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-LVB-'.$sequence,
            'name' => "Balance Person {$sequence}",
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
            'name' => "Balance Type {$sequence}",
            'slug' => "balance-type-{$sequence}",
            'accrual_method' => 'none',
            ...$overrides,
        ]);
    }

    private function ledger(Employee $employee, LeaveType $type, string $kind, float $quantity): LeaveAdjustment
    {
        return LeaveAdjustment::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => 2026,
            'kind' => $kind,
            'quantity' => $quantity,
            'created_at' => now(),
        ]);
    }

    private function ask(Employee $employee, LeaveType $type, string $from, string $to, float $days, LeaveRequestStatus $status): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => $from,
            'to_date' => $to,
            'total_days' => $days,
            'reason' => 'A break.',
            'status' => $status->value,
        ]);
    }
}
