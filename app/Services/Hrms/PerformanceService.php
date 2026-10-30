<?php

declare(strict_types=1);

namespace App\Services\Hrms;

use App\Models\Hrms\Performance\GoalTaskLink;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\Task;
use App\Models\User;
use App\Services\Hrms\Performance\PerformanceGoalEvidence;
use App\Services\HrmsAuditLogger;
use App\Support\Integration\BridgeSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Performance/HRMS — task-derived evidence for goals, never scores.
 *
 * `refreshGoalEvidence()` delegates to {@see PerformanceGoalEvidence} to photograph
 * what the work record says (completed counts, logged minutes) into `progress_evidence`
 * and derives a bounded percentage; it never writes a rating, a status, or anything a
 * reviewer did not type. Counts respect project membership the way every other
 * global read does — an assignee sees their own tasks through the same
 * scoping the board uses — and explicitly linked tasks (`hrms_task_links`,
 * kind `goal`) join the pool under the same rule, with the exact ids
 * stored so a reviewer can audit the number. A goal whose owner has no
 * login is skipped rather than priced from unscoped data.
 */
class PerformanceService
{
    public function __construct(
        private readonly HrmsAuditLogger $audit,
        private readonly PerformanceGoalEvidence $evidence,
    ) {}

    /**
     * Re-photograph one goal's evidence.
     */
    public function refreshGoalEvidence(PerformanceGoal $goal): PerformanceGoal
    {
        return $this->evidence->refreshGoalEvidence($goal);
    }

    /**
     * Re-photograph every goal on a cycle. Idempotent maintenance, not a
     * decision — like the attendance rollup, it writes no audit rows.
     *
     * @return array{goals: int, refreshed: int}
     */
    public function refreshCycleEvidence(PerformanceCycle $cycle): array
    {
        return $this->evidence->refreshCycleEvidence($cycle);
    }

    /**
     * Link a task as evidence for a goal: the task stays the record, the
     * goal reads it. Duplicates refuse (the unique pair backstops, the
     * message explains), and sealed cycles refuse new links like every
     * other history write.
     *
     * @throws ValidationException on a duplicate or a sealed cycle
     */
    public function linkTask(PerformanceGoal $goal, Task $task, ?User $actor = null): GoalTaskLink
    {
        if (! BridgeSchema::present()) {
            throw ValidationException::withMessages(['task' => __('Task linking is only available when both HRMS and TMS are active.')]);
        }

        if ($goal->cycle->stage->value === 'completed') {
            throw ValidationException::withMessages(['form' => __('That cycle is sealed — history gains no new links.')]);
        }

        if ($goal->taskLinks()->where('task_id', $task->id)->exists()) {
            throw ValidationException::withMessages(['task' => __('That task already evidences this goal.')]);
        }

        return DB::transaction(function () use ($goal, $task, $actor): GoalTaskLink {
            $link = $goal->taskLinks()->create([
                'task_id' => $task->id,
                'created_by' => $actor?->id,
            ]);

            $this->audit->log($goal, 'performance.goal_task_linked', null, [
                'task_id' => $task->id,
            ], $actor);

            return $link;
        });
    }

    /**
     * Unlink a task from a goal: the repair path for a wrong link. The
     * task itself is untouched — only the evidence pointer goes.
     */
    public function unlinkTask(PerformanceGoal $goal, Task $task, ?User $actor = null): void
    {
        if (! BridgeSchema::present()) {
            return;
        }

        DB::transaction(function () use ($goal, $task, $actor): void {
            $goal->taskLinks()->where('task_id', $task->id)->delete();

            $this->audit->log($goal, 'performance.goal_task_unlinked', null, [
                'task_id' => $task->id,
            ], $actor);
        });
    }

    /**
     * Re-photograph every goal evidencing a task: the completion hook calls
     * this after a task lands done, so linked goals update on the event
     * rather than waiting for the nightly sweep.
     *
     * @return array{goals: int}
     */
    public function refreshTaskGoals(Task $task): array
    {
        return $this->evidence->refreshTaskGoals($task);
    }
}
