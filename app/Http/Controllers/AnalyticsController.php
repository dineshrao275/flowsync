<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesDateRange;
use App\Http\Controllers\Concerns\ScopesVisibleTasks;
use App\Models\Project;
use App\Models\WorkLog;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    use ResolvesDateRange;
    use ScopesVisibleTasks;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $canManage = $user->hasPermission('workspaces.manage');

        $range = $this->range($request);

        $workspaces = $this->scopeWorkspaces($user, $canManage);
        $projects = $this->scopeProjects($user, $canManage);
        $tasks = $this->visibleTaskQuery($user);

        // Visible task ids power the work-log + activity aggregations.
        $visibleTaskIds = (clone $tasks)->whereNull('tasks.archived_at')->pluck('tasks.id');

        $logs = WorkLog::query()->whereIn('task_id', $visibleTaskIds);

        return response()->json([
            'range' => $range,
            'counts' => [
                'workspaces' => (clone $workspaces)->count(),
                'projects' => (clone $projects)->count(),
                'open' => (clone $tasks)->whereNull('tasks.completed_at')->whereNull('tasks.archived_at')->count(),
                'done' => (clone $tasks)->whereNotNull('tasks.completed_at')->count(),
                'overdue' => (clone $tasks)
                    ->whereNull('tasks.completed_at')
                    ->whereNotNull('tasks.due_date')
                    ->whereDate('tasks.due_date', '<', now()->toDateString())
                    ->count(),
                'due_this_week' => (clone $tasks)
                    ->whereNull('tasks.completed_at')
                    ->whereNotNull('tasks.due_date')
                    ->whereDate('tasks.due_date', '>=', now()->toDateString())
                    ->whereDate('tasks.due_date', '<=', now()->addDays(7)->toDateString())
                    ->count(),
                'created_30d' => (clone $tasks)->where('tasks.created_at', '>=', now()->subDays(30))->count(),
                'created_in_range' => (clone $tasks)
                    ->whereDate('tasks.created_at', '>=', $range['from'])
                    ->whereDate('tasks.created_at', '<=', $range['to'])
                    ->count(),
            ],
            'projects_progress' => $this->projectsProgress($projects),
            'work_logs' => [
                'total_minutes' => (int) (clone $logs)
                    ->whereDate('started_at', '>=', $range['from'])
                    ->whereDate('started_at', '<=', $range['to'])
                    ->sum('duration_minutes'),
                'today_minutes' => (int) (clone $logs)->whereDate('started_at', Carbon::today())->sum('duration_minutes'),
                'week_minutes' => (int) (clone $logs)->where('started_at', '>=', Carbon::now()->startOfWeek())->sum('duration_minutes'),
                'daily' => $this->dailySeries($logs, 'started_at', 'minutes', $range),
            ],
            'tasks_created' => [
                'daily' => $this->dailySeries($tasks, 'tasks.created_at', 'count', $range),
            ],
            'top_contributors' => $this->topContributors($visibleTaskIds, $range),
        ]);
    }

    /**
     * Optional ?from=&to= (YYYY-MM-DD) window for the ranged series.
     * Defaults to the last 14 days inclusive. Capped at 366 days so a
     * year view stays a few indexed queries, never hundreds of day
     * slices.
     *
     * @return array{from: string, to: string, days: int}
     */
    private function range(Request $request): array
    {
        return $this->resolveDateRange($request);
    }

    private function scopeWorkspaces($user, bool $canManage): Builder
    {
        return $canManage
            ? Workspace::query()
            : Workspace::query()->whereHas('members', fn (Builder $q) => $q->where('user_id', $user->id));
    }

    private function scopeProjects($user, bool $canManage): Builder
    {
        return $canManage
            ? Project::query()
            : Project::query()->whereHas('members', fn (Builder $q) => $q->where('user_id', $user->id));
    }

    private function projectsProgress(Builder $projects): array
    {
        $rows = (clone $projects)
            ->withCount([
                'tasks as total_tasks' => fn (Builder $q) => $q->whereNull('archived_at'),
                'tasks as done_tasks' => fn (Builder $q) => $q->whereNotNull('completed_at'),
            ])
            ->orderBy('name')
            ->limit(12)
            ->get();

        return $rows->map(fn (Project $project) => [
            'id' => $project->id,
            'name' => $project->name,
            'key' => $project->key,
            'open' => max(0, $project->total_tasks - $project->done_tasks),
            'done' => (int) $project->done_tasks,
            'total' => (int) $project->total_tasks,
            'percent' => $project->total_tasks > 0 ? (int) round(($project->done_tasks / $project->total_tasks) * 100) : 0,
        ])->values()->all();
    }

    /**
     * Per-day series over the range, oldest first — one grouped query no
     * matter how wide the window, with PHP filling the dateless gaps. The
     * old per-day loop issued a query per day (14 by default, 366 for a
     * year view); DATE() groups portably on both grammars.
     */
    private function dailySeries(Builder $query, string $column, string $kind, array $range): array
    {
        $dateSql = $column === 'started_at' ? 'DATE(started_at)' : 'DATE(tasks.created_at)';

        $rows = (clone $query)
            ->whereDate($column, '>=', $range['from'])
            ->whereDate($column, '<=', $range['to'])
            ->selectRaw("{$dateSql} as day, ".($kind === 'minutes' ? 'SUM(duration_minutes) as value' : 'COUNT(*) as value'))
            ->groupBy(DB::raw($dateSql))
            ->pluck('value', 'day');

        $from = Carbon::parse($range['from']);

        return collect(range(0, $range['days'] - 1))
            ->map(fn (int $offset) => [
                'date' => $from->copy()->addDays($offset)->toDateString(),
                $kind => (int) ($rows->get($from->copy()->addDays($offset)->toDateString()) ?? 0),
            ])
            ->values()
            ->all();
    }

    private function topContributors($visibleTaskIds, array $range): array
    {
        return WorkLog::query()
            ->whereIn('task_id', $visibleTaskIds)
            ->whereDate('started_at', '>=', $range['from'])
            ->whereDate('started_at', '<=', $range['to'])
            ->with('user:id,name,email')
            ->selectRaw('user_id, SUM(duration_minutes) as minutes, COUNT(*) as logs_count')
            ->groupBy('user_id')
            ->orderByDesc('minutes')
            ->limit(5)
            ->get()
            ->map(fn (WorkLog $log) => [
                'user' => $log->user?->only('id', 'name', 'email'),
                'minutes' => (int) $log->minutes,
                'logs_count' => (int) $log->logs_count,
            ])
            ->all();
    }
}
