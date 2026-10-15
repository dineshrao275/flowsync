<?php

namespace App\Services\Hrms\Payroll;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\EmployeeSalaryStructure;
use App\Models\Hrms\Payroll\PayrollRun;
use Illuminate\Support\Collection;

/**
 * One query for every active employee's covering salary assignment, with
 * structures + components eager-loaded. PayslipCalculator used to re-query
 * that pair per employee (N+1 at run scale).
 */
class PayrollAssignmentMap
{
    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, EmployeeSalaryStructure> keyed by employee_id
     */
    public function forEmployees(Collection $employees, PayrollRun $run): Collection
    {
        if ($employees->isEmpty()) {
            return collect();
        }

        $payDate = $run->pay_date->toDateString();

        return EmployeeSalaryStructure::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereDate('effective_from', '<=', $payDate)
            ->with(['structure.components'])
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id');
    }
}
