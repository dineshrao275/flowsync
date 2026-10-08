<?php

namespace App\Services\Hrms\Payroll;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\EmployeeSalaryStructure;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Payroll\SalaryStructure;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Services\Hrms\AttendanceService;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Holiday\HolidayService;
use App\Services\Hrms\Leave\LeaveCalendar;
use App\Services\Hrms\Statutory\StatutoryResolver;
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
        private readonly StatutoryResolver $statutory,
    ) {}

    /**
     * Compute one employee's payslip payload, or null when the run has no
     * pay basis for them (no assignment covering the pay date — the caller
     * skips and reports, never invents a salary).
     *
     * @param  list<array{kind: string, label: string, amount: string, component_id?: int|null, reference_type?: string|null, reference_id?: int|null, note?: string|null, actor_user_id?: int|null}>  $preservedAdjustments
     * @return array<string, mixed>|null
     */
    public function build(Employee $employee, PayrollRun $run, array $preservedAdjustments = [], ?EmployeeSalaryStructure $assignment = null): ?array
    {
        $assignment ??= $this->assignmentFor($employee, $run);

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

        $structure = $this->structureOf($assignment);

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

        // Statutory lines price off the computed base (paid basic, monthly
        // gross) and fold into the snapshots: deduction-side lines reduce
        // take-home, employer-side lines inform it. A missing gate or
        // rulebook resolves nothing, and the payload below is exactly the
        // pre-statutory shape.
        $statutory = $this->statutory->apply($employee, $run, [
            'earnings' => $earnings,
            'gross_pay' => $gross->toDecimal(),
        ]);

        $statutoryDeductions = Money::zero($currency);

        foreach ($statutory as $line) {
            $entry = [
                'code' => $line['component'],
                'name' => $this->componentName($line['component']),
                'monthly' => $line['amount'],
                'source' => 'statutory',
            ];

            if ($line['side'] === 'employer') {
                $employer[] = $entry;

                continue;
            }

            $deductions[] = $entry;
            $statutoryDeductions = $statutoryDeductions->add(Money::fromDecimal($line['amount'], $currency));
        }

        $net = $net->sub($statutoryDeductions);

        return [
            'employee_salary_structure_id' => $assignment->id,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'employer_contributions' => $employer,
            'statutory' => $statutory,
            'gross_pay' => $gross->toDecimal(),
            'total_deductions' => $dedTotal->add($adjustDeductions)->add($statutoryDeductions)->toDecimal(),
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
     * Refresh one stored payslip's statutory lines from the current
     * rulebook, keeping the priced base and every adjustment: engine lines
     * (tagged `source: statutory` at fold time) are stripped and re-priced,
     * structure heads untouched. The recompute command's unit of work — a
     * config change must move these lines without repricing anyone.
     *
     * @return array{deductions: list<array<string, mixed>>, employer_contributions: list<array<string, mixed>>, statutory: list<array<string, mixed>>}
     */
    public function restatutory(Payslip $payslip, PayrollRun $run): array
    {
        $strip = fn (?array $lines): array => array_values(array_filter(
            $lines ?? [],
            fn ($line): bool => ($line['source'] ?? null) !== 'statutory',
        ));

        $deductions = $strip($payslip->deductions);
        $employer = $strip($payslip->employer_contributions);

        $lines = $this->statutory->apply($payslip->employee, $run, [
            'earnings' => $payslip->earnings ?? [],
            'gross_pay' => (string) $payslip->gross_pay,
        ]);

        foreach ($lines as $line) {
            $entry = [
                'code' => $line['component'],
                'name' => $this->componentName($line['component']),
                'monthly' => $line['amount'],
                'source' => 'statutory',
            ];

            if ($line['side'] === 'employer') {
                $employer[] = $entry;
            } else {
                $deductions[] = $entry;
            }
        }

        return ['deductions' => $deductions, 'employer_contributions' => $employer, 'statutory' => $lines];
    }

    /**
     * A snapshot line's display name: the catalogue where a head exists,
     * a headlined slug where it does not (engine codes like `tds` name no
     * catalogue row, and the snapshot must still read).
     */
    private function componentName(string $code): string
    {
        $name = SalaryComponent::query()->where('code', $code)->value('name');

        return $name !== null ? (string) $name : str($code)->headline()->toString();
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

    private function structureOf(EmployeeSalaryStructure $assignment): SalaryStructure
    {
        if ($assignment->relationLoaded('structure') && $assignment->structure !== null) {
            if (! $assignment->structure->relationLoaded('components')) {
                $assignment->structure->load('components');
            }

            return $assignment->structure;
        }

        return $assignment->structure()->with('components')->firstOrFail();
    }
}
