<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\Projects\ProjectIssueScheme;
use App\Services\Tasks\TaskColumnOrder;
use App\Services\Tasks\TaskHierarchy;
use App\Services\Tasks\TaskInputResolver;
use App\Services\Tasks\TaskLifecycle;
use App\Services\Tasks\TaskPresenter;
use App\Services\Tasks\TaskReader;
use App\Services\Tasks\TaskUpdater;
use App\Services\Tasks\TaskWatchers;
use App\Services\Workflow\WorkflowGuard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(
        private readonly KeyGenerator $keyGenerator,
        private readonly TenantLimits $limits,
        private readonly WorkflowGuard $workflow,
        private readonly TaskInputResolver $inputs,
        private readonly TaskPresenter $presenter,
        private readonly TaskWatchers $taskWatchers,
        private readonly TaskColumnOrder $columns,
        private readonly TaskReader $reader,
        private readonly TaskHierarchy $hierarchy,
        private readonly TaskLifecycle $lifecycle,
        private readonly TaskUpdater $updater,
    ) {}

    public function create(Project $project, array $data, User $creator): Task
    {
        $this->limits->assertQuota('tasks');

        $status = $this->inputs->status($project, $data['status_id'] ?? null);
        $priority = $this->inputs->priority($project, $data['priority_id'] ?? null);
        $assignee = $this->inputs->assignee($project, $data['assignee_id'] ?? null);
        $parent = $this->inputs->parent($project, $data['parent_id'] ?? null);

        if ($status->category->isDone() && $this->lifecycle->hasOpenBlockers($parent)) {
            throw ValidationException::withMessages([
                'form' => __('Cannot complete a task that has open blockers.'),
            ]);
        }

        $this->lifecycle->assertWip($status);

        $key = $this->keyGenerator->nextTaskKey($project);
        $issueType = $this->inputs->issueType($data['issue_type_id'] ?? null);
        if (! empty($data['issue_type_id'])) {
            ProjectIssueScheme::assertAllowed($project, $issueType);
        }
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
        $this->lifecycle->recordStatusChange($task, null, $status->id);

        if (! empty($data['components'])) {
            $task->components()->attach($this->inputs->components($project, $data['components']));
        }

        return $task;
    }

    public function update(Task $task, array $data): Task
    {
        return $this->updater->update($task, $data);
    }

    public function delete(Task $task): void
    {
        $task->delete();
    }

    public function move(Task $task, int $statusId, ?int $index): Task
    {
        $status = $this->inputs->status($task->project, $statusId);
        $oldStatusId = $task->status_id;

        if ($status->is_done && $this->lifecycle->hasOpenBlockers($task)) {
            throw ValidationException::withMessages([
                'form' => __('Cannot move to Done while this task has open blockers.'),
            ]);
        }

        $this->workflow->assertCanMove($task, $status);

        // WIP applies to arrivals into the column: a reorder inside the same
        // column keeps its own occupancy, so the mover is not counted there.
        $this->lifecycle->assertWip($status, $task->status_id === $status->id ? $task : null);

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
            $this->lifecycle->recordStatusChange($moved, $oldStatusId, $status->id);
        }

        if ($status->is_done) {
            $this->lifecycle->refreshGoalsOnCompletion($moved);
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
