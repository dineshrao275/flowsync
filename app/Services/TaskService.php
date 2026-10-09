<?php

namespace App\Services;

use App\Models\IssueType;
use App\Models\Label;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\ProjectVersion;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskStatusHistory;
use App\Models\User;
use App\Services\Hrms\PerformanceService;
use App\Services\Workflow\WorkflowGuard;
use App\Support\Hrms\HrmsSchema;
use App\Support\Like;
use App\Support\TaskScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(
        private readonly KeyGenerator $keyGenerator,
        private readonly TenantLimits $limits,
        private readonly PerformanceService $performance,
        private readonly WorkflowGuard $workflow,
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
        $issueType = $this->resolveIssueType($data['issue_type_id'] ?? null);
        $version = $this->resolveVersion($project, $data['version_id'] ?? null);

        $task = Task::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $creator->id,
            'reporter_id' => $creator->id,
            'assignee_id' => $assignee?->id,
            'parent_id' => $parent?->id,
            'status_id' => $status->id,
            'priority_id' => $priority?->id,
            'issue_type_id' => $issueType?->id,
            'version_id' => $version?->id,
            'key' => $key[0],
            'sequence' => $key[1],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'story_points' => $data['story_points'] ?? null,
            'estimate_minutes' => $data['estimate_minutes'] ?? null,
            'position' => $this->nextPosition($status),
            'completed_at' => $status->is_done ? now() : null,
        ]);

        $task->labels()->attach($this->resolveLabels($project, $data['labels'] ?? []));
        $this->recordStatusChange($task, null, $status->id);

        if (! empty($data['components'])) {
            $task->components()->attach($this->resolveComponents($project, $data['components']));
        }

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

        $updateData = [
            'title' => $data['title'] ?? $task->title,
            'description' => array_key_exists('description', $data) ? $data['description'] : $task->description,
            'status_id' => $status->id,
            'priority_id' => $priority?->id,
            'assignee_id' => $assignee?->id,
            'parent_id' => $parent?->id,
            'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $task->due_date,
            'estimate_minutes' => array_key_exists('estimate_minutes', $data) ? $data['estimate_minutes'] : $task->estimate_minutes,
            'completed_at' => $status->is_done ? ($task->completed_at ?? now()) : null,
        ];

        if (array_key_exists('start_date', $data)) {
            $updateData['start_date'] = $data['start_date'];
        }
        if (array_key_exists('story_points', $data)) {
            $updateData['story_points'] = $data['story_points'];
        }
        if (array_key_exists('issue_type_id', $data)) {
            $updateData['issue_type_id'] = $this->resolveIssueType($data['issue_type_id'])?->id;
        }
        if (array_key_exists('version_id', $data)) {
            $updateData['version_id'] = $this->resolveVersion($task->project, $data['version_id'])?->id;
        }

        $completing = $status->is_done && (int) $task->status_id !== (int) $status->id;
        $fromStatusId = (int) $task->status_id;
        $statusMoves = $fromStatusId !== (int) $status->id;
        if ($statusMoves) {
            // Judge the task as it is about to be saved, so one edit can satisfy the entry rule it triggers.
            $this->workflow->assertCanMove($task, $status, array_intersect_key($updateData, array_flip(['assignee_id', 'due_date', 'estimate_minutes', 'story_points'])));
        }

        $task->update($updateData);

        if (array_key_exists('labels', $data)) {
            $task->labels()->sync($this->resolveLabels($task->project, $data['labels'] ?? []));
        }

        if (array_key_exists('components', $data)) {
            $task->components()->sync($this->resolveComponents($task->project, $data['components'] ?? []));
        }

        $updated = $task->fresh();

        if ($statusMoves) {
            $this->recordStatusChange($updated, $fromStatusId, (int) $status->id);
        }

        if ($completing) {
            $this->refreshGoalsOnCompletion($updated);
        }

        return $updated;
    }

    /** One history row per status change — the basis for cycle and lead time. */
    private function recordStatusChange(Task $task, ?int $from, int $to): void
    {
        TaskStatusHistory::create([
            'task_id' => $task->id, 'from_status_id' => $from, 'to_status_id' => $to,
            'user_id' => Auth::id(), 'changed_at' => now(),
        ]);
    }

    /**
     * A completion re-photographs every goal evidencing the task, so linked goals update on
     * the event rather than waiting for the nightly sweep. Evidence only — it never writes a
     * rating or a status. A tenant without the HRMS product has no goals to refresh.
     */
    private function refreshGoalsOnCompletion(Task $task): void
    {
        if (HrmsSchema::present()) {
            $this->performance->refreshTaskGoals($task);
        }
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

        $this->workflow->assertCanMove($task, $status);

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

        $moved = $task->fresh();

        if ($oldStatusId !== $status->id) {
            $this->recordStatusChange($moved, $oldStatusId, $status->id);
        }

        if ($status->is_done) {
            $this->refreshGoalsOnCompletion($moved);
        }

        return $moved;
    }

    public function board(Project $project, array $filters, User $user): array
    {
        $topLevel = $this->filteredQuery($project, $filters, $user)->whereNull('tasks.parent_id');

        $columnLimit = isset($filters['column_limit'])
            ? max(1, min((int) $filters['column_limit'], 500))
            : (isset($filters['limit']) ? max(1, min((int) $filters['limit'], 500)) : null);

        // Pre-count status totals for high-volume boards in a single query
        $statusCounts = (clone $topLevel)
            ->reorder()
            ->select('tasks.status_id', DB::raw('count(*) as count'))
            ->groupBy('tasks.status_id')
            ->pluck('count', 'tasks.status_id');

        $taskQuery = (clone $topLevel)->get()->groupBy('status_id');

        $statuses = $project->statuses()
            ->orderBy('position')
            ->get()
            ->map(function (TaskStatus $status) use ($taskQuery, $statusCounts, $columnLimit) {
                $allTasks = $taskQuery->get($status->id, collect())
                    ->sortBy(fn (Task $task) => [$task->position, $task->id])
                    ->values();

                $totalCount = (int) ($statusCounts->get($status->id) ?? $allTasks->count());
                $tasks = $columnLimit !== null ? $allTasks->take($columnLimit) : $allTasks;

                $data = array_merge($this->presentStatus($status), [
                    'tasks_count' => $totalCount,
                    'tasks' => $tasks->map(fn (Task $task) => $this->present($task)),
                ]);

                if ($columnLimit !== null) {
                    $data['has_more'] = $totalCount > $tasks->count();
                    $data['column_limit'] = $columnLimit;
                }

                return $data;
            });

        // Fast single-pass totals scan
        $totalsRow = (clone $topLevel)
            ->reorder()
            ->selectRaw('count(case when completed_at is null then 1 end) as open_count, count(case when completed_at is not null then 1 end) as done_count')
            ->first();

        return [
            'statuses' => $statuses,
            'totals' => [
                'open' => (int) ($totalsRow?->open_count ?? 0),
                'done' => (int) ($totalsRow?->done_count ?? 0),
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

    private function filteredQuery(Project $project, array $filters, User $user): Builder
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

        if (! empty($filters['component_id'])) {
            $query->whereHas('components', fn (Builder $q) => $q->where('project_components.id', $filters['component_id']));
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

        $this->writePositions($ids);
    }

    /**
     * One UPDATE with CASE id WHEN … THEN … instead of N per-row writes.
     *
     * @param  Collection<int, int>  $ids
     */
    private function writePositions(Collection $ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        $cases = [];
        $bindings = [];

        foreach ($ids as $offset => $id) {
            $cases[] = 'when ? then ?';
            $bindings[] = $id;
            $bindings[] = $offset + 1;
        }

        $placeholders = implode(',', array_fill(0, $ids->count(), '?'));
        $bindings = array_merge($bindings, $ids->all());

        DB::update(
            'update tasks set position = case id '.implode(' ', $cases).' end where id in ('.$placeholders.')',
            $bindings,
        );
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
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'story_points' => $task->story_points !== null ? (float) $task->story_points : null,
            'sprint_id' => $task->sprint_id,
            'issue_type_id' => $task->issue_type_id,
            'issue_type' => $task->issueType ? [
                'id' => $task->issueType->id,
                'name' => $task->issueType->name,
                'slug' => $task->issueType->slug,
                'icon' => $task->issueType->icon,
                'color' => $task->issueType->color,
                'is_subtask' => $task->issueType->is_subtask,
            ] : null,
            'version_id' => $task->version_id,
            'version' => $task->version ? [
                'id' => $task->version->id,
                'name' => $task->version->name,
                'released' => $task->version->released,
                'release_date' => $task->version->release_date?->toDateString(),
            ] : null,
            'components' => $task->components->map(fn (ProjectComponent $comp) => [
                'id' => $comp->id,
                'name' => $comp->name,
            ])->values(),
            'watchers' => ($task->relationLoaded('watchers') ? $task->watchers : $task->watchers()->select(['users.id', 'users.name', 'users.email'])->get())->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values(),
            'estimate_minutes' => $task->estimate_minutes,
            'position' => $task->position,
            'completed_at' => $task->completed_at?->toISOString(),
            'archived_at' => $task->archived_at?->toISOString(),
            'created_at' => $task->created_at?->toISOString(),
            'subtasks_count' => $task->subtasks_count ?? 0,
            'comments_count' => $task->comments_count ?? 0,
            'attachments_count' => $task->attachments_count ?? 0,
            'open_blockers_count' => $task->open_blockers_count ?? 0,
            'checklist_done_count' => (int) ($task->checklist_done_count ?? 0),
            'checklist_total' => (int) ($task->checklist_total ?? 0),
        ];
    }

    public function addWatcher(Task $task, User $user): void
    {
        $task->watchers()->syncWithoutDetaching([$user->id => ['created_at' => now()]]);
    }

    public function removeWatcher(Task $task, User $user): void
    {
        $task->watchers()->detach($user->id);
    }

    /**
     * @return Collection<int, User>
     */
    public function watchers(Task $task): Collection
    {
        return $task->watchers()->select(['users.id', 'users.name', 'users.email'])->get();
    }

    /**
     * @param  array<int, mixed>  $componentIds
     * @return list<int>
     */
    private function resolveComponents(Project $project, array $componentIds): array
    {
        $ids = collect($componentIds)->filter()->map(fn ($id) => (int) $id)->values();

        $valid = $project->components()->whereIn('id', $ids)->pluck('id');

        if ($valid->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'components' => 'One or more selected components do not belong to this project.',
            ]);
        }

        return $valid->all();
    }

    private function resolveVersion(Project $project, $versionId): ?ProjectVersion
    {
        if ($versionId === null || $versionId === '') {
            return null;
        }

        $version = $project->versions()->find($versionId);

        if ($version === null) {
            throw ValidationException::withMessages([
                'version_id' => 'The selected version does not belong to this project.',
            ]);
        }

        return $version;
    }

    private function resolveIssueType($issueTypeId): ?IssueType
    {
        if ($issueTypeId === null || $issueTypeId === '') {
            return IssueType::where('slug', config('issue_types.default_slug', 'task'))->first();
        }

        $type = IssueType::find($issueTypeId);

        if ($type === null) {
            throw ValidationException::withMessages([
                'issue_type_id' => 'The selected issue type does not exist.',
            ]);
        }

        return $type;
    }
}
