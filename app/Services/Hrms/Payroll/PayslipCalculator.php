<?php

namespace App\Services\Hrms\Payroll;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\EmployeeSalaryStructure;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Services\Hrms\AttendanceService;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Holiday\HolidayService;
use App\Services\Hrms\Leave\LeaveCalendar;
use App\Support\Hrms\Money;

/**
 * Payroll/HRMS — one employee's pay for a run, computed.
 *
 * The engine's math lives here so `PayrollService` stays an orchestrator
 * (runs, persistence, transitions) and the lifecycle its own class — the
 * three together would breach the 300-line ceiling, the P4.2 split rule.
 *
 * The LOP equation, stated once: `lop = F + 0.5H + U`, `paid = W − lop`,
 * where W is working dates (period minus week-offs minus holidays), F/H are
 * unexplained full/half absences (attendance rows minus charged-leave,
 * holiday and week-off dates), and U is unpaid approved leave. Paid leave
 * never enters lop — it stays payable by staying out of the equation.
 * Deductions never prorate; only `is_prorated` earnings do. Reimbursement
 * heads pay out, so they ride with earnings.
 */
class PayslipCalculator
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly HolidayService $holidays,
        private readonly LeaveCalendar $calendar,
        private readonly CompensationService $compensation,
    ) {}

    /**
     * Compute one employee's payslip payload, or null when the run has no
     * pay basis for them (no assignment covering the pay date — the caller
     * skips and reports, never invents a salary).
     *
     * @param  list<array{kind: string, label: string, amount: string, component_id?: int|null, reference_type?: string|null, reference_id?: int|null, note?: string|null, actor_user_id?: int|null}>  $preservedAdjustments
     * @return array<string, mixed>|null
     */
    public function build(Employee $employee, PayrollRun $run, array $preservedAdjustments = []): ?array
    {
        $assignment = $this->assignmentFor($employee, $run);

        if ($assignment === null) {
            return null;
        }

        $start = $run->pay_period_start->copy()->startOfDay();
        $end = $run->pay_period_end->copy()->startOfDay();

        $weekOffs = $this->attendance->weeklyOffDates($employee, $start, $end);
        $holidayDates = $this->holidays->holidayDates($employee, $start, $end);
        $closed = array_flip([...$weekOffs, ...$holidayDates]);

        $working = 0;

        for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
            if (! isset($closed[$date->toDateString()])) {
                $working++;
            }
        }

        $absence = $this->attendance->absenceDetail($employee, $start, $end);
        $leave = $this->calendar->chargedLeaveDays($employee, $start, $end);
        $charged = array_flip($leave['dates']);

        $full = count(array_diff($absence['absent'], array_keys($charged), $weekOffs, $holidayDates));
        $half = count(array_diff($absence['half'], array_keys($charged), $weekOffs, $holidayDates));

        $lop = round($full + $half * 0.5 + $leave['unpaid_days'], 2);
        $paid = max(0, round($working - $lop, 2));

        $structure = $assignment->structure()->with('components')->firstOrFail();

        $resolved = $this->compensation->ctcToComponents($structure, (string) $assignment->ctc_annual);

        $currency = $structure->currency;
        $paidFactor = number_format($paid, 2, '.', '');

        $earnings = [];
        $deductions = [];
        $employer = [];
        $gross = Money::zero($currency);
        $dedTotal = Money::zero($currency);

        foreach ($resolved['components'] as $head) {
            $monthly = Money::fromDecimal($head['monthly'], $currency);

            if ($head['type'] === 'deduction') {
                $deductions[] = ['code' => $head['code'], 'name' => $head['name'], 'monthly' => $monthly->toDecimal()];
                $dedTotal = $dedTotal->add($monthly);

                continue;
            }

            if ($head['type'] === 'employer_contribution') {
                $employer[] = ['code' => $head['code'], 'name' => $head['name'], 'monthly' => $monthly->toDecimal()];

                continue;
            }

            $payable = $head['is_prorated'] && $working > 0
                ? $monthly->multiply($paidFactor)->dividedBy((string) $working)
                : ($head['is_prorated'] ? Money::zero($currency) : $monthly);

            $earnings[] = ['code' => $head['code'], 'name' => $head['name'], 'monthly' => $payable->toDecimal()];
            $gross = $gross->add($payable);
        }

        $otMinutes = $absence['overtime_minutes'];
        $otRate = (string) (float) HrmsSetting::current()->setting('payroll.ot_rate', 1.0);

        if ($otMinutes > 0 && $working > 0) {
            $hourly = Money::fromDecimal($resolved['gross_monthly'], $currency)->dividedBy((string) ($working * 8));
            $otAmount = $hourly->multiply(bcdiv((string) $otMinutes, '60', 4))->multiply($otRate);

            $earnings[] = ['code' => 'overtime', 'name' => 'Overtime', 'monthly' => $otAmount->toDecimal()];
            $gross = $gross->add($otAmount);
        }

        $adjustEarnings = Money::zero($currency);
        $adjustDeductions = Money::zero($currency);

        foreach ($preservedAdjustments as $row) {
            $amount = Money::fromDecimal((string) $row['amount'], $currency);

            if (($row['kind'] ?? 'earning') === 'deduction') {
                $adjustDeductions = $adjustDeductions->add($amount);
            } else {
                $adjustEarnings = $adjustEarnings->add($amount);
            }
        }

        $net = $gross->sub($dedTotal)->add($adjustEarnings)->sub($adjustDeductions);

        return [
            'employee_salary_structure_id' => $assignment->id,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'employer_contributions' => $employer,
            'statutory' => [],
            'gross_pay' => $gross->toDecimal(),
            'total_deductions' => $dedTotal->add($adjustDeductions)->toDecimal(),
            'net_pay' => $net->toDecimal(),
            'working_days' => number_format($working, 2, '.', ''),
            'paid_days' => $paidFactor,
            'lop_days' => number_format($lop, 2, '.', ''),
            'ot_minutes' => $otMinutes,
            'absent_days' => $full,
            'leave_days' => [
                'paid_days' => $leave['paid_days'],
                'unpaid_days' => $leave['unpaid_days'],
                'dates' => $leave['dates'],
            ],
            'adjustments' => $preservedAdjustments,
        ];
    }

    /**
     * The latest assignment covering the pay date. The chain's live row by
     * construction — a raise closes the predecessor, so the newest
     * `effective_from <= pay_date` is the basis, whatever `effective_to`
     * says.
     */
    private function assignmentFor(Employee $employee, PayrollRun $run): ?EmployeeSalaryStructure
    {
        $payDate = $run->pay_date->toDateString();

        return EmployeeSalaryStructure::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $payDate)
            ->orderByDesc('effective_from')
            ->first();
    }
}
