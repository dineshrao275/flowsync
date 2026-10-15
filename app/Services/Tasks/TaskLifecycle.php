<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskStatusHistory;
use App\Services\Hrms\PerformanceService;
use App\Support\Hrms\HrmsSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Shared guards and side-effects for the task write paths (create / update /
 * move): WIP limits, open blockers, status history, and goal-evidence refresh.
 * Extracted from TaskService to hold the 300-line class ceiling.
 */
class TaskLifecycle
{
    public function __construct(
        private readonly PerformanceService $performance,
    ) {}

    /** A column's WIP limit caps the open cards it may hold; exceeding it is a 422. */
    public function assertWip(TaskStatus $status, ?Task $exclude = null): void
    {
        if ($status->wip_limit === null) {
            return;
        }

        $count = $status->tasks()
            ->whereNull('parent_id')
            ->whereNull('completed_at')
            ->when($exclude !== null, fn ($q) => $q->whereKeyNot($exclude->id))
            ->count();

        if ($count >= $status->wip_limit) {
            throw ValidationException::withMessages([
                'form' => sprintf('"%s" is at its WIP limit (%d). Move a card out or raise the limit.', $status->name, $status->wip_limit),
            ]);
        }
    }

    public function hasOpenBlockers(?Task $task): bool
    {
        if ($task === null) {
            return false;
        }

        return $task->openBlockers()->exists();
    }

    /** One history row per status change — the basis for cycle and lead time. */
    public function recordStatusChange(Task $task, ?int $from, int $to): void
    {
        TaskStatusHistory::create([
            'task_id' => $task->id, 'from_status_id' => $from, 'to_status_id' => $to,
            'user_id' => Auth::id(), 'changed_at' => now(),
        ]);
    }

    /**
     * A completion re-photographs every goal evidencing the task, so linked goals update on
     * the event rather than waiting for the nightly sweep. Evidence only — it never writes a
     * rating or a status. A tenant without the HRMS product has no goals to refresh.
     */
    public function refreshGoalsOnCompletion(Task $task): void
    {
        if (HrmsSchema::present()) {
            $this->performance->refreshTaskGoals($task);
        }
    }
}
