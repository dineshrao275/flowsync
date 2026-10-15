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
    /** Permission that lets someone act on a step they were not assigned (audited). */
    public const OVERRIDE_PERMISSION = 'hrms.approvals.override';

    public function __construct(
        private readonly HrmsAuditLogger $audit,
        private readonly ApprovalActors $actors,
        private readonly ApprovalStages $stages,
        private readonly ApprovalDelegations $delegations,
    ) {}

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
        ?string $domain = null,
        ?int $resubmissionOfId = null,
    ): Approval {
        $steps = $approverSpec instanceof ApproverSpec
            ? [$approverSpec]
            : [...$approverSpec];

        if ($steps === []) {
            throw ValidationException::withMessages([
                'approver_spec' => 'An approval needs at least one step.',
            ]);
        }

        $approval = DB::transaction(function () use ($steps, $subject, $action, $subjectLabel, $meta, $requester, $requesterEmployeeId, $domain, $resubmissionOfId): Approval {
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
                'domain' => $domain,
                'resubmission_of_id' => $resubmissionOfId,
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
                    'stage' => $spec->stage ?? $order,
                    'mode' => $spec->mode,
                    'sla_hours' => $spec->slaHours,
                    // A step nobody can act on is skipped up front rather than
                    // stalling the chain when it is reached.
                    'status' => $spec->hasCandidate()
                        ? ApprovalStepStatus::Pending
                        : ApprovalStepStatus::Skipped,
                ]);

                $order++;
            }

            // Point the persisted cursor at the first *actionable* step (and
            // start its SLA), so it never disagrees with `currentStepRecord()`
            // when leading steps were skipped.
            $this->stages->open($approval);

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
        return $this->stages->settleIfDeadOnArrival($approval, $requester);
    }

    /**
     * Resubmit a rejected approval: a NEW approval for the same subject
     * (a resolved flow is closed, never reopened), linked back through
     * `resubmission_of_id`. Only the requester may, and only for the latest
     * rejected approval of that subject.
     *
     * @param  ApproverSpec|iterable<ApproverSpec>  $approverSpec
     *
     * @throws ValidationException
     */
    public function resubmit(Approval $previous, ApproverSpec|iterable $approverSpec, User $actor, ?array $meta = null): Approval
    {
        if (! $this->actors->isRequester($previous, $actor)) {
            throw ValidationException::withMessages(['approval' => 'Only the requester can resubmit this approval.']);
        }

        if ($previous->status !== ApprovalStatus::Rejected) {
            throw ValidationException::withMessages(['approval' => 'Only a rejected approval can be resubmitted.']);
        }

        $superseded = Approval::query()
            ->where('approvable_type', $previous->approvable_type)
            ->where('approvable_id', $previous->approvable_id)
            ->where('id', '>', $previous->id)
            ->exists();

        if ($superseded) {
            throw ValidationException::withMessages(['approval' => 'This approval has already been resubmitted.']);
        }

        return $this->request(
            $approverSpec,
            $previous->approvable,
            $previous->action,
            $previous->subject,
            $meta ?? $previous->meta,
            $actor,
            $previous->requested_by_employee_id,
            $previous->domain,
            $previous->id,
        );
    }

    /**
     * Approve the user's step of the current stage and advance.
     *
     * @throws ValidationException when the user cannot act on this step
     */
    public function approve(Approval $approval, User $actor, ?string $note = null): Approval
    {
        $pick = $this->assertCanAct($approval, $actor);
        $step = $pick['step'];
        $this->requireOverrideNote($pick['via'], $note);

        $this->audit->log(
            subject: $approval,
            action: $this->auditAction($pick['via'], 'approved'),
            before: ['step' => $step->step_order, 'status' => $step->status->value],
            after: ['step' => $step->step_order, 'status' => ApprovalStepStatus::Approved->value],
            actor: $actor,
        );

        $step->update([
            'status' => ApprovalStepStatus::Approved,
            'acted_at' => now(),
            'acted_by_user_id' => $actor->id,
            'acted_for_user_id' => $pick['for'],
            'note' => $note,
        ]);

        return $this->stages->afterApproval($approval, $step, $actor);
    }

    /**
     * Reject the user's step. The whole flow stops here, whatever the stage mode.
     *
     * @throws ValidationException when the user cannot act on this step
     */
    public function reject(Approval $approval, User $actor, ?string $note = null): Approval
    {
        $pick = $this->assertCanAct($approval, $actor);
        $step = $pick['step'];
        $this->requireOverrideNote($pick['via'], $note);

        $this->audit->log(
            subject: $approval,
            action: $this->auditAction($pick['via'], 'rejected'),
            before: ['status' => $step->status->value],
            after: ['status' => ApprovalStepStatus::Rejected->value, 'step' => $step->step_order],
            actor: $actor,
        );

        $step->update([
            'status' => ApprovalStepStatus::Rejected,
            'acted_at' => now(),
            'acted_by_user_id' => $actor->id,
            'acted_for_user_id' => $pick['for'],
            'note' => $note,
        ]);

        $approval->update([
            'status' => ApprovalStatus::Rejected,
            'resolved_at' => now(),
            'resolved_by_user_id' => $actor->id,
            'decision_note' => $note,
            'due_at' => null,
        ]);

        return $approval->fresh(['steps']);
    }

    /**
     * Withdraw a pending request. Only the requester may cancel.
     */
    public function cancel(Approval $approval, User $actor, ?string $note = null): Approval
    {
        if (! $this->actors->isRequester($approval, $actor)) {
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
            'due_at' => null,
        ]);

        return $approval->fresh(['steps']);
    }

    /**
     * Steps waiting on the given user — their own, plus those of anyone who
     * delegated to them in an active window (restricted to the delegation's
     * domains).
     *
     * Role steps are matched in SQL by joining the roles, so an approver
     * holding a role sees the step without the query loading every candidate
     * step and filtering in PHP.
     *
     * @return Collection<int, ApprovalStep>
     */
    public function pendingFor(User $user): Collection
    {
        $seats = [[$user, null]];

        foreach ($this->delegations->activeTo($user)->load('from') as $delegation) {
            if ($delegation->from !== null) {
                $seats[] = [$delegation->from, $delegation->domains];
            }
        }

        return ApprovalStep::query()
            ->where('status', ApprovalStepStatus::Pending)
            ->whereHas('approval', fn ($query) => $query->where('status', ApprovalStatus::Pending))
            ->where(function ($query) use ($seats) {
                foreach ($seats as [$seat, $domains]) {
                    $query->orWhere(function ($seatQuery) use ($seat, $domains) {
                        $roleIds = $seat->roles()->pluck('roles.id');

                        $seatQuery->where(function ($who) use ($seat, $roleIds) {
                            $who->where('approver_user_id', $seat->id);

                            if ($roleIds->isNotEmpty()) {
                                $who->orWhereIn('approver_role_id', $roleIds);
                            }
                        });

                        if ($domains !== null && $domains !== []) {
                            $seatQuery->whereHas('approval', fn ($a) => $a->whereIn('domain', $domains));
                        }
                    });
                }
            })
            ->with(['approval.requester', 'approverRole'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether the user may act on a step of the approval's current stage:
     * as its own approver, as a delegate of that approver, or through the
     * explicit override permission (see ApprovalActors). There is deliberately
     * **no** administrator bypass: a tenant admin approving their own payroll
     * revision is exactly the case a review trail must make visible.
     */
    public function canAct(Approval $approval, User $user): bool
    {
        return $this->actors->resolve($approval, $user) !== null;
    }

    /** Whether the user holds the R11 override for this approval (see ApprovalActors). */
    public function mayOverride(Approval $approval, User $user): bool
    {
        return $this->actors->mayOverride($approval, $user);
    }

    /**
     * An override (acting for the assigned approver without being their
     * delegate) needs a reason of at least 5 characters.
     *
     * @throws ValidationException
     */
    private function requireOverrideNote(string $via, ?string $note): void
    {
        if ($via === ApprovalActors::OVERRIDE && mb_strlen(trim((string) $note)) < 5) {
            throw ValidationException::withMessages([
                'note' => 'Acting on behalf of the assigned approver needs a reason (at least 5 characters).',
            ]);
        }
    }

    private function auditAction(string $via, string $verb): string
    {
        return match ($via) {
            ApprovalActors::OVERRIDE => 'approval.override_'.$verb,
            ApprovalActors::DELEGATE => 'approval.delegated_'.$verb,
            default => 'approval.'.$verb,
        };
    }

    /**
     * @return array{step: ApprovalStep, via: string, for: int|null}
     *
     * @throws ValidationException
     */
    private function assertCanAct(Approval $approval, User $actor): array
    {
        if (! $approval->isOpen()) {
            throw ValidationException::withMessages([
                'approval' => 'This approval is already resolved.',
            ]);
        }

        if ($approval->currentStageSteps()->isEmpty()) {
            throw ValidationException::withMessages([
                'approval' => 'This approval has no actionable step.',
            ]);
        }

        $pick = $this->actors->resolve($approval, $actor);

        if ($pick === null) {
            throw ValidationException::withMessages([
                'approval' => 'You are not an approver for this step.',
            ]);
        }

        // Eager-load the role the canAct() check depends on.
        $pick['step']->load('approverRole');

        return $pick;
    }
}
