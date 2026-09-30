<?php

namespace App\Services\Hrms;

use App\Enums\Hrms\GoalMetricType;
use App\Enums\Hrms\GoalProgressSource;
use App\Models\Hrms\Performance\GoalTaskLink;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Performance/HRMS — task-derived evidence for goals, never scores.
 *
 * `refreshGoalEvidence()` photographs what the work record says (completed
 * counts, logged minutes) into `progress_evidence` and derives a bounded
 * percentage; it never writes a rating, a status, or anything a reviewer
 * did not type. Counts respect project membership the way every other
 * global read does — an assignee sees their own tasks through the same
 * scoping the board uses — and a goal whose owner has no login is skipped
 * rather than priced from unscoped data.
 */
class PerformanceService
{
    /**
     * Re-photograph one goal's evidence. `manual` (and `none`) goals are
     * returned untouched — HR sets those percentages by hand, and a refresh
     * that zeroed them would destroy human input on a schedule.
     */
    public function refreshGoalEvidence(PerformanceGoal $goal): PerformanceGoal
    {
        $goal->loadMissing(['employee:id,user_id', 'cycle:id,period_start,period_end']);

        $userId = $goal->employee?->user_id;

        if ($userId === null) {
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

        $assigned = $this->assignedTasks($goal, $user);

        $completed = (clone $assigned)
            ->whereNotNull('tasks.completed_at')
            ->whereDate('tasks.completed_at', '>=', $from)
            ->whereDate('tasks.completed_at', '<=', $to)
            ->count();

        $created = (clone $assigned)
            ->whereDate('tasks.created_at', '>=', $from)
            ->whereDate('tasks.created_at', '<=', $to)
            ->count();

        $overdue = (clone $assigned)
            ->whereNull('tasks.completed_at')
            ->whereDate('tasks.due_date', '<', today()->toDateString())
            ->count();

        $target = (float) ($goal->target_value ?? 0);

        $goal->update([
            'progress_percent' => $target > 0 ? number_format(min(100, $completed / $target * 100), 2, '.', '') : '0.00',
            'progress_source' => GoalProgressSource::Auto,
            'progress_evidence' => [
                'completed' => $completed,
                'created' => $created,
                'overdue' => $overdue,
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

        // pluck() skips casts, so the SQLite time part is parsed off in PHP
        // — comparing raw strings would miss rows on the fast path (the
        // approvedLeaveDates precedent).
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
