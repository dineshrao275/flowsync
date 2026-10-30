<?php

declare(strict_types=1);

namespace App\Services\Hrms\Performance;

use App\Enums\Hrms\GoalMetricType;
use App\Enums\Hrms\GoalProgressSource;
use App\Enums\Hrms\TaskLinkKind;
use App\Models\Hrms\Performance\GoalTaskLink;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\Hrms\TaskLink\TaskLink;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Support\Integration\BridgeSchema;
use App\Support\Tms\TmsSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Performance/HRMS — calculates task-derived evidence for goals.
 *
 * `refreshGoalEvidence()` photographs what the work record says (completed
 * counts, logged minutes) into `progress_evidence` and derives a bounded
 * percentage; it never writes a rating, a status, or anything a reviewer
 * did not type.
 */
class PerformanceGoalEvidence
{
    /**
     * Re-photograph one goal's evidence. `manual` (and `none`) goals are
     * returned untouched — HR sets those percentages by hand, and a refresh
     * that zeroed them would destroy human input on a schedule. TMS-backed
     * metrics are likewise untouched when the tenant has no TMS schema: the
     * work tables do not exist, so there is no evidence to photograph.
     */
    public function refreshGoalEvidence(PerformanceGoal $goal): PerformanceGoal
    {
        $goal->loadMissing(['employee:id,user_id', 'cycle:id,period_start,period_end']);

        $userId = $goal->employee?->user_id;

        if ($userId === null) {
            return $goal;
        }

        if (in_array($goal->metric_type, [GoalMetricType::TaskCompletion, GoalMetricType::WorklogHours], true)
            && ! TmsSchema::present()) {
            return $goal;
        }

        $user = User::find($userId);

        if ($user === null) {
            return $goal;
        }

        match ($goal->metric_type) {
            GoalMetricType::TaskCompletion => $this->refreshTaskCompletion($goal, $user),
            GoalMetricType::WorklogHours => $this->refreshWorklogHours($goal, $user),
            default => null,
        };

        return $goal->refresh();
    }

    /**
     * Re-photograph every goal on a cycle. Idempotent maintenance, not a
     * decision — like the attendance rollup, it writes no audit rows.
     *
     * @return array{goals: int, refreshed: int}
     */
    public function refreshCycleEvidence(PerformanceCycle $cycle): array
    {
        $goals = $cycle->goals()->with(['employee:id,user_id', 'cycle:id,period_start,period_end'])->get();
        $refreshed = 0;

        foreach ($goals as $goal) {
            $before = [$goal->progress_percent, $goal->progress_evidence];
            $this->refreshGoalEvidence($goal);

            if ([$goal->fresh()->progress_percent, $goal->fresh()->progress_evidence] !== $before) {
                $refreshed++;
            }
        }

        return ['goals' => $goals->count(), 'refreshed' => $refreshed];
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
        if (! BridgeSchema::present()) {
            return ['goals' => 0];
        }

        $goalIds = GoalTaskLink::query()
            ->where('task_id', $task->id)
            ->pluck('goal_id')
            ->all();

        $count = 0;

        foreach (PerformanceGoal::query()->whereIn('id', $goalIds)->get() as $goal) {
            $this->refreshGoalEvidence($goal);
            $count++;
        }

        return ['goals' => $count];
    }

    /**
     * Completed, created and overdue counts for the assignee inside the
     * cycle window, with progress against the target capped at 100. A goal
     * with no target keeps progress at zero — counts without a denominator
     * are evidence, not a percentage, and inventing one would be a score.
     */
    private function refreshTaskCompletion(PerformanceGoal $goal, User $user): void
    {
        [$from, $to] = $this->window($goal);

        $ids = (clone $this->assignedTasks($goal, $user))->pluck('tasks.id');
        if (BridgeSchema::present()) {
            $ids = $ids->merge((clone $this->linkedTasks($goal, $user))->pluck('tasks.id'));
        }
        $ids = $ids->unique()->values();

        $pool = Task::query()->whereIn('tasks.id', $ids);

        $completedIds = (clone $pool)
            ->whereNotNull('tasks.completed_at')
            ->whereDate('tasks.completed_at', '>=', $from)
            ->whereDate('tasks.completed_at', '<=', $to)
            ->pluck('tasks.id')->all();

        $created = (clone $pool)
            ->whereDate('tasks.created_at', '>=', $from)
            ->whereDate('tasks.created_at', '<=', $to)
            ->count();

        $overdue = (clone $pool)
            ->whereNull('tasks.completed_at')
            ->whereDate('tasks.due_date', '<', today()->toDateString())
            ->count();

        $target = (float) ($goal->target_value ?? 0);
        $completed = count($completedIds);

        $goal->update([
            'progress_percent' => $target > 0 ? number_format(min(100, $completed / $target * 100), 2, '.', '') : '0.00',
            'progress_source' => GoalProgressSource::Auto,
            'progress_evidence' => [
                'completed' => $completed,
                'created' => $created,
                'overdue' => $overdue,
                'task_ids' => array_values($completedIds),
                'linked_task_ids' => BridgeSchema::present() ? $this->linkedTasks($goal, $user)->pluck('tasks.id')->all() : [],
                'window' => [$from, $to],
            ],
        ]);
    }

    /**
     * Logged minutes inside the window plus the days they fell on, with
     * progress against the target in hours. Same zero-target rule as task
     * completion: no denominator, no percentage.
     */
    private function refreshWorklogHours(PerformanceGoal $goal, User $user): void
    {
        [$from, $to] = $this->window($goal);

        $logs = WorkLog::query()->where('user_id', $user->id)
            ->whereDate('started_at', '>=', $from)
            ->whereDate('started_at', '<=', $to)
            ->get(['started_at', 'duration_minutes']);

        $days = $logs
            ->map(fn (WorkLog $log): string => Carbon::parse((string) $log->started_at)->toDateString())
            ->unique()
            ->count();

        $minutes = $logs->sum('duration_minutes');
        $target = (float) ($goal->target_value ?? 0);

        $goal->update([
            'progress_percent' => $target > 0 ? number_format(min(100, $minutes / 60 / $target * 100), 2, '.', '') : '0.00',
            'progress_source' => GoalProgressSource::Auto,
            'progress_evidence' => [
                'minutes' => $minutes,
                'hours' => round($minutes / 60, 2),
                'days_logged' => $days,
                'window' => [$from, $to],
            ],
        ]);
    }

    /**
     * The assignee's tasks with the board's membership scoping: tenant
     * managers read all, everyone else reads projects they belong to. An
     * assignee is always a project member (the task resolvers require it),
     * so the constraint documents rather than filters — the evidence a
     * reviewer sees is exactly what the board would show them.
     *
     * @return Builder<Task>
     */
    private function assignedTasks(PerformanceGoal $goal, User $user): Builder
    {
        $query = Task::query()->where('tasks.assignee_id', $user->id);

        if (! $user->hasPermission('workspaces.manage')) {
            $query->whereHas('project', fn ($project) => $project
                ->whereHas('members', fn ($members) => $members->where('user_id', $user->id)));
        }

        return $query;
    }

    /**
     * Explicitly linked work: `hrms_task_links` rows naming this goal's
     * owner with kind `goal`. Same membership rule as assigned work —
     * linking never widens sight, it only names tasks inside it.
     *
     * @return Builder<Task>
     */
    private function linkedTasks(PerformanceGoal $goal, User $user): Builder
    {
        if (! BridgeSchema::present()) {
            return Task::query()->whereRaw('1 = 0');
        }

        $ids = TaskLink::query()
            ->where('employee_id', $goal->employee_id)
            ->where('kind', TaskLinkKind::Goal->value)
            ->pluck('task_id');

        $query = Task::query()->whereIn('tasks.id', $ids);

        if (! $user->hasPermission('workspaces.manage')) {
            $query->whereHas('project', fn ($project) => $project
                ->whereHas('members', fn ($members) => $members->where('user_id', $user->id)));
        }

        return $query;
    }

    /**
     * @return array{string, string} Inclusive Y-m-d bounds.
     */
    private function window(PerformanceGoal $goal): array
    {
        return [
            $goal->cycle->period_start->toDateString(),
            $goal->cycle->period_end->toDateString(),
        ];
    }
}
