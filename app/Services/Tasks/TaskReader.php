<?php

namespace App\Services\Tasks;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Like;
use App\Support\TaskScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read side of tasks: board columns, paginated list, single-task payload and
 * the shared filtered/row-scoped query. Moved out of TaskService (P1.7).
 */
class TaskReader
{
    public function __construct(private readonly TaskPresenter $presenter) {}

    public function board(Project $project, array $filters, User $user): array
    {
        $topLevel = $this->filteredQuery($project, $filters, $user)->whereNull('tasks.parent_id');

        $columnLimit = isset($filters['column_limit'])
            ? max(1, min((int) $filters['column_limit'], 500))
            : (isset($filters['limit']) ? max(1, min((int) $filters['limit'], 500)) : null);

        $statuses = $project->statuses()->orderBy('position')->get();

        if (($filters['swimlane'] ?? 'none') === 'assignee') {
            return $this->boardByAssignee($topLevel->get(), $statuses, $columnLimit);
        }

        // Pre-count status totals for high-volume boards in a single query
        $statusCounts = (clone $topLevel)
            ->reorder()
            ->select('tasks.status_id', DB::raw('count(*) as count'), DB::raw('count(case when completed_at is null then 1 end) as open_count'))
            ->groupBy('tasks.status_id')
            ->get()
            ->keyBy('status_id');

        $taskQuery = (clone $topLevel)->get()->groupBy('status_id');

        $boardStatuses = $statuses->map(fn (TaskStatus $status) => $this->buildColumn(
            $status,
            $taskQuery->get($status->id, collect()),
            (int) ($statusCounts->get($status->id)?->count ?? 0),
            (int) ($statusCounts->get($status->id)?->open_count ?? 0),
            $columnLimit,
        ));

        // Fast single-pass totals scan
        $totalsRow = (clone $topLevel)
            ->reorder()
            ->selectRaw('count(case when completed_at is null then 1 end) as open_count, count(case when completed_at is not null then 1 end) as done_count')
            ->first();

        return [
            'statuses' => $boardStatuses,
            'totals' => [
                'open' => (int) ($totalsRow?->open_count ?? 0),
                'done' => (int) ($totalsRow?->done_count ?? 0),
            ],
        ];
    }

    /** One column of the board: present status + present cards + counts. */
    private function buildColumn(TaskStatus $status, $tasks, int $totalCount, int $openCount, ?int $columnLimit): array
    {
        $allTasks = $tasks->sortBy(fn (Task $task) => [$task->position, $task->id])->values();
        $shown = $columnLimit !== null ? $allTasks->take($columnLimit) : $allTasks;

        $data = array_merge($this->presenter->presentStatus($status), [
            'tasks_count' => $totalCount,
            'open_count' => $openCount,
            'tasks' => $shown->map(fn (Task $task) => $this->presenter->present($task)),
        ]);

        if ($columnLimit !== null) {
            $data['has_more'] = $totalCount > $shown->count();
            $data['column_limit'] = $columnLimit;
        }

        return $data;
    }

    /** Board grouped into swimlanes by assignee; each lane carries its own columns and totals. */
    private function boardByAssignee($tasks, $statuses, ?int $columnLimit): array
    {
        $byAssignee = $tasks->groupBy(fn (Task $task) => (string) ($task->assignee_id ?? ''))
            ->map(function ($laneTasks) use ($statuses, $columnLimit) {
                $byStatus = $laneTasks->groupBy('status_id');

                $columns = $statuses->map(fn (TaskStatus $status) => $this->buildColumn(
                    $status,
                    $byStatus->get($status->id, collect()),
                    $byStatus->get($status->id, collect())->count(),
                    $byStatus->get($status->id, collect())->filter(fn (Task $t) => $t->completed_at === null)->count(),
                    $columnLimit,
                ));

                return [
                    'assignee' => $laneTasks->first()?->assignee ? [
                        'id' => $laneTasks->first()->assignee->id,
                        'name' => $laneTasks->first()->assignee->name,
                    ] : null,
                    'statuses' => $columns,
                    'totals' => [
                        'open' => $laneTasks->filter(fn (Task $t) => $t->completed_at === null)->count(),
                        'done' => $laneTasks->filter(fn (Task $t) => $t->completed_at !== null)->count(),
                    ],
                ];
            });

        // Unassigned lane last; assignee lanes sorted by name so the board is stable.
        $lanes = $byAssignee->sortBy(
            fn ($lane) => $lane['assignee'] === null ? "\u{10FFFF}" : strtolower($lane['assignee']['name']),
        )->values();

        $all = collect($tasks);

        return [
            'swimlanes' => $lanes,
            'totals' => [
                'open' => $all->filter(fn (Task $t) => $t->completed_at === null)->count(),
                'done' => $all->filter(fn (Task $t) => $t->completed_at !== null)->count(),
            ],
        ];
    }

    public function list(Project $project, array $filters, User $user): LengthAwarePaginator
    {
        return $this->filteredQuery($project, $filters, $user)
            ->withCount(['subtasks', 'comments', 'attachments'])
            ->orderBy($filters['sort_by'] ?? 'position', $filters['sort_dir'] ?? 'asc')
            ->paginate(min($filters['per_page'] ?? 25, 100));
    }

    public function show(Task $task): Task
    {
        return $task->loadMissing([
            'status',
            'priority',
            'issueType',
            'version',
            'components',
            'watchers:users.id,name,email',
            'assignee',
            'reporter',
            'creator',
            'parent:id,key,title',
            'epic:id,key,title',
            'labels',
            'subtasks:id,key,title,status_id,completed_at,parent_id,position',
            'subtasks.status',
        ])->loadCount([
            'subtasks',
            'comments',
            'attachments',
            'openBlockers as open_blockers_count',
            'checklistItems as checklist_total',
            'checklistItems as checklist_done_count' => fn ($q) => $q->where('is_done', true),
        ]);
    }

    public function filteredQuery(Project $project, array $filters, User $user): Builder
    {
        $query = TaskScope::constrainQuery(
            Task::query()
                ->where('tasks.project_id', $project->id)
                ->with(['status', 'priority', 'assignee', 'labels', 'issueType', 'version', 'components', 'watchers:users.id,name,email'])
                ->withCount([
                    'subtasks',
                    'comments',
                    'attachments',
                    'openBlockers as open_blockers_count',
                ]),
            $user,
            TaskScope::resolveQueryScope($project, $user, 'tasks.view'),
        );

        if (! empty($filters['sprint'])) {
            $sprint = $filters['sprint'];
            if ($sprint === 'none') {
                $query->whereNull('tasks.sprint_id');
            } else {
                $id = $sprint === 'active' ? $project->sprints()->where('status', 'active')->value('id') : (int) $sprint;
                $query->where('tasks.sprint_id', $id ?: 0);
            }
        }

        if (! empty($filters['status_id'])) {
            $query->where('tasks.status_id', $filters['status_id']);
        }

        if (! empty($filters['priority_id'])) {
            $query->where('tasks.priority_id', $filters['priority_id']);
        }

        if (! empty($filters['assignee_id'])) {
            $query->where('tasks.assignee_id', $filters['assignee_id']);
        }

        if (! empty($filters['label_id'])) {
            $query->whereHas('labels', fn (Builder $q) => $q->where('labels.id', $filters['label_id']));
        }

        if (! empty($filters['q'])) {
            Like::any($query, ['tasks.title', 'tasks.key', 'tasks.description'], $filters['q']);
        }

        if (! empty($filters['due_from'])) {
            $query->whereDate('tasks.due_date', '>=', $filters['due_from']);
        }

        if (! empty($filters['due_to'])) {
            $query->whereDate('tasks.due_date', '<=', $filters['due_to']);
        }

        if (! empty($filters['issue_type_id'])) {
            $query->where('tasks.issue_type_id', $filters['issue_type_id']);
        }

        if (! empty($filters['version_id'])) {
            $query->where('tasks.version_id', $filters['version_id']);
        }

        if (! empty($filters['epic_id'])) {
            $query->where('tasks.epic_id', $filters['epic_id']);
        }

        if (! empty($filters['component_id'])) {
            $query->whereHas('components', fn (Builder $q) => $q->where('project_components.id', $filters['component_id']));
        }

        return $query;
    }
}
