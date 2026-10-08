<?php

namespace App\Services\Hrms\Shared;

use App\Enums\Hrms\ApprovalStatus;
use App\Enums\Hrms\ApprovalStepStatus;
use App\Models\Hrms\Shared\Approval;
use App\Models\Hrms\Shared\ApprovalStep;
use App\Models\User;
use App\Services\Hrms\Shared\ValueObjects\ApproverSpec;
use App\Services\HrmsAuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared/HRMS — the generic multi-step approval engine.
 *
 * One engine for every approvable thing (leave, expenses, salary revisions,
 * offboarding clearance, documents). Callers hand it a subject model and a
 * list of {@see ApproverSpec} steps; the engine owns the state machine:
 *
 *   - steps advance in `step_order`, 1-based and dense;
 *   - a step with no candidate is marked `skipped`, never left pending, so a
 *     chain cannot deadlock on a person who does not exist;
 *   - a reject stops the flow at the current step;
 *   - approving the last actionable step resolves the approval.
 *
 * The engine does not decide *who* a requester's manager is — that belongs to
 * the context owning the employee graph. Callers resolve it and pass a
 * `Manager`/`DepartmentHead` spec with `userId` set.
 */
class ApprovalService
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * Open an approval chain for a subject.
     *
     * @param  ApproverSpec|iterable<ApproverSpec>  $approverSpec  One spec for a
     *                                                             single-step flow, or
     *                                                             several for a chain.
     * @param  array<string, mixed>|null  $meta  Non-sensitive context for the
     *                                           approval screen (dates, amounts
     *                                           already masked upstream).
     *
     * @throws ValidationException when no steps are supplied
     */
    public function request(
        ApproverSpec|iterable $approverSpec,
        Model $subject,
        string $action = 'approve',
        ?string $subjectLabel = null,
        ?array $meta = null,
        ?User $requester = null,
        ?int $requesterEmployeeId = null,
    ): Approval {
        $steps = $approverSpec instanceof ApproverSpec
            ? [$approverSpec]
            : [...$approverSpec];

        if ($steps === []) {
            throw ValidationException::withMessages([
                'approver_spec' => 'An approval needs at least one step.',
            ]);
        }

        $approval = DB::transaction(function () use ($steps, $subject, $action, $subjectLabel, $meta, $requester, $requesterEmployeeId): Approval {
            $approval = Approval::create([
                'approvable_type' => $subject->getMorphClass(),
                'approvable_id' => $subject->getKey(),
                'subject' => $subjectLabel ?? class_basename($subject),
                'action' => $action,
                'status' => ApprovalStatus::Pending,
                'requested_by_user_id' => $requester?->id,
                'requested_by_employee_id' => $requesterEmployeeId,
                'current_step' => 1,
                'meta' => $meta,
            ]);

            $order = 1;

            foreach ($steps as $spec) {
                ApprovalStep::create([
                    'approval_id' => $approval->id,
                    'step_order' => $order,
                    'approver_type' => $spec->type,
                    'approver_role_id' => $spec->roleId,
                    'approver_user_id' => $spec->userId,
                    'approver_employee_id' => $spec->employeeId,
                    // A step nobody can act on is skipped up front rather than
                    // stalling the chain when it is reached.
                    'status' => $spec->hasCandidate()
                        ? ApprovalStepStatus::Pending
                        : ApprovalStepStatus::Skipped,
                ]);

                $order++;
            }

            // Point the persisted cursor at the first *actionable* step, so it
            // never disagrees with `currentStepRecord()` when leading steps
            // were skipped.
            $firstPending = ApprovalStep::query()
                ->where('approval_id', $approval->id)
                ->where('status', ApprovalStepStatus::Pending)
                ->orderBy('step_order')
                ->value('step_order');

            if ($firstPending !== null) {
                $approval->update(['current_step' => $firstPending]);
            }

            return $approval->fresh(['steps']);
        });

        $this->audit->log(
            subject: $approval,
            action: 'approval.requested',
            before: null,
            after: ['status' => $approval->status->value, 'steps' => count($steps)],
            actor: $requester,
        );

        // A chain whose only steps were all skipped is dead on arrival; resolve
        // it so it does not sit in a pending queue forever.
        return $this->settleIfNoActionableStep($approval, $requester);
    }

    /**
     * Approve the current step and advance.
     *
     * @throws ValidationException when the user cannot act on this step
     */
    public function approve(Approval $approval, User $actor, ?string $note = null): Approval
    {
        $step = $this->assertCanAct($approval, $actor);

        $this->audit->log(
            subject: $approval,
            action: 'approval.approved',
            before: ['step' => $step->step_order, 'status' => $step->status->value],
            after: ['step' => $step->step_order, 'status' => ApprovalStepStatus::Approved->value],
            actor: $actor,
        );

        $step->update([
            'status' => ApprovalStepStatus::Approved,
            'acted_at' => now(),
            'acted_by_user_id' => $actor->id,
            'note' => $note,
        ]);

        return $this->advance($approval, $actor);
    }

    /**
     * Reject the current step. The flow stops here.
     *
     * @throws ValidationException when the user cannot act on this step
     */
    public function reject(Approval $approval, User $actor, ?string $note = null): Approval
    {
        $step = $this->assertCanAct($approval, $actor);

        $this->audit->log(
            subject: $approval,
            action: 'approval.rejected',
            before: ['status' => $step->status->value],
            after: ['status' => ApprovalStepStatus::Rejected->value, 'step' => $step->step_order],
            actor: $actor,
        );

        $step->update([
            'status' => ApprovalStepStatus::Rejected,
            'acted_at' => now(),
            'acted_by_user_id' => $actor->id,
            'note' => $note,
        ]);

        $approval->update([
            'status' => ApprovalStatus::Rejected,
            'resolved_at' => now(),
            'resolved_by_user_id' => $actor->id,
            'decision_note' => $note,
        ]);

        return $approval->fresh(['steps']);
    }

    /**
     * Withdraw a pending request. Only the requester may cancel.
     */
    public function cancel(Approval $approval, User $actor, ?string $note = null): Approval
    {
        if (! $this->isRequester($approval, $actor)) {
            throw ValidationException::withMessages([
                'approval' => 'Only the requester can cancel this approval.',
            ]);
        }

        if (! $approval->isOpen()) {
            throw ValidationException::withMessages([
                'approval' => 'This approval is already resolved.',
            ]);
        }

        $this->audit->log(
            subject: $approval,
            action: 'approval.cancelled',
            before: ['status' => $approval->status->value],
            after: ['status' => ApprovalStatus::Cancelled->value],
            actor: $actor,
        );

        $approval->update([
            'status' => ApprovalStatus::Cancelled,
            'resolved_at' => now(),
            'resolved_by_user_id' => $actor->id,
            'decision_note' => $note,
        ]);

        return $approval->fresh(['steps']);
    }

    /**
     * Steps waiting on the given user.
     *
     * This is the "my approvals" inbox. Role steps are matched in SQL by
     * joining the user's roles, so an approver holding a role sees the step
     * without the query loading every candidate step and filtering in PHP.
     *
     * @return Collection<int, ApprovalStep>
     */
    public function pendingFor(User $user): Collection
    {
        $roleIds = $user->roles()->pluck('roles.id');

        return ApprovalStep::query()
            ->where('status', ApprovalStepStatus::Pending)
            ->whereHas('approval', fn ($query) => $query->where('status', ApprovalStatus::Pending))
            ->where(function ($query) use ($user, $roleIds) {
                $query->where('approver_user_id', $user->id);

                if ($roleIds->isNotEmpty()) {
                    $query->orWhereIn('approver_role_id', $roleIds);
                }
            })
            ->with(['approval.requester', 'approverRole'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether the user may act on the approval's current step.
     *
     * Only the step's own approver — a resolved user, or any holder of the
     * step's role. There is deliberately **no** administrator bypass: a tenant
     * admin approving their own payroll revision is exactly the case a review
     * trail must make visible, so an override, if it is ever wanted, needs its
     * own permission and its own audit action rather than a silent `|| true`.
     */
    public function canAct(Approval $approval, User $user): bool
    {
        $step = $approval->currentStepRecord();

        if ($step === null) {
            return false;
        }

        return $step->canBeActedBy($user);
    }

    /**
     * Advance to the next actionable step, or resolve the approval.
     *
     * Skips over already-terminal steps (approved, rejected, skipped) so a
     * chain with gaps still lands on the right step.
     */
    private function advance(Approval $approval, User $actor): Approval
    {
        $next = $approval->steps()
            ->where('step_order', '>', $approval->current_step)
            ->where('status', ApprovalStepStatus::Pending)
            ->orderBy('step_order')
            ->first();

        if ($next !== null) {
            $approval->update(['current_step' => $next->step_order]);

            return $approval->fresh(['steps']);
        }

        $approval->update([
            'status' => ApprovalStatus::Approved,
            'resolved_at' => now(),
            'resolved_by_user_id' => $actor->id,
        ]);

        return $approval->fresh(['steps']);
    }

    /**
     * Resolve an approval that has no actionable step left.
     *
     * Reached when every step was skipped at request time. Without this the
     * flow would be `pending` with nobody able to act on it.
     */
    private function settleIfNoActionableStep(Approval $approval, ?User $actor): Approval
    {
        if (! $approval->isOpen()) {
            return $approval;
        }

        $hasActionable = $approval->steps()
            ->where('status', ApprovalStepStatus::Pending)
            ->exists();

        if ($hasActionable) {
            return $approval;
        }

        $approval->update([
            'status' => ApprovalStatus::Approved,
            'resolved_at' => now(),
            'resolved_by_user_id' => $actor?->id,
            'decision_note' => 'No approver was resolvable; auto-approved.',
        ]);

        $this->audit->log(
            subject: $approval,
            action: 'approval.auto_approved',
            before: ['status' => ApprovalStatus::Pending->value],
            after: ['status' => ApprovalStatus::Approved->value],
            actor: $actor,
        );

        return $approval->fresh(['steps']);
    }

    /**
     * @throws ValidationException
     */
    private function assertCanAct(Approval $approval, User $actor): ApprovalStep
    {
        if (! $approval->isOpen()) {
            throw ValidationException::withMessages([
                'approval' => 'This approval is already resolved.',
            ]);
        }

        $step = $approval->currentStepRecord();

        if ($step === null) {
            throw ValidationException::withMessages([
                'approval' => 'This approval has no actionable step.',
            ]);
        }

        if (! $this->canAct($approval, $actor)) {
            throw ValidationException::withMessages([
                'approval' => 'You are not an approver for this step.',
            ]);
        }

        // Eager-load the role the canAct() check depends on.
        return $step->load('approverRole');
    }

    private function isRequester(Approval $approval, User $actor): bool
    {
        return $approval->requested_by_user_id !== null
            && $approval->requested_by_user_id === $actor->id;
    }
}
