<?php

namespace App\Services;

use App\Models\Label;
use App\Models\Priority;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(
        private readonly KeyGenerator $keyGenerator,
        private readonly TenantLimits $limits,
    ) {}

    public function create(Project $project, array $data, User $creator): Task
    {
        $this->limits->assertQuota('tasks');

        $status = $this->resolveStatus($project, $data['status_id'] ?? null);
        $priority = $this->resolvePriority($project, $data['priority_id'] ?? null);
        $assignee = $this->resolveAssignee($project, $data['assignee_id'] ?? null);
        $parent = $this->resolveParent($project, $data['parent_id'] ?? null);

        if ($status->category->isDone() && $this->hasOpenBlockers($parent)) {
            throw ValidationException::withMessages([
                'form' => 'Cannot complete a task that has open blockers.',
            ]);
        }

        $key = $this->keyGenerator->nextTaskKey($project);

        $task = Task::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $creator->id,
            'reporter_id' => $creator->id,
            'assignee_id' => $assignee?->id,
            'parent_id' => $parent?->id,
            'status_id' => $status->id,
            'priority_id' => $priority?->id,
            'key' => $key[0],
            'sequence' => $key[1],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'estimate_minutes' => $data['estimate_minutes'] ?? null,
            'position' => $this->nextPosition($status),
            'completed_at' => $status->is_done ? now() : null,
        ]);

        $task->labels()->attach($this->resolveLabels($project, $data['labels'] ?? []));

        return $task;
    }

    public function update(Task $task, array $data): Task
    {
        $status = $this->resolveStatus($task->project, $data['status_id'] ?? $task->status_id);
        $priority = $this->resolvePriority($task->project, $data['priority_id'] ?? $task->priority_id);
        $assignee = $this->resolveAssignee($task->project, $data['assignee_id'] ?? $task->assignee_id);
        $parent = $this->resolveParent($task->project, $data['parent_id'] ?? $task->parent_id);

        $statusChanged = ($data['status_id'] ?? null) !== null && (int) $data['status_id'] !== $task->status_id;

        if ($statusChanged && $status->is_done && $this->hasOpenBlockers($task)) {
            throw ValidationException::withMessages([
                'form' => 'Cannot complete a task that has open blockers.',
            ]);
        }

        $task->update([
            'title' => $data['title'] ?? $task->title,
            'description' => array_key_exists('description', $data) ? $data['description'] : $task->description,
            'status_id' => $status->id,
            'priority_id' => $priority?->id,
            'assignee_id' => $assignee?->id,
            'parent_id' => $parent?->id,
            'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $task->due_date,
            'estimate_minutes' => array_key_exists('estimate_minutes', $data) ? $data['estimate_minutes'] : $task->estimate_minutes,
            'completed_at' => $status->is_done ? ($task->completed_at ?? now()) : null,
        ]);

        if (isset($data['labels'])) {
            $task->labels()->sync($this->resolveLabels($task->project, $data['labels']));
        }

        return $task->fresh();
    }

    public function delete(Task $task): void
    {
        $task->delete();
    }

    public function move(Task $task, int $statusId, ?int $index): Task
    {
        $status = $this->resolveStatus($task->project, $statusId);
        $oldStatusId = $task->status_id;

        if ($status->is_done && $this->hasOpenBlockers($task)) {
            throw ValidationException::withMessages([
                'form' => 'Cannot move to Done while this task has open blockers.',
            ]);
        }

        $task->update([
            'status_id' => $status->id,
            'completed_at' => $status->is_done ? ($task->completed_at ?? now()) : null,
        ]);

        $this->reorderColumn($task->project, $status, $task, $index);

        if ($oldStatusId !== $status->id) {
            $oldStatus = $task->project->statuses()->find($oldStatusId);

            if ($oldStatus !== null) {
                $this->reorderColumn($task->project, $oldStatus, $task, null);
            }
        }

        return $task->fresh();
    }

    public function board(Project $project, array $filters): array
    {
        $topLevel = $this->filteredQuery($project, $filters)->whereNull('tasks.parent_id');
        $taskQuery = (clone $topLevel)->get()->groupBy('status_id');

        $statuses = $project->statuses()
            ->orderBy('position')
            ->get()
            ->map(function (TaskStatus $status) use ($taskQuery) {
                $tasks = $taskQuery->get($status->id, collect())
                    ->sortBy(fn (Task $task) => [$task->position, $task->id])
                    ->values();

                return array_merge($this->presentStatus($status), [
                    'tasks_count' => $tasks->count(),
                    'tasks' => $tasks->map(fn (Task $task) => $this->present($task)),
                ]);
            });

        return [
            'statuses' => $statuses,
            'totals' => [
                'open' => (clone $topLevel)->whereNull('completed_at')->count(),
                'done' => (clone $topLevel)->whereNotNull('completed_at')->count(),
            ],
        ];
    }

    public function list(Project $project, array $filters): LengthAwarePaginator
    {
        return $this->filteredQuery($project, $filters)
            ->withCount(['subtasks', 'comments', 'attachments'])
            ->orderBy($filters['sort_by'] ?? 'position', $filters['sort_dir'] ?? 'asc')
            ->paginate(min($filters['per_page'] ?? 25, 100));
    }

    public function show(Task $task): Task
    {
        return $task->loadMissing([
            'status',
            'priority',
            'assignee',
            'reporter',
            'creator',
            'parent:id,key,title',
            'labels',
            'subtasks:id,key,title,status_id,completed_at,parent_id,position',
            'subtasks.status',
        ])->loadCount([
            'subtasks',
            'comments',
            'attachments',
            'openBlockers as open_blockers_count',
        ]);
    }

    private function filteredQuery(Project $project, array $filters): Builder
    {
        $query = Task::query()
            ->where('tasks.project_id', $project->id)
            ->with(['status', 'priority', 'assignee', 'labels'])
            ->withCount([
                'subtasks',
                'comments',
                'attachments',
                'openBlockers as open_blockers_count',
            ]);

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
            $query->where(function (Builder $q) use ($filters) {
                $q->where('tasks.title', 'like', '%'.$filters['q'].'%')
                    ->orWhere('tasks.key', 'like', '%'.$filters['q'].'%')
                    ->orWhere('tasks.description', 'like', '%'.$filters['q'].'%');
            });
        }

        if (! empty($filters['due_from'])) {
            $query->whereDate('tasks.due_date', '>=', $filters['due_from']);
        }

        if (! empty($filters['due_to'])) {
            $query->whereDate('tasks.due_date', '<=', $filters['due_to']);
        }

        return $query;
    }

    private function reorderColumn(Project $project, TaskStatus $status, Task $moved, ?int $index): void
    {
        $ids = $project->tasks()
            ->where('status_id', $status->id)
            ->whereKeyNot($moved->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->values();

        $position = max(0, min($index ?? $ids->count(), $ids->count()));

        $ids = $ids->slice(0, $position)
            ->concat([$moved->id])
            ->concat($ids->slice($position))
            ->values();

        $ids->each(function (int $id, int $position) {
            Task::whereKey($id)->update(['position' => $position + 1]);
        });
    }

    private function nextPosition(TaskStatus $status): int
    {
        return $status->tasks()->max('position') + 1;
    }

    private function hasOpenBlockers(?Task $task): bool
    {
        if ($task === null) {
            return false;
        }

        return $task->openBlockers()->exists();
    }

    private function resolveStatus(Project $project, $statusId): TaskStatus
    {
        if ($statusId === null || $statusId === '') {
            return $project->statuses()
                ->where('is_default', true)
                ->first() ?? $project->statuses()->orderBy('position')->first();
        }

        $status = $project->statuses()->find($statusId);

        if ($status === null) {
            throw ValidationException::withMessages([
                'status_id' => 'The selected status does not belong to this project.',
            ]);
        }

        return $status;
    }

    private function resolvePriority(Project $project, $priorityId): ?Priority
    {
        if ($priorityId === null || $priorityId === '') {
            return Priority::where('is_default', true)
                ->first();
        }

        $priority = Priority::find($priorityId);

        if ($priority === null) {
            throw ValidationException::withMessages([
                'priority_id' => 'The selected priority does not belong to this tenant.',
            ]);
        }

        return $priority;
    }

    private function resolveAssignee(Project $project, $assigneeId): ?User
    {
        if ($assigneeId === null || $assigneeId === '') {
            return null;
        }

        $assignee = User::find($assigneeId);

        if ($assignee === null) {
            throw ValidationException::withMessages([
                'assignee_id' => 'The selected assignee does not belong to this tenant.',
            ]);
        }

        if (! $project->isMember($assignee)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'The selected assignee must be a member of the project.',
            ]);
        }

        return $assignee;
    }

    private function resolveParent(Project $project, $parentId): ?Task
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parent = $project->tasks()->find($parentId);

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => 'The selected parent task does not belong to this project.',
            ]);
        }

        return $parent;
    }

    /**
     * @return list<int>
     */
    private function resolveLabels(Project $project, array $labelIds): array
    {
        $ids = collect($labelIds)->filter()->map(fn ($id) => (int) $id)->values();

        $valid = Label::where('workspace_id', $project->workspace_id)
            ->whereIn('id', $ids)
            ->pluck('id');

        if ($valid->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'labels' => 'One or more selected labels do not belong to this workspace.',
            ]);
        }

        return $valid->all();
    }

    public function presentStatus(TaskStatus $status): array
    {
        return [
            'id' => $status->id,
            'name' => $status->name,
            'slug' => $status->slug,
            'category' => $status->category->value,
            'position' => $status->position,
            'color' => $status->color,
            'is_default' => $status->is_default,
            'is_done' => $status->is_done,
        ];
    }

    public function present(Task $task): array
    {
        return [
            'id' => $task->id,
            'key' => $task->key,
            'sequence' => $task->sequence,
            'title' => $task->title,
            'description' => $task->description,
            'parent_id' => $task->parent_id,
            'status_id' => $task->status_id,
            'status' => $task->status ? $this->presentStatus($task->status) : null,
            'priority_id' => $task->priority_id,
            'priority' => $task->priority ? [
                'id' => $task->priority->id,
                'name' => $task->priority->name,
                'slug' => $task->priority->slug,
                'color' => $task->priority->color,
                'value' => $task->priority->value,
            ] : null,
            'assignee_id' => $task->assignee_id,
            'assignee' => $task->assignee ? ['id' => $task->assignee->id, 'name' => $task->assignee->name, 'email' => $task->assignee->email] : null,
            'labels' => $task->labels->map(fn (Label $label) => [
                'id' => $label->id,
                'name' => $label->name,
                'color' => $label->color,
            ])->values(),
            'due_date' => $task->due_date?->toDateString(),
            'estimate_minutes' => $task->estimate_minutes,
            'position' => $task->position,
            'completed_at' => $task->completed_at?->toISOString(),
            'archived_at' => $task->archived_at?->toISOString(),
            'created_at' => $task->created_at?->toISOString(),
            'subtasks_count' => $task->subtasks_count ?? 0,
            'comments_count' => $task->comments_count ?? 0,
            'attachments_count' => $task->attachments_count ?? 0,
            'open_blockers_count' => $task->open_blockers_count ?? 0,
        ];
    }
}
