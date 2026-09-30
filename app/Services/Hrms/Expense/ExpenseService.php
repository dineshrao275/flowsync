<?php

namespace App\Services\Hrms\Expense;

use App\Enums\Hrms\ApproverType;
use App\Enums\Hrms\ExpenseClaimStatus;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseCategory;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\PayslipAdjustment;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrms\Employee\ReportingLine;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\Hrms\Shared\ValueObjects\ApproverSpec;
use App\Services\HrmsAuditLogger;
use App\Services\NotificationService;
use App\Support\Hrms\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Expense/HRMS — claims from filing to reimbursement.
 *
 * Six public methods, one per verb: file with items, replace the items,
 * submit into the chain, decide both ways, and reimburse into payroll.
 * Totals are recomputed from the items on every write (a client total is
 * never read); submission locks the figures; only a resolved chain
 * approves; and the payroll hand-off is a reference (an adjustment row
 * pointing at the claim), never a service dependency — payroll calls
 * `reimburse()`, expenses never call payroll.
 */
class ExpenseService
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ReportingLine $reporting,
        private readonly HrmsAuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * File a draft claim with its lines. Receipts are checked per line
     * against the category threshold (0 means always); the total is the
     * items' sum; the number is stamped after insert (`EXP-2026-000001`).
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     *
     * @throws ValidationException on a missing receipt or an empty claim
     */
    public function create(Employee $employee, array $data, array $items, ?User $actor = null): ExpenseClaim
    {
        $lines = $this->validatedItems($employee, $items);

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'A claim names at least one line.']);
        }

        return DB::transaction(function () use ($employee, $data, $lines, $actor): ExpenseClaim {
            $claim = ExpenseClaim::create([
                'employee_id' => $employee->id,
                'claim_number' => 'pending',
                'claim_date' => $data['claim_date'],
                'period_year' => $data['period_year'],
                'period_month' => $data['period_month'],
                'purpose' => $data['purpose'],
                'description' => $data['description'] ?? null,
                'currency' => $data['currency'] ?? 'USD',
                'total_amount' => $this->sum($lines),
                'status' => ExpenseClaimStatus::Draft,
            ]);

            $claim->update(['claim_number' => $this->claimNumber($claim)]);
            $claim->items()->createMany($lines);
            $claim->update(['total_amount' => $this->sum($lines)]);

            $this->audit->log($claim->refresh(), 'expense.filed', null, $this->snapshot($claim->refresh()), $actor);

            return $claim->refresh();
        });
    }

    /**
     * Replace a draft's lines wholesale: one call names the full set, so a
     * payload cannot smuggle a line onto another claim, and the total
     * recomputes from what is stored.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @throws ValidationException outside draft
     */
    public function setItems(ExpenseClaim $claim, array $items, ?User $actor = null): ExpenseClaim
    {
        $this->requireStatus($claim, ExpenseClaimStatus::Draft, 'Only a draft claim can be edited.');
        $lines = $this->validatedItems($claim->employee, $items);

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'A claim names at least one line.']);
        }

        return DB::transaction(function () use ($claim, $lines, $actor): ExpenseClaim {
            $claim->items()->delete();
            $claim->items()->createMany($lines);
            $claim->update(['total_amount' => $this->sum($lines)]);

            $this->audit->log($claim->refresh(), 'expense.items_set', null, $this->snapshot($claim->refresh()), $actor);

            return $claim->refresh();
        });
    }

    /**
     * Lock the figures and route the chain: the manager first, then the
     * first role holding `hrms.expenses.approve`. Either leg may resolve
     * candidate-less (no manager, no such role) — the engine skips such a
     * step visibly instead of parking the claim forever.
     *
     * @throws ValidationException outside draft
     */
    public function submit(ExpenseClaim $claim, ?User $actor = null): ExpenseClaim
    {
        $this->requireStatus($claim, ExpenseClaimStatus::Draft, 'Only a draft claim can be submitted.');

        return DB::transaction(function () use ($claim, $actor): ExpenseClaim {
            $manager = $this->reporting->managerOf($claim->employee);

            $approval = $this->approvals->request(
                [
                    new ApproverSpec(ApproverType::Manager, userId: $manager?->user_id, employeeId: $manager?->id),
                    $this->financeStep(),
                ],
                $claim,
                'expense.decide',
                'Expense claim',
                ['claim_number' => $claim->claim_number],
                $actor,
                $claim->employee_id,
            );

            $claim->update(['status' => ExpenseClaimStatus::Submitted, 'approval_id' => $approval->id]);
            $this->audit->log($claim->refresh(), 'expense.submitted', null, $this->snapshot($claim->refresh()), $actor);
            $this->notifications->expenseSubmitted($claim->refresh(), $actor);

            return $claim->refresh();
        });
    }

    /**
     * Approve for the full total, or for less with the reason the employee
     * can act on. Only a resolved chain approves, and never the claim's
     * own owner — a decider with their own claim in the queue waits like
     * everyone else.
     *
     * @throws ValidationException outside submitted, on an open chain, on
     *                             self-approval, or on a cut without a reason
     */
    public function approve(ExpenseClaim $claim, User $actor, ?string $approvedAmount = null, ?string $reason = null): ExpenseClaim
    {
        $this->requireStatus($claim, ExpenseClaimStatus::Submitted, 'Only a submitted claim can be approved.');
        $this->requireDecider($claim, $actor);

        if ($claim->approval === null || $claim->approval->fresh()->status->value !== 'approved') {
            throw ValidationException::withMessages(['form' => 'Resolve the approval chain before approving.']);
        }

        $total = Money::fromDecimal((string) $claim->total_amount);
        $approved = $approvedAmount === null ? $total : Money::fromDecimal($approvedAmount);

        if ($approved->isNegative() || $approved->minor > $total->minor) {
            throw ValidationException::withMessages(['approved_amount' => 'An approval prices between nothing and the filed total.']);
        }

        if ($approved->minor < $total->minor && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages(['reason' => 'A reduced approval says why, so the employee can act on it.']);
        }

        return DB::transaction(function () use ($claim, $actor, $approved): ExpenseClaim {
            $claim->update([
                'status' => ExpenseClaimStatus::Approved,
                'approved_amount' => $approved->toDecimal(),
                'decided_at' => now(),
                'decided_by_user_id' => $actor->id,
            ]);

            $this->audit->log($claim->refresh(), 'expense.approved', null, $this->snapshot($claim->refresh()), $actor);
            $this->notifications->expenseDecided($claim->refresh(), 'submitted', $actor);

            return $claim->refresh();
        });
    }

    /**
     * Reject with the reason the employee can act on. An unexplained
     * rejection answers nothing, so it is refused rather than stored.
     *
     * @throws ValidationException outside submitted or without a reason
     */
    public function reject(ExpenseClaim $claim, User $actor, ?string $reason = null): ExpenseClaim
    {
        $this->requireStatus($claim, ExpenseClaimStatus::Submitted, 'Only a submitted claim can be rejected.');
        $this->requireDecider($claim, $actor);

        if ($reason === null || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A rejection says why, so the employee can act on it.']);
        }

        return DB::transaction(function () use ($claim, $actor): ExpenseClaim {
            $claim->update([
                'status' => ExpenseClaimStatus::Rejected,
                'approved_amount' => '0.00',
                'decided_at' => now(),
                'decided_by_user_id' => $actor->id,
            ]);

            $this->audit->log($claim->refresh(), 'expense.rejected', null, $this->snapshot($claim->refresh()), $actor);
            $this->notifications->expenseDecided($claim->refresh(), 'submitted', $actor);

            return $claim->refresh();
        });
    }

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

            $this->audit->log($claim->refresh(), 'expense.reimbursed', null, $this->snapshot($claim->refresh()), $actor);
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

    /**
     * The finance leg: the first role holding the approve permission, or a
     * candidate-less step the engine skips visibly when no tenant built
     * one. The requester holding that permission does not remove the leg —
     * self-approval is refused at decision time instead, so the chain still
     * names who should have acted.
     */
    private function financeStep(): ApproverSpec
    {
        $roleId = Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('slug', 'hrms.expenses.approve'))
            ->orderBy('id')
            ->value('id');

        return new ApproverSpec(ApproverType::Role, roleId: $roleId === null ? null : (int) $roleId);
    }

    /**
     * @throws ValidationException when the decider owns the claim
     */
    private function requireDecider(ExpenseClaim $claim, User $actor): void
    {
        $ownerId = $claim->employee?->user_id;

        if ($ownerId !== null && (int) $ownerId === (int) $actor->id) {
            throw ValidationException::withMessages(['form' => 'A claim is never decided by its own owner.']);
        }
    }

    /**
     * @throws ValidationException on any other state
     */
    private function requireStatus(ExpenseClaim $claim, ExpenseClaimStatus $expected, string $message): void
    {
        if ($claim->status !== $expected) {
            throw ValidationException::withMessages(['form' => $message]);
        }
    }

    /**
     * One line per item, validated: an active catalogue row (or none — an
     * ad-hoc line names no head), a positive amount, and a receipt where
     * the category demands one. Receipts must belong to the employee, like
     * declaration proofs: evidence for somebody else's money is refused.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException on a bad line
     */
    private function validatedItems(Employee $employee, array $items): array
    {
        $lines = [];

        foreach (array_values($items) as $index => $item) {
            $category = ! empty($item['category_id'])
                ? ExpenseCategory::find($item['category_id'])
                : null;

            if (! empty($item['category_id']) && $category === null) {
                throw ValidationException::withMessages(["items.{$index}.category_id" => 'That category does not exist.']);
            }

            if ($category !== null && ! $category->is_active) {
                throw ValidationException::withMessages(["items.{$index}.category_id" => 'That category is retired.']);
            }

            $amount = Money::fromDecimal((string) ($item['amount'] ?? '0'));

            if ($amount->isNegative() || $amount->isZero()) {
                throw ValidationException::withMessages(["items.{$index}.amount" => 'A line prices positive money.']);
            }

            $receiptId = $item['receipt_document_id'] ?? null;

            if ($receiptId !== null) {
                $receipt = EmployeeDocument::find($receiptId);

                if ($receipt === null || (int) $receipt->employee_id !== (int) $employee->id) {
                    throw ValidationException::withMessages(["items.{$index}.receipt_document_id" => 'That file does not belong to this employee.']);
                }
            }

            $threshold = $category?->requires_receipt_above;

            if ($threshold !== null && $amount->minor > Money::fromDecimal((string) $threshold)->minor && $receiptId === null) {
                throw ValidationException::withMessages(["items.{$index}.receipt_document_id" => "Lines over {$threshold} need a receipt."]);
            }

            $lines[] = [
                'category_id' => $category?->id,
                'description' => (string) ($item['description'] ?? ''),
                'amount' => $amount->toDecimal(),
                'spent_at' => isset($item['spent_at']) ? Carbon::parse((string) $item['spent_at'])->toDateString() : null,
                'vendor' => $item['vendor'] ?? null,
                'receipt_document_id' => $receiptId,
                'is_billable' => (bool) ($item['is_billable'] ?? false),
                'notes' => $item['notes'] ?? null,
            ];
        }

        return $lines;
    }

    private function sum(array $lines): string
    {
        $total = Money::zero();

        foreach ($lines as $line) {
            $total = $total->add(Money::fromDecimal((string) $line['amount']));
        }

        return $total->toDecimal();
    }

    private function claimNumber(ExpenseClaim $claim): string
    {
        return sprintf('EXP-%d-%06d', $claim->claim_date->year, $claim->id);
    }

    /**
     * Identifiers and states only: figures never enter the ledger (D2.17.8).
     *
     * @return array<string, mixed>
     */
    private function snapshot(ExpenseClaim $claim): array
    {
        return [
            'employee_id' => $claim->employee_id,
            'claim_number' => $claim->claim_number,
            'status' => $claim->status->value,
        ];
    }
}
