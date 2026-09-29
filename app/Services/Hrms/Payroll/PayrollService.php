<?php

namespace App\Services\Hrms\Payroll;

use App\Enums\Hrms\PayrollRunStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Payroll\PayslipAdjustment;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Support\Hrms\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payroll/HRMS — runs in, payslips out.
 *
 * A thin orchestrator on purpose: per-employee math lives in
 * {@see PayslipCalculator}, run-state transitions in
 * {@see PayrollLifecycle}. Calculating a draft/review run wipes and rebuilds
 * its payslips — hand-added adjustments survive the wipe because they are
 * preserved per employee and re-created, so a review refinement is never
 * lost to a recalculation.
 */
class PayrollService
{
    public function __construct(
        private readonly PayslipCalculator $calculator,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array{period_year?: int, period_month?: int, pay_period_start?: string, pay_period_end?: string, pay_date?: string, notes?: string|null}  $data
     *
     * @throws ValidationException on a bad period or a duplicate month
     */
    public function openRun(array $data, ?User $actor = null): PayrollRun
    {
        $year = (int) ($data['period_year'] ?? 0);
        $month = (int) ($data['period_month'] ?? 0);

        if ($year < 1900 || $year > 2100 || $month < 1 || $month > 12) {
            throw ValidationException::withMessages(['period' => 'A run prices one real calendar month.']);
        }

        $start = Carbon::parse((string) ($data['pay_period_start'] ?? ''))->startOfDay();
        $end = Carbon::parse((string) ($data['pay_period_end'] ?? ''))->startOfDay();
        $payDate = Carbon::parse((string) ($data['pay_date'] ?? ''))->startOfDay();

        if ($end->lessThan($start)) {
            throw ValidationException::withMessages(['pay_period_end' => 'A period cannot end before it starts.']);
        }

        if (PayrollRun::query()->where('period_year', $year)->where('period_month', $month)->exists()) {
            throw ValidationException::withMessages(['period' => 'That month already has a run.']);
        }

        $run = PayrollRun::create([
            'period_year' => $year,
            'period_month' => $month,
            'pay_period_start' => $start->toDateString(),
            'pay_period_end' => $end->toDateString(),
            'pay_date' => $payDate->toDateString(),
            'status' => PayrollRunStatus::Draft,
            'initiated_by_user_id' => $actor?->id,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit->log($run, 'payroll.run_opened', null, [
            'period_year' => $year,
            'period_month' => $month,
        ], $actor);

        return $run->refresh();
    }

    /**
     * (Re)build every payslip on a draft/review run. Never a locked run —
     * the status gate refuses before a single row is touched.
     *
     * @return array{calculated: int, skipped: list<int>}
     *
     * @throws ValidationException on a non-calculable run
     */
    public function calculate(PayrollRun $run, ?User $actor = null): array
    {
        $this->requireCalculable($run);

        return DB::transaction(function () use ($run, $actor): array {
            $run->update(['status' => PayrollRunStatus::Calculating]);

            $preserved = $this->preservedAdjustments($run);
            $run->payslips()->delete();

            $calculated = 0;
            $skipped = [];

            foreach (Employee::query()->active()->orderBy('id')->get() as $employee) {
                $payload = $this->calculator->build($employee, $run, $preserved[$employee->id] ?? []);

                if ($payload === null) {
                    $skipped[] = $employee->id;

                    continue;
                }

                $this->storePayslip($run, $employee, $payload);
                $calculated++;
            }

            $run->update([
                'employee_count' => $calculated,
                'totals' => $this->runTotals($run),
                'status' => PayrollRunStatus::Review,
            ]);

            $this->audit->log($run->refresh(), 'payroll.calculated', null, [
                'calculated' => $calculated,
                'skipped' => $skipped,
            ], $actor);

            return ['calculated' => $calculated, 'skipped' => $skipped];
        });
    }

    /**
     * Rebuild one employee's payslip on a review run — the review-grid
     * correction path, carrying that payslip's adjustments across.
     *
     * @throws ValidationException outside review or without a pay basis
     */
    public function recalculateEmployee(PayrollRun $run, Employee $employee, ?User $actor = null): Payslip
    {
        if ($run->status !== PayrollRunStatus::Review) {
            throw ValidationException::withMessages(['run' => 'Only a run in review accepts corrections.']);
        }

        return DB::transaction(function () use ($run, $employee, $actor): Payslip {
            $existing = $run->payslips()->where('employee_id', $employee->id)->first();
            $preserved = $existing !== null ? $this->adjustmentAttributes($existing) : [];

            $existing?->delete();

            $payload = $this->calculator->build($employee, $run, $preserved);

            if ($payload === null) {
                throw ValidationException::withMessages(['employee' => 'That employee has no pay basis for this run.']);
            }

            $payslip = $this->storePayslip($run, $employee, $payload);

            $run->update([
                'employee_count' => $run->payslips()->count(),
                'totals' => $this->runTotals($run),
            ]);

            $this->audit->log($run->refresh(), 'payroll.recalculated', null, [
                'employee_id' => $employee->id,
            ], $actor);

            return $payslip;
        });
    }

    /**
     * Hand a bonus or recovery onto a review payslip: the row is created and
     * the snapshot totals are recomputed from the stored heads plus every
     * adjustment, so the totals always equal their inputs. Paid runs refuse —
     * a correction after approval is a recalculation, not a quiet edit.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException outside review
     */
    public function addAdjustment(Payslip $payslip, array $data, ?User $actor = null): Payslip
    {
        $this->requireReview($payslip->run);

        $amount = Money::fromDecimal((string) ($data['amount'] ?? '0'));

        if ($amount->isNegative() || $amount->isZero()) {
            throw ValidationException::withMessages(['amount' => 'An adjustment moves positive money — pick the kind for the direction.']);
        }

        return DB::transaction(function () use ($payslip, $data, $actor): Payslip {
            $adjustment = $payslip->adjustments()->create([...$data, 'actor_user_id' => $actor?->id, 'created_at' => now()]);
            $this->refreshTotals($payslip->fresh());

            $this->audit->log($payslip->run, 'payroll.adjustment_added', null, [
                'payslip_id' => $payslip->id,
                'kind' => $adjustment->kind,
            ], $actor);

            return $payslip->refresh();
        });
    }

    /**
     * Take a hand-added line back off a review payslip, recomputing the
     * snapshot totals the same way.
     *
     * @throws ValidationException outside review
     */
    public function removeAdjustment(PayslipAdjustment $adjustment, ?User $actor = null): Payslip
    {
        $payslip = $adjustment->payslip;
        $this->requireReview($payslip->run);

        return DB::transaction(function () use ($payslip, $adjustment, $actor): Payslip {
            $kind = $adjustment->kind;
            $adjustment->delete();
            $this->refreshTotals($payslip->refresh());

            $this->audit->log($payslip->run, 'payroll.adjustment_removed', null, [
                'payslip_id' => $payslip->id,
                'kind' => $kind,
            ], $actor);

            return $payslip->refresh();
        });
    }

    /**
     * Hand-added lines keyed by employee, values as plain attributes the
     * calculator folds back into the rebuilt totals.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function preservedAdjustments(PayrollRun $run): array
    {
        $preserved = [];

        foreach ($run->payslips()->with('adjustments')->get() as $payslip) {
            $preserved[$payslip->employee_id] = $this->adjustmentAttributes($payslip);
        }

        return $preserved;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function adjustmentAttributes(Payslip $payslip): array
    {
        return $payslip->adjustments->map(fn ($row): array => [
            'kind' => $row->kind,
            'label' => $row->label,
            'amount' => (string) $row->amount,
            'component_id' => $row->component_id,
            'reference_type' => $row->reference_type,
            'reference_id' => $row->reference_id,
            'note' => $row->note,
            'actor_user_id' => $row->actor_user_id,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storePayslip(PayrollRun $run, Employee $employee, array $payload): Payslip
    {
        $adjustments = $payload['adjustments'] ?? [];
        unset($payload['adjustments']);

        $payslip = $run->payslips()->create([...$payload, 'employee_id' => $employee->id]);

        foreach ($adjustments as $row) {
            $payslip->adjustments()->create([...$row, 'created_at' => now()]);
        }

        return $payslip;
    }

    /**
     * @return array{gross_pay: string, total_deductions: string, net_pay: string}
     */
    private function runTotals(PayrollRun $run): array
    {
        $gross = Money::zero();
        $deductions = Money::zero();
        $net = Money::zero();

        foreach ($run->payslips()->get(['gross_pay', 'total_deductions', 'net_pay']) as $payslip) {
            $gross = $gross->add(Money::fromDecimal((string) $payslip->gross_pay));
            $deductions = $deductions->add(Money::fromDecimal((string) $payslip->total_deductions));
            $net = $net->add(Money::fromDecimal((string) $payslip->net_pay));
        }

        return [
            'gross_pay' => $gross->toDecimal(),
            'total_deductions' => $deductions->toDecimal(),
            'net_pay' => $net->toDecimal(),
        ];
    }

    /**
     * Recompute one payslip's snapshot totals from its stored heads plus
     * every adjustment row, then roll the run totals up. The same equation
     * the calculator writes at build time — a later adjustment must land on
     * identical math, or review compares two different arithmetics.
     */
    private function refreshTotals(Payslip $payslip): void
    {
        $currency = $payslip->assignment?->structure->currency ?? 'INR';
        $gross = Money::zero($currency);
        $deductions = Money::zero($currency);

        foreach ($payslip->earnings ?? [] as $line) {
            $gross = $gross->add(Money::fromDecimal((string) ($line['monthly'] ?? '0'), $currency));
        }

        foreach ($payslip->deductions ?? [] as $line) {
            $deductions = $deductions->add(Money::fromDecimal((string) ($line['monthly'] ?? '0'), $currency));
        }

        $earnAdjust = Money::zero($currency);
        $dedAdjust = Money::zero($currency);

        foreach ($payslip->adjustments as $row) {
            $amount = Money::fromDecimal((string) $row->amount, $currency);

            if ($row->kind === 'deduction') {
                $dedAdjust = $dedAdjust->add($amount);
            } else {
                $earnAdjust = $earnAdjust->add($amount);
            }
        }

        $payslip->update([
            'gross_pay' => $gross->toDecimal(),
            'total_deductions' => $deductions->add($dedAdjust)->toDecimal(),
            'net_pay' => $gross->sub($deductions)->add($earnAdjust)->sub($dedAdjust)->toDecimal(),
        ]);

        $run = $payslip->run;
        $run->update(['totals' => $this->runTotals($run)]);
    }

    /**
     * @throws ValidationException outside review
     */
    private function requireReview(PayrollRun $run): void
    {
        if ($run->status !== PayrollRunStatus::Review) {
            throw ValidationException::withMessages(['run' => 'Only a run in review accepts adjustments.']);
        }
    }

    /**
     * @throws ValidationException on a run past review
     */
    private function requireCalculable(PayrollRun $run): void
    {
        if (! $run->status->isCalculable()) {
            throw ValidationException::withMessages(['run' => 'Only a draft or review run can be calculated.']);
        }
    }
}
