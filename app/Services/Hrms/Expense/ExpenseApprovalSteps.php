<?php

declare(strict_types=1);

namespace App\Services\Hrms\Expense;

use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\User;
use App\Services\Hrms\Shared\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Expense/HRMS — completing a claim's approval chain, one step at a time.
 *
 * `ExpenseService::approve()` prices the claim and only accepts a *resolved* chain; this is the
 * missing hand that resolves it. Who may act is the engine's answer (the step's approver, their
 * delegate, or the audited override), already checked by the policy and again by the engine.
 */
class ExpenseApprovalSteps
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ExpenseService $expenses,
    ) {}

    /**
     * Approval ids of expense claims whose current step the user can act on (own seat or delegated):
     * one query for a whole list, so the page can show who may clear a step without a check per row.
     *
     * @return list<int>
     */
    public function actableApprovalIds(User $user): array
    {
        return $this->approvals->pendingFor($user)
            ->filter(fn ($step): bool => $step->approval?->domain === 'expense')
            ->pluck('approval_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Clear the caller's step of the current stage. The claim stays `submitted`: the money decision
     * is the separate, priced `decide` once the chain is resolved.
     *
     * @throws ValidationException when the caller cannot act or an override has no reason
     */
    public function approve(ExpenseClaim $claim, User $actor, ?string $note = null): ExpenseClaim
    {
        $this->approvals->approve($claim->approval, $actor, $note);

        return $claim->refresh();
    }

    /**
     * Reject at the caller's step: the whole flow stops, and the claim is closed with the reason.
     *
     * @throws ValidationException without a reason, or when the caller cannot act
     */
    public function reject(ExpenseClaim $claim, User $actor, ?string $note = null): ExpenseClaim
    {
        if ($note === null || trim($note) === '') {
            throw ValidationException::withMessages(['note' => __('A rejection says why, so the employee can act on it.')]);
        }

        return DB::transaction(function () use ($claim, $actor, $note): ExpenseClaim {
            $this->approvals->reject($claim->approval, $actor, $note);

            return $this->expenses->reject($claim, $actor, $note);
        });
    }
}
