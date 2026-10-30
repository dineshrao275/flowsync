<?php

declare(strict_types=1);

namespace App\Services\Hrms\Payroll;

use App\Enums\Hrms\ApprovalStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\EmployeeLoan;
use App\Models\Hrms\Payroll\LoanInstallment;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\User;
use App\Services\Hrms\Approval\ChainBuilder;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\HrmsAuditLogger;
use App\Support\Hrms\Auditable;
use App\Support\Hrms\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loans and salary advances (F7.6). A request goes through the approval engine (domain `loan`); on approval
 * the equal-instalment schedule is written; each payroll run then deducts the instalments that have come due
 * as an ordinary payslip adjustment, so the payslip, the totals and a recalculation all behave as for any other
 * adjustment. An instalment that would push net pay below zero is deferred to a later run, never forgiven.
 */
class LoanService
{
    private const MAX_INSTALMENTS = 60;

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ChainBuilder $chains,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /** @throws ValidationException */
    public function request(Employee $employee, string $kind, string $principal, int $installments, int $firstYear, int $firstMonth, ?string $reason, User $actor): EmployeeLoan
    {
        $amount = Money::fromDecimal($principal);
        if (! in_array($kind, EmployeeLoan::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('Choose loan or advance.')]);
        }
        if ($amount->isNegative() || $amount->isZero()) {
            throw ValidationException::withMessages(['principal' => __('A loan is for positive money.')]);
        }
        if ($installments < 1 || $installments > self::MAX_INSTALMENTS) {
            throw ValidationException::withMessages(['installments' => __('Choose between 1 and :max instalments.', ['max' => self::MAX_INSTALMENTS])]);
        }
        if ($firstMonth < 1 || $firstMonth > 12) {
            throw ValidationException::withMessages(['first_month' => __('Choose a month from 1 to 12.')]);
        }
        if (EmployeeLoan::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'active'])->exists()) {
            throw ValidationException::withMessages(['form' => __('This person already has a loan that is open or waiting for a decision.')]);
        }

        return DB::transaction(function () use ($employee, $kind, $amount, $installments, $firstYear, $firstMonth, $reason, $actor): EmployeeLoan {
            $loan = EmployeeLoan::create([
                'employee_id' => $employee->id,
                'kind' => $kind,
                'principal' => $amount->toDecimal(),
                'installments' => $installments,
                'installment_amount' => Money::allocate($amount->minor, $installments)[0]->toDecimal(),
                'first_year' => $firstYear,
                'first_month' => $firstMonth,
                'balance' => $amount->toDecimal(),
                'reason' => $reason !== null ? mb_substr(trim($reason), 0, 500) : null,
                'status' => 'pending',
                'requested_by_user_id' => $actor->id,
            ]);

            $approval = $this->approvals->request(
                $this->chains->stepsFor('loan', $employee), $loan, 'loan.request', ucfirst($kind),
                ['amount' => $amount->toDecimal()], $actor, $employee->id, 'loan',
            );
            $loan->update(['approval_id' => $approval->id]);
            $this->audit->log($loan, 'loan.requested', null, $this->snapshot($loan->refresh()), $actor);

            return $approval->status === ApprovalStatus::Approved ? $this->activate($loan, $actor, null) : $loan->refresh();
        });
    }

    public function approve(EmployeeLoan $loan, User $actor, ?string $note = null): EmployeeLoan
    {
        $this->requirePending($loan);
        $approval = $this->approvals->approve($loan->approval, $actor, $note);

        return $approval->status === ApprovalStatus::Approved ? $this->activate($loan, $actor, $note) : $loan->refresh();
    }

    public function reject(EmployeeLoan $loan, User $actor, string $note): EmployeeLoan
    {
        $this->requirePending($loan);
        if (trim($note) === '') {
            throw ValidationException::withMessages(['decision_note' => __('A rejection needs a reason the requester can act on.')]);
        }

        $this->approvals->reject($loan->approval, $actor, $note);
        $loan->update(['status' => 'rejected', 'decided_at' => now(), 'decision_note' => mb_substr(trim($note), 0, 500)]);
        $this->audit->log($loan, 'loan.rejected', ['status' => 'pending'], $this->snapshot($loan->refresh()), $actor);

        return $loan->refresh();
    }

    public function withdraw(EmployeeLoan $loan, User $actor): EmployeeLoan
    {
        $this->requirePending($loan);
        $this->approvals->cancel($loan->approval, $actor);
        $loan->update(['status' => 'cancelled', 'decided_at' => now()]);
        $this->audit->log($loan, 'loan.withdrawn', ['status' => 'pending'], $this->snapshot($loan->refresh()), $actor);

        return $loan->refresh();
    }

    /** Stop deducting. Instalments already taken stay taken; the rest are dropped. */
    public function cancelActive(EmployeeLoan $loan, User $actor): EmployeeLoan
    {
        if ($loan->status !== 'active') {
            throw ValidationException::withMessages(['form' => __('Only an active loan can be stopped.')]);
        }

        LoanInstallment::query()->where('employee_loan_id', $loan->id)->where('status', 'due')->delete();
        $loan->update(['status' => 'cancelled', 'decided_at' => now()]);
        $this->audit->log($loan, 'loan.stopped', ['status' => 'active'], $this->snapshot($loan->refresh()), $actor);

        return $loan->refresh();
    }

    /**
     * The payroll hook: deduct this employee's due instalments (this month or earlier) from the payslip just built.
     *
     * @return bool whether any instalment landed (the caller refreshes the payslip totals)
     */
    public function deductDue(PayrollRun $run, Payslip $payslip, ?User $actor = null): bool
    {
        $due = LoanInstallment::query()
            ->whereHas('loan', fn ($q) => $q->where('employee_id', $payslip->employee_id)->where('status', 'active'))
            ->where('status', 'due')
            ->where(fn ($q) => $q->where('due_year', '<', $run->period_year)->orWhere(fn ($w) => $w->where('due_year', $run->period_year)->where('due_month', '<=', $run->period_month)))
            ->orderBy('due_year')->orderBy('due_month')->orderBy('number')
            ->with('loan')
            ->get();

        $headroom = Money::fromDecimal((string) $payslip->net_pay);
        $landed = false;

        foreach ($due as $installment) {
            $amount = Money::fromDecimal((string) $installment->amount);
            if ($amount->minor > $headroom->minor) {
                break; // keep the order: a later instalment never jumps the queue
            }

            DB::transaction(function () use ($installment, $payslip, $run, $actor, $amount): void {
                $payslip->adjustments()->create([
                    'kind' => 'deduction',
                    'label' => ucfirst($installment->loan->kind)." instalment {$installment->number}/{$installment->loan->installments}",
                    'amount' => $amount->toDecimal(),
                    'reference_type' => 'loan_installment',
                    'reference_id' => $installment->id,
                    'actor_user_id' => $actor?->id,
                    'created_at' => now(),
                ]);

                $installment->update(['status' => 'paid', 'payroll_run_id' => $run->id, 'paid_at' => now()]);
                $loan = $installment->loan;
                $balance = Money::fromDecimal((string) $loan->balance)->sub($amount);
                $loan->update(['balance' => $balance->toDecimal(), 'status' => $balance->minor <= 0 ? 'closed' : 'active']);
                $this->audit->log($loan, 'loan.instalment_deducted', null, ['installment' => $installment->number, 'run' => $run->id], $actor);
            });

            $headroom = $headroom->sub($amount);
            $landed = true;
        }

        return $landed;
    }

    private function activate(EmployeeLoan $loan, ?User $actor, ?string $note): EmployeeLoan
    {
        return DB::transaction(function () use ($loan, $actor, $note): EmployeeLoan {
            $parts = Money::allocate(Money::fromDecimal((string) $loan->principal)->minor, $loan->installments);
            $year = $loan->first_year;
            $month = $loan->first_month;

            foreach ($parts as $i => $part) {
                LoanInstallment::create([
                    'employee_loan_id' => $loan->id, 'number' => $i + 1, 'due_year' => $year, 'due_month' => $month,
                    'amount' => $part->toDecimal(), 'status' => 'due',
                ]);
                if (++$month > 12) {
                    $month = 1;
                    $year++;
                }
            }

            $loan->update(['status' => 'active', 'decided_at' => now(), 'decision_note' => $note !== null ? mb_substr(trim($note), 0, 500) : null]);
            $this->audit->log($loan, 'loan.approved', ['status' => 'pending'], $this->snapshot($loan->refresh()), $actor);

            return $loan->refresh();
        });
    }

    private function requirePending(EmployeeLoan $loan): void
    {
        if (! $loan->isPending() || $loan->approval === null) {
            throw ValidationException::withMessages(['form' => __('This loan has already been decided.')]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(EmployeeLoan $loan): array
    {
        return Auditable::snapshot($loan, ['employee_id', 'kind', 'principal', 'installments', 'status', 'approval_id']);
    }
}
