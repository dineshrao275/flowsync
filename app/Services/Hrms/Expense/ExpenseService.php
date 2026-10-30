<?php

declare(strict_types=1);

namespace App\Services\Hrms\Expense;

use App\Enums\Hrms\ExpenseClaimStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\PayslipAdjustment;
use App\Models\User;
use App\Services\Hrms\Approval\ChainBuilder;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\HrmsAuditLogger;
use App\Services\NotificationService;
use App\Support\Hrms\Auditable;
use App\Support\Hrms\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Expense/HRMS — claims from filing to reimbursement.
 *
 * Six public methods, one per verb: file with items, replace the items,
 * submit into the (template-driven, P2.5) chain, decide both ways, and reimburse into payroll.
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
        private readonly ChainBuilder $chains,
        private readonly HrmsAuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly ExpenseItemRules $rules,
        private readonly ExpenseReimbursement $reimbursement,
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
        $lines = $this->rules->validatedItems($employee, $items);

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => __('A claim names at least one line.')]);
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
                'total_amount' => $this->rules->sum($lines),
                'status' => ExpenseClaimStatus::Draft,
            ]);

            $claim->update(['claim_number' => $this->claimNumber($claim)]);
            $claim->items()->createMany($lines);
            $claim->update(['total_amount' => $this->rules->sum($lines)]);

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
        $lines = $this->rules->validatedItems($claim->employee, $items);

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => __('A claim names at least one line.')]);
        }

        return DB::transaction(function () use ($claim, $lines, $actor): ExpenseClaim {
            $claim->items()->delete();
            $claim->items()->createMany($lines);
            $claim->update(['total_amount' => $this->rules->sum($lines)]);

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
            $approval = $this->approvals->request(
                $this->chains->stepsFor('expense', $claim->employee, ['amount' => $claim->total_amount]),
                $claim,
                'expense.decide',
                'Expense claim',
                ['claim_number' => $claim->claim_number],
                $actor,
                $claim->employee_id,
                'expense',
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
            throw ValidationException::withMessages(['form' => __('Resolve the approval chain before approving.')]);
        }

        $total = Money::fromDecimal((string) $claim->total_amount);
        $approved = $approvedAmount === null ? $total : Money::fromDecimal($approvedAmount);

        if ($approved->isNegative() || $approved->minor > $total->minor) {
            throw ValidationException::withMessages(['approved_amount' => __('An approval prices between nothing and the filed total.')]);
        }

        if ($approved->minor < $total->minor && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages(['reason' => __('A reduced approval says why, so the employee can act on it.')]);
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
            throw ValidationException::withMessages(['reason' => __('A rejection says why, so the employee can act on it.')]);
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
     * claim, and the claim stamped paid.
     */
    public function reimburse(ExpenseClaim $claim, PayrollRun $run, ?User $actor = null): ?PayslipAdjustment
    {
        return $this->reimbursement->reimburse($claim, $run, $actor);
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
        return $this->reimbursement->payableFor($employee, $run);
    }

    /**
     * @throws ValidationException when the decider owns the claim
     */
    private function requireDecider(ExpenseClaim $claim, User $actor): void
    {
        $ownerId = $claim->employee?->user_id;

        if ($ownerId !== null && (int) $ownerId === (int) $actor->id) {
            throw ValidationException::withMessages(['form' => __('A claim is never decided by its own owner.')]);
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
        return Auditable::snapshot($claim, ['employee_id', 'claim_number', 'status']);
    }
}
