<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesVisibleTasks;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportsController extends Controller
{
    use ScopesVisibleTasks;

    public function overview(Request $request): JsonResponse
    {
        $range = $this->range($request);

        $query = $this->visibleTaskQuery($request->user())
            ->whereNull('tasks.archived_at');

        // Optional window: tasks created or completed inside it. Without
        // bounds the pool is everything visible (the historic behavior).
        if ($range !== null) {
            $query->where(fn ($builder) => $builder
                ->whereDate('tasks.created_at', '>=', $range['from'])
                ->whereDate('tasks.created_at', '<=', $range['to'])
                ->orWhere(fn ($done) => $done
                    ->whereDate('tasks.completed_at', '>=', $range['from'])
                    ->whereDate('tasks.completed_at', '<=', $range['to'])));
        }

        $tasks = $query->get();

        $groupBy = fn ($key, $labelFor) => $tasks
            ->groupBy($key)
            ->map(function ($group) use ($labelFor) {
                $first = $group->first();

                return [
                    'key' => (string) $labelFor['value']($first),
                    'label' => $labelFor['label']($first),
                    'count' => $group->count(),
                    'open' => $group->whereNull('completed_at')->count(),
                    'done' => $group->whereNotNull('completed_at')->count(),
                    'color' => $labelFor['color']($first),
                ];
            })
            ->sortByDesc('count')
            ->values();

        $overdue = $tasks->filter(fn (Task $task) => $task->completed_at === null && $task->due_date !== null && $task->due_date->lt(now()->startOfDay()));

        return response()->json([
            'scope' => $this->userManagesAllTasks($request->user()) ? 'all' : 'member',
            'range' => $range,
            'totals' => [
                'total' => $tasks->count(),
                'open' => $tasks->whereNull('completed_at')->count(),
                'done' => $tasks->whereNotNull('completed_at')->count(),
                'overdue' => $overdue->count(),
            ],
            'by_status' => $groupBy('status_id', [
                'value' => fn (Task $task) => $task->status_id ?? 'none',
                'label' => fn (Task $task) => $task->status?->name ?? 'No status',
                'color' => fn (Task $task) => $task->status?->color ?? '#94a3b8',
            ]),
            'by_priority' => $groupBy('priority_id', [
                'value' => fn (Task $task) => $task->priority_id ?? 'none',
                'label' => fn (Task $task) => $task->priority?->name ?? 'No priority',
                'color' => fn (Task $task) => $task->priority?->color ?? '#94a3b8',
            ]),
            'by_assignee' => $groupBy('assignee_id', [
                'value' => fn (Task $task) => $task->assignee_id ?? 'none',
                'label' => fn (Task $task) => $task->assignee?->name ?? 'Unassigned',
                'color' => fn (Task $task) => $task->assignee?->name ? '#6366f1' : '#94a3b8',
            ]),
            'by_project' => $groupBy('project_id', [
                'value' => fn (Task $task) => $task->project_id ?? 'none',
                'label' => fn (Task $task) => $task->project?->name ?? 'Unknown project',
                'color' => fn (Task $task) => '#0ea5e9',
            ]),
        ]);
    }

    /**
     * Optional ?from=&to= (YYYY-MM-DD) window, echoed back so the client
     * can label the charts. Null when unfiltered (the historic "everything
     * visible" behavior). Capped like the analytics range.
     *
     * @return array{from: string, to: string, days: int}|null
     */
    private function range(Request $request): ?array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if (! isset($data['from']) && ! isset($data['to'])) {
            return null;
        }

        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::today();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(13);

        if ($from->diffInDays($to) > 365) {
            abort(422, 'Range covers at most 366 days.');
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $from->diffInDays($to) + 1,
        ];
    }
}
