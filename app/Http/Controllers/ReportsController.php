<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesVisibleTasks;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    use ScopesVisibleTasks;

    public function overview(Request $request): JsonResponse
    {
        $query = $this->visibleTaskQuery($request->user())
            ->whereNull('tasks.archived_at');

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
}
