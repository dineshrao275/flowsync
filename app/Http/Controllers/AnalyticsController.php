<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesVisibleTasks;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkLog;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    use ScopesVisibleTasks;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $canManage = $user->hasPermission('workspaces.manage');

        $workspaces = $this->scopeWorkspaces($user, $canManage);
        $projects = $this->scopeProjects($user, $canManage);
        $tasks = $this->visibleTaskQuery($user);

        // Visible task ids power the work-log + activity aggregations.
        $visibleTaskIds = (clone $tasks)->whereNull('tasks.archived_at')->pluck('tasks.id');

        $logs = WorkLog::query()->whereIn('task_id', $visibleTaskIds);

        return response()->json([
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
            ],
            'projects_progress' => $this->projectsProgress($projects),
            'work_logs' => [
                'total_minutes' => (int) (clone $logs)->sum('duration_minutes'),
                'today_minutes' => (int) (clone $logs)->whereDate('started_at', Carbon::today())->sum('duration_minutes'),
                'week_minutes' => (int) (clone $logs)->where('started_at', '>=', Carbon::now()->startOfWeek())->sum('duration_minutes'),
                'daily' => $this->dailySeries($logs, 'started_at', 'minutes'),
            ],
            'tasks_created' => [
                'daily' => $this->dailySeries($tasks, 'tasks.created_at', 'count'),
            ],
            'top_contributors' => $this->topContributors($visibleTaskIds),
        ]);
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
     * Per-day series for the last 14 days (inclusive of today, oldest first).
     */
    private function dailySeries(Builder $query, string $column, string $kind): array
    {
        $base = fn () => clone $query;

        return collect(range(13, 0))
            ->map(fn (int $daysAgo) => $this->daySlice($base(), $column, $kind, $daysAgo))
            ->values()
            ->all();
    }

    private function daySlice(Builder $query, string $column, string $kind, int $daysAgo): array
    {
        $date = Carbon::now()->subDays($daysAgo)->toDateString();

        $value = $kind === 'minutes'
            ? (int) (clone $query)->whereDate($column, $date)->sum('duration_minutes')
            : (clone $query)->whereDate($column, $date)->count();

        return ['date' => $date, $kind => $value];
    }

    private function topContributors($visibleTaskIds): array
    {
        return WorkLog::query()
            ->whereIn('task_id', $visibleTaskIds)
            ->where('started_at', '>=', Carbon::now()->subDays(30))
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
