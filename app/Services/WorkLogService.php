<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class WorkLogService
{
    public function taskLogs(Task $task, int $perPage = 50): LengthAwarePaginator
    {
        return $task->workLogs()
            ->with('user:id,name,email')
            ->orderByDesc('started_at')
            ->paginate($perPage);
    }

    public function create(Task $task, array $data, User $user): WorkLog
    {
        [$startedAt, $endedAt] = $this->resolveInterval($data['started_at'], $data['ended_at'] ?? null);

        if ($this->overlaps($task, $startedAt, $endedAt)) {
            throw ValidationException::withMessages([
                'form' => 'This work log overlaps another log on the same task.',
            ]);
        }

        return WorkLog::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_minutes' => $this->duration($startedAt, $endedAt),
            'description' => $data['description'] ?? null,
        ]);
    }

    public function update(WorkLog $log, array $data): WorkLog
    {
        [$startedAt, $endedAt] = $this->resolveInterval($data['started_at'], $data['ended_at'] ?? null);

        if ($this->overlaps($log->task, $startedAt, $endedAt, $log->id)) {
            throw ValidationException::withMessages([
                'form' => 'This work log overlaps another log on the same task.',
            ]);
        }

        $log->update([
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_minutes' => $this->duration($startedAt, $endedAt),
            'description' => $data['description'] ?? $log->description,
        ]);

        return $log->fresh();
    }

    public function delete(WorkLog $log): void
    {
        $log->delete();
    }

    /**
     * Minutes a log actually counts toward a task — a still-running log
     * (null ended_at) counts elapsed time up to now.
     */
    public function effectiveMinutes(WorkLog $log): int
    {
        return $this->duration($log->started_at, $log->ended_at);
    }

    public function taskAggregate(Task $task): array
    {
        $logs = $task->workLogs()->get();
        $totalMinutes = (int) $logs->sum(fn (WorkLog $log) => $this->effectiveMinutes($log));
        $estimateMinutes = $task->estimate_minutes;

        return [
            'total_minutes' => $totalMinutes,
            'estimate_minutes' => $estimateMinutes,
            'remaining_minutes' => $estimateMinutes === null
                ? null
                : max(0, $estimateMinutes - $totalMinutes),
            'logs_count' => $logs->count(),
        ];
    }

    public function projectSummary(Project $project, array $filters): array
    {
        $query = WorkLog::query()
            ->whereIn('task_id', $project->tasks()->select('id'));

        return $this->summarize($query, $filters);
    }

    public function workspaceSummary(Workspace $workspace, array $filters): array
    {
        $query = WorkLog::query()
            ->whereIn('task_id', Task::query()->where('workspace_id', $workspace->id)->select('id'));

        return $this->summarize($query, $filters);
    }

    /**
     * @return array{total_minutes:int, logs_count:int, group_by:string, groups:list<array>}
     */
    private function summarize(Builder $query, array $filters): array
    {
        $groupBy = $filters['group_by'] ?? 'user';

        if (! empty($filters['from'])) {
            $query->whereDate('started_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('started_at', '<=', $filters['to']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        $logs = $query->with(['task.status:id,name', 'user:id,name'])->get();

        $groups = $logs
            ->groupBy(fn (WorkLog $log) => $this->groupKey($log, $groupBy))
            ->map(function ($group, $key) use ($groupBy) {
                $first = $group->first();

                return [
                    'key' => $key,
                    'label' => $this->groupLabel($first, $groupBy),
                    'minutes' => (int) $group->sum(fn (WorkLog $log) => $this->effectiveMinutes($log)),
                    'logs_count' => $group->count(),
                ];
            })
            ->sortByDesc('minutes')
            ->values()
            ->all();

        return [
            'total_minutes' => (int) $logs->sum(fn (WorkLog $log) => $this->effectiveMinutes($log)),
            'logs_count' => $logs->count(),
            'group_by' => $groupBy,
            'groups' => $groups,
        ];
    }

    private function groupKey(WorkLog $log, string $groupBy): string
    {
        return match ($groupBy) {
            'status' => 'status:'.($log->task?->status_id ?? 'none'),
            'date' => 'date:'.$log->started_at?->toDateString(),
            default => 'user:'.$log->user_id,
        };
    }

    private function groupLabel(WorkLog $log, string $groupBy): string
    {
        return match ($groupBy) {
            'status' => $log->task?->status?->name ?? 'No status',
            'date' => $log->started_at?->toDateString() ?? 'Unknown',
            default => $log->user?->name ?? 'Unknown',
        };
    }

    private function overlaps(Task $task, Carbon $startedAt, ?Carbon $endedAt, ?int $exceptId = null): bool
    {
        $end = $endedAt ?? now();
        $query = WorkLog::query()->where('task_id', $task->id);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query
            ->where('started_at', '<', $end)
            ->where(function (Builder $query) use ($startedAt) {
                $query->whereNull('ended_at')
                    ->orWhere('ended_at', '>', $startedAt);
            })
            ->exists();
    }

    /**
     * @return array{Carbon, Carbon|null}
     */
    private function resolveInterval(string $startedAt, ?string $endedAt): array
    {
        return [Carbon::parse($startedAt), $endedAt === null || $endedAt === '' ? null : Carbon::parse($endedAt)];
    }

    private function duration(Carbon $startedAt, ?Carbon $endedAt): int
    {
        $end = $endedAt ?? now();

        return max(1, (int) round(abs($end->diffInSeconds($startedAt)) / 60));
    }
}
