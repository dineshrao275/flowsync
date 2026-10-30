<?php

declare(strict_types=1);

namespace App\Services\Hrms\Expense;

use App\Enums\Hrms\ExpenseClaimStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\PayslipAdjustment;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Services\NotificationService;
use App\Support\Hrms\Auditable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Handles expense reimbursement into payroll runs and retrieval of payable claims.
 */
class ExpenseReimbursement
{
    public function __construct(
        private readonly HrmsAuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Pay an approved claim through a run: one earning line referencing the
     * claim, and the claim stamped paid. Returns null when there is nothing
     * to pay — no payslip for the employee on the run, or an already-paid
     * claim — so a recalculation can call this per claim without ever
     * double-paying (the acceptance's "exactly once").
     */
    public function reimburse(ExpenseClaim $claim, PayrollRun $run, ?User $actor = null): ?PayslipAdjustment
    {
        if ($claim->status !== ExpenseClaimStatus::Approved) {
            return null;
        }

        return DB::transaction(function () use ($claim, $run, $actor): ?PayslipAdjustment {
            $payslip = $run->payslips()->where('employee_id', $claim->employee_id)->first();

            if ($payslip === null || $claim->paid_in_payroll_run_id !== null) {
                return null;
            }

            $adjustment = $payslip->adjustments()->create([
                'kind' => 'earning',
                'label' => "Expense {$claim->claim_number}: {$claim->purpose}",
                'amount' => (string) $claim->approved_amount,
                'reference_type' => 'expense_claim',
                'reference_id' => $claim->id,
                'actor_user_id' => $actor?->id,
                'created_at' => now(),
            ]);

            $claim->update([
                'status' => ExpenseClaimStatus::Paid,
                'reimbursed_amount' => (string) $claim->approved_amount,
                'paid_in_payroll_run_id' => $run->id,
                'paid_via' => 'payroll',
            ]);

            $this->audit->log($claim->refresh(), 'expense.reimbursed', null, Auditable::snapshot($claim->refresh(), ['employee_id', 'claim_number', 'status']), $actor);
            $this->notifications->expensePaid($claim->refresh(), $actor);

            return $adjustment;
        });
    }

    /**
     * Approved, unpaid claims naming this run's period: the payroll loop's
     * shopping list, ordered oldest first so a recalculation pays in filing
     * order.
     *
     * @return Collection<int, ExpenseClaim>
     */
    public function payableFor(Employee $employee, PayrollRun $run): Collection
    {
        return ExpenseClaim::query()->where('employee_id', $employee->id)
            ->where('status', ExpenseClaimStatus::Approved)
            ->where('period_year', $run->period_year)
            ->where('period_month', $run->period_month)
            ->whereNull('paid_in_payroll_run_id')
            ->orderBy('id')
            ->get();
    }
}
