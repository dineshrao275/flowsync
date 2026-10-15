<?php

namespace App\Services\Hrms\Shared;

use App\Enums\Hrms\ApprovalStatus;
use App\Enums\Hrms\ApprovalStepMode;
use App\Enums\Hrms\ApprovalStepStatus;
use App\Models\Hrms\Shared\Approval;
use App\Models\Hrms\Shared\ApprovalStep;
use App\Models\User;
use App\Services\HrmsAuditLogger;

/**
 * Shared/HRMS — moving an approval through its stages (P2.4).
 *
 * A stage is the set of steps sharing a `stage` number. A `sequential` step is
 * a stage of one (the only shape before v2). `parallel_any` settles the stage
 * on the first approval and skips the rest; `parallel_all` waits for every
 * step. A reject anywhere stops the whole approval (see ApprovalService).
 *
 * Each time the cursor moves, the new stage's SLA starts: `due_at` is reset and
 * the once-only reminder/escalation stamps are cleared.
 */
class ApprovalStages
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * Point the cursor at the first actionable step and start its SLA.
     * Returns false when no step is actionable.
     */
    public function open(Approval $approval): bool
    {
        $first = $approval->steps()
            ->where('status', ApprovalStepStatus::Pending)
            ->orderBy('step_order')
            ->first();

        if ($first === null) {
            return false;
        }

        $this->moveCursor($approval, $first);

        return true;
    }

    /**
     * After a step was approved: settle the stage, then advance or resolve.
     */
    public function afterApproval(Approval $approval, ApprovalStep $step, User $actor): Approval
    {
        $stage = $step->stageNumber();

        if ($step->mode === ApprovalStepMode::ParallelAny) {
            $approval->steps()
                ->inStage($stage)
                ->where('status', ApprovalStepStatus::Pending)
                ->update(['status' => ApprovalStepStatus::Skipped, 'note' => 'Settled by another approver of the group.']);
        }

        $stillWaiting = $approval->steps()
            ->inStage($stage)
            ->where('status', ApprovalStepStatus::Pending)
            ->exists();

        if ($stillWaiting) {
            return $approval->fresh(['steps']);
        }

        $next = $approval->steps()
            ->afterStage($stage)
            ->where('status', ApprovalStepStatus::Pending)
            ->orderBy('step_order')
            ->first();

        if ($next !== null) {
            $this->moveCursor($approval, $next);

            return $approval->fresh(['steps']);
        }

        $approval->update([
            'status' => ApprovalStatus::Approved,
            'resolved_at' => now(),
            'resolved_by_user_id' => $actor->id,
            'due_at' => null,
        ]);

        return $approval->fresh(['steps']);
    }

    /**
     * Resolve an approval that has no actionable step left (every step was
     * skipped at request time) — otherwise it would sit `pending` with nobody
     * able to act on it.
     */
    public function settleIfDeadOnArrival(Approval $approval, ?User $actor): Approval
    {
        if (! $approval->isOpen()
            || $approval->steps()->where('status', ApprovalStepStatus::Pending)->exists()) {
            return $approval;
        }

        $approval->update([
            'status' => ApprovalStatus::Approved,
            'resolved_at' => now(),
            'resolved_by_user_id' => $actor?->id,
            'decision_note' => 'No approver was resolvable; auto-approved.',
            'due_at' => null,
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

    private function moveCursor(Approval $approval, ApprovalStep $step): void
    {
        $approval->update([
            'current_step' => $step->step_order,
            'due_at' => $step->sla_hours !== null ? now()->addHours($step->sla_hours) : null,
            'reminded_at' => null,
            'escalated_at' => null,
        ]);
    }
}
