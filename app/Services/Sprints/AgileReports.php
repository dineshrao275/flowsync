<?php

namespace App\Services\Sprints;

use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskStatusHistory;
use Carbon\CarbonImmutable;

/**
 * Agile numbers computed from what the tracker already records — sprint scope events and the
 * status history — so nothing is typed in by hand and nothing can drift from the board.
 */
class AgileReports
{
    /** @return array<string, mixed> */
    public function forProject(Project $project, ?Sprint $sprint): array
    {
        return [
            'velocity' => $this->velocity($project),
            'burndown' => $sprint ? $this->burndown($sprint) : null,
            'flow' => $this->flow($project),
            'throughput' => $this->throughput($project),
        ];
    }

    /** @return list<array{sprint: string, committed: float, completed: float}> last 8 completed sprints, oldest first */
    public function velocity(Project $project): array
    {
        return $project->sprints()->where('status', Sprint::COMPLETED)->orderByDesc('completed_at')->limit(8)->get()->reverse()->values()
            ->map(fn (Sprint $s) => ['sprint' => $s->name, 'committed' => (float) $s->committed_points, 'completed' => (float) $s->completed_points])->all();
    }

    /**
     * Remaining story points at the end of each day of the sprint, against the ideal line.
     *
     * @return array{days: list<array{date: string, scope: float, done: float, remaining: float, ideal: float}>}
     */
    public function burndown(Sprint $sprint): array
    {
        if (! $sprint->start_date) {
            return ['days' => []];
        }

        $start = CarbonImmutable::parse($sprint->start_date)->startOfDay();
        $end = CarbonImmutable::parse($sprint->end_date ?? $start->addWeeks(2))->startOfDay();
        $last = $sprint->completed_at ? CarbonImmutable::parse($sprint->completed_at)->startOfDay() : min(CarbonImmutable::now()->startOfDay(), $end);
        $last = $last->lt($start) ? $start : $last;

        $events = $sprint->events()->orderBy('at')->orderBy('id')->get();
        $taskIds = $events->pluck('task_id')->unique()->all();
        $doneStatusIds = TaskStatus::where('is_done', true)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $history = TaskStatusHistory::whereIn('task_id', $taskIds)->orderBy('changed_at')->orderBy('id')->get()->groupBy('task_id');

        $days = [];
        $totalDays = max(1, (int) $start->diffInDays($end));
        $initialScope = null;

        for ($day = $start; $day->lte($last); $day = $day->addDay()) {
            $until = $day->endOfDay();

            // Membership and points as of the end of the day.
            $members = [];
            foreach ($events as $e) {
                if ($e->at->gt($until)) {
                    break;
                }
                $e->type === 'added' ? $members[$e->task_id] = (float) $e->points : null;
                $e->type === 'removed' ? $members[$e->task_id] = null : null;
            }
            $members = array_filter($members, fn ($p) => $p !== null || false);

            $scope = array_sum($members);
            $done = 0.0;
            foreach ($members as $taskId => $points) {
                $latest = ($history[$taskId] ?? collect())->filter(fn ($h) => $h->changed_at->lte($until))->last();
                if ($latest && in_array((int) $latest->to_status_id, $doneStatusIds, true)) {
                    $done += $points;
                }
            }

            $initialScope ??= $scope;
            $elapsed = (int) $start->diffInDays($day);
            $days[] = [
                'date' => $day->toDateString(), 'scope' => round($scope, 2), 'done' => round($done, 2), 'remaining' => round($scope - $done, 2),
                'ideal' => round(max(0, $initialScope * (1 - $elapsed / $totalDays)), 2),
            ];
        }

        return ['days' => $days];
    }

    /**
     * Lead time (created → completed) and cycle time (first in-progress → completed), in days,
     * for tasks completed in the last 90 days.
     *
     * @return array{window_days: int, completed: int, lead_time: array{avg: float|null, median: float|null}, cycle_time: array{avg: float|null, median: float|null}}
     */
    public function flow(Project $project): array
    {
        $since = now()->subDays(90);
        $tasks = Task::where('project_id', $project->id)->whereNotNull('completed_at')->where('completed_at', '>=', $since)->get(['id', 'created_at', 'completed_at']);

        $inProgressIds = TaskStatus::where('project_id', $project->id)->where('category', 'in_progress')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $firstStart = TaskStatusHistory::whereIn('task_id', $tasks->pluck('id'))->whereIn('to_status_id', $inProgressIds)->orderBy('changed_at')->get()->groupBy('task_id')->map(fn ($rows) => $rows->first()->changed_at);

        $lead = $tasks->map(fn ($t) => $t->created_at->diffInSeconds($t->completed_at, false) / 86400)->filter(fn ($d) => $d >= 0)->values();
        $cycle = $tasks->filter(fn ($t) => $firstStart->has($t->id))->map(fn ($t) => $firstStart[$t->id]->diffInSeconds($t->completed_at, false) / 86400)->filter(fn ($d) => $d >= 0)->values();

        return [
            'window_days' => 90, 'completed' => $tasks->count(),
            'lead_time' => $this->stats($lead), 'cycle_time' => $this->stats($cycle),
        ];
    }

    /** @return list<array{week: string, completed: int}> last 8 weeks, oldest first */
    public function throughput(Project $project): array
    {
        $weeks = [];
        for ($i = 7; $i >= 0; $i--) {
            $from = now()->startOfWeek()->subWeeks($i);
            $weeks[] = [
                'week' => $from->toDateString(),
                'completed' => Task::where('project_id', $project->id)->whereBetween('completed_at', [$from, $from->copy()->endOfWeek()])->count(),
            ];
        }

        return $weeks;
    }

    /** @return array{avg: float|null, median: float|null} */
    private function stats($values): array
    {
        if ($values->isEmpty()) {
            return ['avg' => null, 'median' => null];
        }
        $sorted = $values->sort()->values();
        $mid = intdiv($sorted->count(), 2);
        $median = $sorted->count() % 2 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;

        return ['avg' => round($values->avg(), 1), 'median' => round($median, 1)];
    }
}
