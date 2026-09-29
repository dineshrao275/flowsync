<?php

namespace App\Services\Hrms\Payroll;

use App\Enums\Hrms\DataAccessAction;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\User;
use App\Services\HrmsAuditLogger;

/**
 * Payroll/HRMS — payslips out for reading.
 *
 * Every read writes an `hrms_data_access_logs` view row — the grid writes
 * one per payslip it returns, because the acceptance is "every payslip read
 * is logged" and a grid that silently skipped its rows would answer it
 * falsely. Amounts never enter the ledger: only the column names travel, so
 * the row says what was seen without repeating it.
 */
class PayslipReading
{
    public function __construct(
        private readonly PayslipDownload $downloads,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * The run review grid for a runner: every payslip, oldest record first,
     * each logged as read.
     *
     * @return list<array<string, mixed>>
     */
    public function payslipsFor(User $viewer, PayrollRun $run, ?string $ipAddress): array
    {
        return $run->payslips()
            ->with(['employee:id,employee_code,name,user_id', 'adjustments'])
            ->orderBy('id')
            ->get()
            ->map(fn (Payslip $payslip): array => $this->logged($viewer, $payslip, $ipAddress))
            ->all();
    }

    /**
     * One payslip for its reader, logged.
     *
     * @return array<string, mixed>
     */
    public function show(User $reader, Payslip $payslip, ?string $ipAddress): array
    {
        $payslip->loadMissing(['employee:id,employee_code,name,user_id', 'adjustments', 'run']);

        return $this->logged($reader, $payslip, $ipAddress);
    }

    /**
     * One payslip, shaped for a client, with its signed download.
     *
     * @return array<string, mixed>
     */
    public function present(Payslip $payslip, ?User $reader = null): array
    {
        $payslip->loadMissing(['employee:id,employee_code,name,user_id', 'adjustments', 'run']);

        return [
            'id' => $payslip->id,
            'payroll_run_id' => $payslip->payroll_run_id,
            'employee' => $payslip->employee ? [
                'id' => $payslip->employee->id,
                'employee_code' => $payslip->employee->employee_code,
                'name' => $payslip->employee->displayName(),
            ] : null,
            'run' => $payslip->run ? [
                'id' => $payslip->run->id,
                'period_year' => $payslip->run->period_year,
                'period_month' => $payslip->run->period_month,
                'status' => $payslip->run->status->value,
            ] : null,
            'earnings' => $payslip->earnings ?? [],
            'deductions' => $payslip->deductions ?? [],
            'employer_contributions' => $payslip->employer_contributions ?? [],
            'statutory' => $payslip->statutory ?? [],
            'adjustments' => $payslip->adjustments->map(fn ($row): array => [
                'id' => $row->id,
                'kind' => $row->kind,
                'label' => $row->label,
                'amount' => (string) $row->amount,
            ])->all(),
            'gross_pay' => (string) $payslip->gross_pay,
            'total_deductions' => (string) $payslip->total_deductions,
            'net_pay' => (string) $payslip->net_pay,
            'working_days' => (string) $payslip->working_days,
            'paid_days' => (string) $payslip->paid_days,
            'lop_days' => (string) $payslip->lop_days,
            'ot_minutes' => $payslip->ot_minutes,
            'absent_days' => $payslip->absent_days,
            'leave_days' => $payslip->leave_days ?? [],
            'status' => $payslip->status->value,
            'published_at' => $payslip->published_at?->toIso8601String(),
            'download_url' => $this->downloads->url($payslip, $reader),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function logged(User $reader, Payslip $payslip, ?string $ipAddress): array
    {
        $this->audit->accessed(
            (new Payslip)->getMorphClass(),
            $payslip->id,
            DataAccessAction::View,
            ['earnings', 'deductions', 'employer_contributions', 'gross_pay', 'total_deductions', 'net_pay'],
            $reader,
            $ipAddress,
        );

        return $this->present($payslip, $reader);
    }
}
