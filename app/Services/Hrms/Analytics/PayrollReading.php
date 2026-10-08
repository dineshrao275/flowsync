<?php

namespace App\Services\Hrms\Analytics;

use App\Enums\Hrms\DataAccessAction;
use App\Models\Hrms\Org\Department;
use App\Models\Hrms\Payroll\EmployeeSalaryStructure;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Support\Hrms\Money;

/**
 * Analytics/HRMS — pay in aggregate, logged per read.
 *
 * Run windows sum their snapshots (what was reviewed is what gets paid);
 * department cost annualises live assignments. Aggregates of pay are still
 * pay data, so every call writes its access row — the ledger says who
 * read the totals, with the reader riding alongside.
 */
class PayrollReading extends AnalyticsReading
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function read(array $filters = [], ?User $actor = null): array
    {
        return $this->remember('payroll', $filters, function () use ($filters, $actor): array {
            [$from, $to] = $this->window($filters, 365);

            $gross = Money::zero();
            $net = Money::zero();

            foreach (PayrollRun::query()->whereDate('pay_date', '>=', $from)->whereDate('pay_date', '<=', $to)->get(['totals']) as $run) {
                $gross = $gross->add(Money::fromDecimal((string) ($run->totals['gross_pay'] ?? '0')));
                $net = $net->add(Money::fromDecimal((string) ($run->totals['net_pay'] ?? '0')));
            }

            $ctc = Money::zero();
            $departments = [];
            $deptNames = Department::query()->pluck('name', 'id');

            foreach (EmployeeSalaryStructure::query()->where('is_current', true)->with('employee:id,department_id')->get()
                ->groupBy(fn ($row): string => (string) ($row->employee?->department_id ?? 'unassigned')) as $departmentId => $rows) {
                $subtotal = Money::zero();

                foreach ($rows as $row) {
                    $annual = Money::fromDecimal((string) $row->ctc_annual);
                    $ctc = $ctc->add($annual);
                    $subtotal = $subtotal->add($annual);
                }

                $departments[] = [
                    'department' => $deptNames[$departmentId] ?? $departmentId,
                    'annual_ctc' => $subtotal->toDecimal(),
                ];
            }

            $this->audit->accessed(
                (new Payslip)->getMorphClass(),
                0,
                DataAccessAction::View,
                ['department_cost', 'totals'],
                $actor,
            );

            return [
                'total_gross' => $gross->toDecimal(),
                'total_net' => $net->toDecimal(),
                'total_annual_ctc' => $ctc->toDecimal(),
                'department_cost' => $departments,
            ];
        });
    }
}
