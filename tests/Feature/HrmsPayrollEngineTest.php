<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Payroll\PayrollLifecycle;
use App\Services\Hrms\Payroll\PayrollService;
use App\Support\Hrms\Money;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P9.3b — the payroll engine end to end.
 *
 * A run prices LOP from attendance minus approved leave, prorates flagged
 * earnings, prices overtime off the configured rate, survives a
 * recalculation with its hand-added adjustments intact, walks
 * review → approved → processing → paid → locked with an audit row per
 * step, and notifies employees without amounts. August 2026 carries no US
 * holiday, so every date is a working one — and the assertions read the
 * working count off the payslip rather than hard-coding it.
 */
class HrmsPayrollEngineTest extends TestCase
{
    use IsolatesDatabase;

    public function test_open_run_validates_its_period(): void
    {
        $this->expectException(ValidationException::class);

        app(PayrollService::class)->openRun($this->period(['period_month' => 13]));
    }

    public function test_a_month_gets_exactly_one_run(): void
    {
        app(PayrollService::class)->openRun($this->period());

        $this->expectException(ValidationException::class);

        app(PayrollService::class)->openRun($this->period());
    }

    public function test_a_clean_month_pays_every_head_in_full(): void
    {
        $employee = $this->pricedEmployee();
        $run = app(PayrollService::class)->openRun($this->period());

        $result = app(PayrollService::class)->calculate($run);

        $this->assertSame(['calculated' => 1, 'skipped' => []], $result);

        $payslip = $run->refresh()->payslips()->firstOrFail();

        $this->assertSame('0.00', (string) $payslip->lop_days);
        $this->assertSame((string) $payslip->working_days, (string) $payslip->paid_days);
        $this->assertSame('37500.00', (string) $payslip->gross_pay);
        $this->assertSame('200.00', (string) $payslip->total_deductions);
        $this->assertSame('37300.00', (string) $payslip->net_pay);
        $this->assertSame('review', $run->refresh()->status->value);
        $this->assertSame('37500.00', $run->refresh()->totals['gross_pay']);
    }

    public function test_unpaid_leave_and_unexplained_absences_become_lop(): void
    {
        $employee = $this->pricedEmployee();
        $paid = $this->makeType(['is_paid' => true]);
        $unpaid = $this->makeType(['is_paid' => false]);

        $this->ask($employee, $paid, '2026-08-11', '2026-08-12');
        $this->ask($employee, $unpaid, '2026-08-13', '2026-08-13');

        foreach (['2026-08-17', '2026-08-18', '2026-08-19'] as $date) {
            $this->day($employee, $date, ['status' => 'absent']);
        }

        $run = app(PayrollService::class)->openRun($this->period());
        app(PayrollService::class)->calculate($run);

        $payslip = $run->refresh()->payslips()->firstOrFail();

        // Three unexplained days plus one unpaid one; the two paid days
        // stay payable by staying out of the equation.
        $this->assertSame('4.00', (string) $payslip->lop_days);
        $this->assertSame(3, $payslip->absent_days);
        $this->assertEqualsWithDelta(
            (float) $payslip->working_days - 4.0,
            (float) $payslip->paid_days,
            0.001,
        );

        $expected = Money::fromDecimal('37500.00')
            ->multiply(number_format((float) $payslip->paid_days, 2, '.', ''))
            ->dividedBy(number_format((float) $payslip->working_days, 0, '.', ''))
            ->toDecimal();

        $this->assertSame($expected, (string) $payslip->gross_pay);
    }

    public function test_overtime_prices_off_the_configured_rate(): void
    {
        $employee = $this->pricedEmployee();

        $this->day($employee, '2026-08-10', [
            'status' => 'present',
            'first_in_at' => '2026-08-10 09:00:00',
            'last_out_at' => '2026-08-10 20:00:00',
            'overtime_minutes' => 120,
        ]);

        HrmsSetting::current()->update(['payroll' => ['ot_rate' => 2.0]]);

        $run = app(PayrollService::class)->openRun($this->period());
        app(PayrollService::class)->calculate($run);

        $payslip = $run->refresh()->payslips()->firstOrFail();

        $this->assertSame(120, $payslip->ot_minutes);

        $hourly = Money::fromDecimal('50000.00')->dividedBy('248');
        $expected = $hourly->multiply('2.0000')->multiply('2')->toDecimal();

        $lines = collect($payslip->earnings);
        $this->assertSame($expected, $lines->firstWhere('code', 'overtime')['monthly']);
    }

    public function test_recalculation_keeps_adjustments_and_a_locked_run_refuses(): void
    {
        $employee = $this->pricedEmployee();
        $service = app(PayrollService::class);
        $run = $service->openRun($this->period());
        $service->calculate($run);

        $payslip = $run->refresh()->payslips()->firstOrFail();
        $payslip->adjustments()->create([
            'kind' => 'earning',
            'label' => 'Joining bonus',
            'amount' => '5000.00',
            'created_at' => now(),
        ]);

        $service->calculate($run->refresh());

        $rebuilt = $run->refresh()->payslips()->firstOrFail();

        $this->assertSame('Joining bonus', $rebuilt->adjustments()->firstOrFail()->label);
        $this->assertSame('42300.00', (string) $rebuilt->net_pay);

        $lifecycle = app(PayrollLifecycle::class);
        $lifecycle->approve($run->refresh());
        $lifecycle->publish($run->refresh());
        $lifecycle->markPaid($run->refresh());
        $lifecycle->lock($run->refresh());

        $this->expectException(ValidationException::class);
        $service->calculate($run->refresh());
    }

    public function test_recalculating_a_locked_run_is_refused(): void
    {
        $employee = $this->pricedEmployee();
        $service = app(PayrollService::class);
        $run = $service->openRun($this->period());
        $service->calculate($run);

        $lifecycle = app(PayrollLifecycle::class);
        $lifecycle->approve($run->refresh());
        $lifecycle->publish($run->refresh());
        $lifecycle->markPaid($run->refresh());
        $lifecycle->lock($run->refresh());

        $this->expectException(ValidationException::class);

        $service->recalculateEmployee($run->refresh(), $employee);
    }

    public function test_the_lifecycle_audits_every_step_and_publish_notifies_without_amounts(): void
    {
        $user = $this->makeUser();
        $employee = $this->pricedEmployee(['user_id' => $user->id]);
        $hr = $this->makeUser();

        $service = app(PayrollService::class);
        $run = $service->openRun($this->period());
        $service->calculate($run);

        $lifecycle = app(PayrollLifecycle::class);
        $lifecycle->approve($run->refresh(), $hr);
        $lifecycle->publish($run->refresh(), $hr);
        $lifecycle->markPaid($run->refresh(), $hr);
        $lifecycle->lock($run->refresh(), $hr);

        $this->assertSame('locked', $run->refresh()->status->value);

        $actions = HrmsAuditLog::query()
            ->where('subject_type', $run->getMorphClass())
            ->where('subject_id', $run->id)
            ->pluck('action')
            ->all();

        foreach (['payroll.run_opened', 'payroll.calculated', 'payroll.approved', 'payroll.published', 'payroll.paid', 'payroll.locked'] as $action) {
            $this->assertContains($action, $actions);
        }

        $notification = UserNotification::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('hrms.payroll.published', $notification->type);
        $this->assertSame(
            ['payroll_run_id', 'period_year', 'period_month', 'payslip_id'],
            array_keys($notification->data),
        );
    }

    public function test_an_employee_without_a_pay_basis_is_skipped_not_invented(): void
    {
        $this->makeEmployee('Unpriced');

        $run = app(PayrollService::class)->openRun($this->period());
        $result = app(PayrollService::class)->calculate($run);

        $this->assertSame(0, $result['calculated']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(0, $run->refresh()->payslips()->count());
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function period(array $overrides = []): array
    {
        return [
            'period_year' => 2026,
            'period_month' => 8,
            'pay_period_start' => '2026-08-01',
            'pay_period_end' => '2026-08-31',
            'pay_date' => '2026-09-05',
            ...$overrides,
        ];
    }

    /**
     * An employee priced at 6L on basic 25000 + 50% HRA with a 200 PT line:
     * full-month earnings 37500, deductions 200, gross base 50000.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function pricedEmployee(array $overrides = []): Employee
    {
        $employee = $this->makeEmployee('Priced', $overrides);

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();
        $pt = SalaryComponent::query()->where('code', 'professional_tax')->firstOrFail();

        $structure = app(CompensationService::class)->createStructure(
            ['name' => 'Engine 6L', 'currency' => 'INR', 'effective_from' => '2026-04-01'],
            [
                ['component_id' => $basic->id, 'value' => 25000, 'sequence' => 10],
                ['component_id' => $hra->id, 'value' => 50, 'sequence' => 20],
                ['component_id' => $pt->id, 'value' => 200, 'sequence' => 30],
            ],
        );

        app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');

        return $employee;
    }

    private function makeUser(): User
    {
        static $sequence = 0;

        $sequence++;

        return User::create([
            'name' => "Engine User {$sequence}",
            'email' => "engine.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmployee(string $name, array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-ENG-'.$sequence,
            'name' => "{$name} {$sequence}",
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
            'name' => "Engine Type {$sequence}",
            'slug' => "engine-type-{$sequence}",
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

        for ($date = $from; $date <= $to; $date = date('Y-m-d', strtotime($date.' +1 day'))) {
            $request->days()->create(['date' => $date, 'is_holiday' => false, 'is_week_off' => false, 'is_half_day' => false]);
        }
    }
}
