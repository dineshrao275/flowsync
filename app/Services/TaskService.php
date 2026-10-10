<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskStatusHistory;
use App\Models\User;
use App\Services\Hrms\PerformanceService;
use App\Services\Tasks\TaskColumnOrder;
use App\Services\Tasks\TaskHierarchy;
use App\Services\Tasks\TaskInputResolver;
use App\Services\Tasks\TaskPresenter;
use App\Services\Tasks\TaskReader;
use App\Services\Tasks\TaskWatchers;
use App\Services\Workflow\WorkflowGuard;
use App\Support\Hrms\HrmsSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(
        private readonly KeyGenerator $keyGenerator,
        private readonly TenantLimits $limits,
        private readonly PerformanceService $performance,
        private readonly WorkflowGuard $workflow,
        private readonly TaskInputResolver $inputs,
        private readonly TaskPresenter $presenter,
        private readonly TaskWatchers $taskWatchers,
        private readonly TaskColumnOrder $columns,
        private readonly TaskReader $reader,
        private readonly TaskHierarchy $hierarchy,
    ) {}

    public function create(Project $project, array $data, User $creator): Task
    {
        $this->limits->assertQuota('tasks');

        $status = $this->inputs->status($project, $data['status_id'] ?? null);
        $priority = $this->inputs->priority($project, $data['priority_id'] ?? null);
        $assignee = $this->inputs->assignee($project, $data['assignee_id'] ?? null);
        $parent = $this->inputs->parent($project, $data['parent_id'] ?? null);

        if ($status->category->isDone() && $this->hasOpenBlockers($parent)) {
            throw ValidationException::withMessages([
                'form' => 'Cannot complete a task that has open blockers.',
            ]);
        }

        $key = $this->keyGenerator->nextTaskKey($project);
        $issueType = $this->inputs->issueType($data['issue_type_id'] ?? null);
        $epic = $this->hierarchy->epic($project, $data['epic_id'] ?? null);
        $this->hierarchy->assert(null, $issueType, $parent, $epic);
        $version = $this->inputs->version($project, $data['version_id'] ?? null);

        $task = Task::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $creator->id,
            'reporter_id' => $creator->id,
            'assignee_id' => $assignee?->id,
            'parent_id' => $parent?->id,
            'epic_id' => $epic?->id,
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
            'position' => $this->columns->nextPosition($status),
            'completed_at' => $status->is_done ? now() : null,
        ]);

        $task->labels()->attach($this->inputs->labels($project, $data['labels'] ?? []));
        $this->recordStatusChange($task, null, $status->id);

        if (! empty($data['components'])) {
            $task->components()->attach($this->inputs->components($project, $data['components']));
        }

        return $task;
    }

    public function update(Task $task, array $data): Task
    {
        $status = $this->inputs->status($task->project, $data['status_id'] ?? $task->status_id);
        $priority = $this->inputs->priority($task->project, $data['priority_id'] ?? $task->priority_id);
        $assignee = $this->inputs->assignee($task->project, array_key_exists('assignee_id', $data) ? $data['assignee_id'] : $task->assignee_id);
        $parent = $this->inputs->parent($task->project, array_key_exists('parent_id', $data) ? $data['parent_id'] : $task->parent_id);

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
        $this->applyHierarchy($task, $data, $parent, $updateData);
        if (array_key_exists('version_id', $data)) {
            $updateData['version_id'] = $this->inputs->version($task->project, $data['version_id'])?->id;
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
            $task->labels()->sync($this->inputs->labels($task->project, $data['labels'] ?? []));
        }

        if (array_key_exists('components', $data)) {
            $task->components()->sync($this->inputs->components($task->project, $data['components'] ?? []));
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

    /** Validates the type/parent/epic a write ends with and adds the type and epic columns to the update. */
    private function applyHierarchy(Task $task, array $data, ?Task $parent, array &$updateData): void
    {
        $type = array_key_exists('issue_type_id', $data) ? $this->inputs->issueType($data['issue_type_id']) : $task->issueType;
        $epic = array_key_exists('epic_id', $data) ? $this->hierarchy->epic($task->project, $data['epic_id']) : $task->epic;

        $touched = array_key_exists('issue_type_id', $data) || array_key_exists('epic_id', $data) || array_key_exists('parent_id', $data);
        if ($touched) {
            if (array_key_exists('issue_type_id', $data) && $type !== null) {
                $this->hierarchy->assertRetypable($task, $type);
            }
            $this->hierarchy->assert($task, $type, $parent, $epic);
        }

        if (array_key_exists('issue_type_id', $data)) {
            $updateData['issue_type_id'] = $type?->id;
        }
        if (array_key_exists('epic_id', $data)) {
            $updateData['epic_id'] = $epic?->id;
        }
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
        $status = $this->inputs->status($task->project, $statusId);
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

        $this->columns->reorderColumn($task->project, $status, $task, $index);

        if ($oldStatusId !== $status->id) {
            $oldStatus = $task->project->statuses()->find($oldStatusId);

            if ($oldStatus !== null) {
                $this->columns->reorderColumn($task->project, $oldStatus, $task, null);
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
        return $this->reader->board($project, $filters, $user);
    }

    public function list(Project $project, array $filters, User $user): LengthAwarePaginator
    {
        return $this->reader->list($project, $filters, $user);
    }

    public function show(Task $task): Task
    {
        return $this->reader->show($task);
    }

    private function hasOpenBlockers(?Task $task): bool
    {
        if ($task === null) {
            return false;
        }

        return $task->openBlockers()->exists();
    }

    public function presentStatus(TaskStatus $status): array
    {
        return $this->presenter->presentStatus($status);
    }

    public function present(Task $task): array
    {
        return $this->presenter->present($task);
    }

    public function addWatcher(Task $task, User $user): void
    {
        $this->taskWatchers->add($task, $user);
    }

    public function removeWatcher(Task $task, User $user): void
    {
        $this->taskWatchers->remove($task, $user);
    }

    /**
     * @return Collection<int, User>
     */
    public function watchers(Task $task): Collection
    {
        return $this->taskWatchers->list($task);
    }
}
