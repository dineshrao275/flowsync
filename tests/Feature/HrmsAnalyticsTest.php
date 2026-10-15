<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Org\Department;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\ReviewSummary;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\User;
use App\Services\Hrms\HrmsAnalyticsService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P18.2 — the cached workforce readers.
 *
 * Each domain aggregates live rows with the privacy tier beside the
 * numbers: person-level rows travel only when asked for, liability only
 * behind the payroll flag, ratings only for talent viewers outside peer
 * cycles — and every payroll read writes its access row.
 */
class HrmsAnalyticsTest extends TestCase
{
    use IsolatesDatabase;

    public function test_headcount_breaks_down_and_attrits(): void
    {
        $engineering = Department::query()->firstOrCreate(
            ['slug' => 'engineering'],
            ['name' => 'Engineering', 'code' => 'engineering'],
        );
        $this->makeEmployee(['department_id' => $engineering->id, 'joining_date' => today()->toDateString()]);
        $this->makeEmployee(['department_id' => $engineering->id]);
        $exited = $this->makeEmployee();
        $exited->update(['exit_date' => today()->toDateString(), 'status' => EmployeeStatus::Exited]);

        $headcount = app(HrmsAnalyticsService::class)->headcount();

        $this->assertSame(3, $headcount['total']);
        $this->assertSame(2, $headcount['by_department']['Engineering']);
        $this->assertSame(1, $headcount['new_hires_this_month']);
        $this->assertSame(1, $headcount['exits_this_month']);
        $this->assertEqualsWithDelta(33.3, $headcount['attrition_3mo_percent'], 0.05);
    }

    public function test_attendance_counts_with_optional_names(): void
    {
        $employee = $this->makeEmployee();
        $this->connectTenant('acme');

        AttendanceDay::create([
            'employee_id' => $employee->id,
            'work_date' => today()->toDateString(),
            'status' => 'present',
            'worked_minutes' => 480,
            'late_by_minutes' => 15,
            'overtime_minutes' => 60,
        ]);

        $full = app(HrmsAnalyticsService::class)->attendance();
        $this->assertSame(1, $full['present_days']);
        $this->assertSame(1, $full['late_days']);
        $this->assertSame(60, $full['overtime_minutes']);
        $this->assertSame(8.0, $full['average_worked_hours']);
        $this->assertSame($employee->name, $full['top_late'][0]['name']);

        $redacted = app(HrmsAnalyticsService::class)->attendance([], false);
        $this->assertSame([], $redacted['top_late']);
        $this->assertSame(1, $redacted['present_days']);
    }

    public function test_leave_liability_needs_the_payroll_flag(): void
    {
        $this->connectTenant('acme');

        $plain = app(HrmsAnalyticsService::class)->leave([], true, false);
        $this->assertNull($plain['expiry_liability']);

        $this->assertSame([], $plain['by_type']);
    }

    public function test_performance_counts_always_ratings_for_talent(): void
    {
        $this->connectTenant('acme');

        $cycle = PerformanceCycle::create([
            'name' => 'H1', 'slug' => 'h1-test', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30',
        ]);
        $employee = $this->makeEmployee();
        ReviewSummary::create([
            'cycle_id' => $cycle->id, 'employee_id' => $employee->id,
            'manager_rating' => 4, 'status' => 'final',
        ]);

        $counts = app(HrmsAnalyticsService::class)->performance();
        $this->assertNull($counts['ratings']);

        $rated = app(HrmsAnalyticsService::class)->performance([], true);
        $this->assertSame(4.0, $rated['ratings'][0]['average_manager_rating']);
    }

    public function test_payroll_totals_and_logs_every_call(): void
    {
        $this->connectTenant('acme');
        $actor = User::create(['name' => 'Reader', 'email' => 'reader@flowsync.test', 'password' => 'password']);

        PayrollRun::create([
            'period_year' => 2026, 'period_month' => 8,
            'pay_period_start' => '2026-08-01', 'pay_period_end' => '2026-08-31',
            'pay_date' => '2026-09-05', 'status' => 'paid',
            'totals' => ['gross_pay' => '100000.00', 'net_pay' => '80000.00'],
        ]);

        $payroll = app(HrmsAnalyticsService::class)->payroll([
            'from' => '2026-09-01', 'to' => '2026-09-30',
        ], $actor);

        $this->assertSame('100000.00', $payroll['total_gross']);
        $this->assertSame('80000.00', $payroll['total_net']);
        $this->assertTrue(HrmsDataAccessLog::query()
            ->where('model', (new Payslip)->getMorphClass())
            ->where('actor_user_id', $actor->id)
            ->exists());
    }

    public function test_documents_and_assets_summarize(): void
    {
        $this->connectTenant('acme');

        $documents = app(HrmsAnalyticsService::class)->documents();
        $this->assertArrayHasKey('compliance', $documents);
        $this->assertArrayHasKey('30d', $documents['expiring']);

        $assets = app(HrmsAnalyticsService::class)->assets();
        $this->assertSame([], $assets['by_status']);
        $this->assertSame([], $assets['per_department']);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmployee(array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        return Employee::create([
            'employee_code' => 'EMP-ANL-'.$sequence,
            'name' => "Analytics Employee {$sequence}",
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }
}
