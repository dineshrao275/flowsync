<?php

namespace App\Services\Hrms;

use App\Enums\Hrms\TaskLinkKind;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\TaskLink\TaskLink;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * TaskLink/HRMS — naming which work counts for whom.
 *
 * Links are evidence-only (D2.10): they let a reviewer audit a number,
 * never feed a score. Creation is idempotent (`firstOrCreate` on the
 * grain) — a double-clicked "link" button must not duplicate the claim —
 * and every link/unlink photographs the task's activity timeline, because
 * project members see the task change even when they never open HR.
 *
 * Visibility stays with the caller: `forEmployee` takes the caller's
 * already-scoped task builder (the controller's `visibleTaskQuery`) and
 * only intersects it with this person's links, so the canonical "which
 * tasks may this login see" rule lives in exactly one place and this
 * service can never widen it.
 */
class TaskLinkService
{
    public function __construct(private readonly ActivityLogger $activities) {}

    public function link(Employee $employee, Task $task, TaskLinkKind $kind, ?string $note, ?User $actor, ?string $ipAddress = null): TaskLink
    {
        $link = TaskLink::query()->firstOrCreate(
            ['employee_id' => $employee->id, 'task_id' => $task->id, 'kind' => $kind],
            ['note' => $note, 'created_by' => $actor?->id],
        );

        if ($link->wasRecentlyCreated) {
            $this->activities->log(
                Task::class,
                $task->id,
                'task.link_created',
                ['employee_id' => $employee->id, 'kind' => $kind->value],
                $actor,
                $ipAddress,
            );
        }

        return $link->loadMissing(['employee:id,employee_code,name', 'creator:id,name']);
    }

    public function unlink(TaskLink $link, ?User $actor, ?string $ipAddress = null): void
    {
        $employeeId = $link->employee_id;
        $taskId = $link->task_id;
        $kind = $link->kind instanceof TaskLinkKind ? $link->kind->value : (string) $link->kind;

        $link->delete();

        $this->activities->log(
            Task::class,
            $taskId,
            'task.link_deleted',
            ['employee_id' => $employeeId, 'kind' => $kind],
            $actor,
            $ipAddress,
        );
    }

    /**
     * @return Collection<int, TaskLink>
     */
    public function forTask(Task $task): Collection
    {
        return TaskLink::query()
            ->where('task_id', $task->id)
            ->with(['employee:id,employee_code,name', 'creator:id,name'])
            ->orderBy('id')
            ->get();
    }

    /**
     * This person's linked tasks, intersected with the caller's visibility.
     *
     * @param  Builder<Task>  $visible  The caller's scoped task query.
     * @param  array{kind?: TaskLinkKind|string|null, status_id?: int|null}  $filters
     * @return Collection<int, Task>
     */
    public function forEmployee(Employee $employee, Builder $visible, array $filters = []): Collection
    {
        $kind = $filters['kind'] ?? null;
        $kind = $kind instanceof TaskLinkKind ? $kind : ($kind === null ? null : TaskLinkKind::from((string) $kind));

        $links = TaskLink::query()
            ->where('employee_id', $employee->id)
            ->when($kind !== null, fn (Builder $query) => $query->where('kind', $kind))
            ->orderBy('id')
            ->get()
            ->keyBy('task_id');

        if ($links->isEmpty()) {
            return collect();
        }

        $tasks = $visible
            ->whereIn('tasks.id', $links->keys()->all())
            ->when(
                isset($filters['status_id']),
                fn (Builder $query) => $query->where('tasks.status_id', (int) $filters['status_id']),
            )
            ->orderBy('tasks.id')
            ->get();

        foreach ($tasks as $task) {
            $task->setRelation('taskLink', $links->get($task->id));
        }

        return $tasks;
    }
}
