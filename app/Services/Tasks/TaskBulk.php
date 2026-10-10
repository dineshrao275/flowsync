<?php

namespace App\Services\Tasks;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Bulk edit / assign / transition over a set of a project's tasks (P4.6). Each task is its own
 * unit of work: it is authorised by the same TaskPolicy ability the single-task endpoint uses
 * (so a narrow `tasks.edit_own` scope only reaches its own rows), runs through TaskService (so
 * workflow rules, blockers and hierarchy checks still bite) and records the same activity row.
 * A task that cannot be changed is reported with the reason; the rest still go through.
 */
class TaskBulk
{
    public const MAX_TASKS = 100;

    public const ACTIONS = ['assign', 'transition', 'update'];

    /** Fields the `update` action may set; labels travel as labels_add / labels_remove. */
    public const UPDATE_FIELDS = ['priority_id', 'due_date', 'start_date', 'version_id', 'story_points', 'estimate_minutes', 'epic_id'];

    public function __construct(private readonly TaskService $tasks, private readonly TaskChangeLogger $changes) {}

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $fields
     * @return array{updated: list<array{id: int, key: string}>, failed: list<array{id: int, key: ?string, reason: string}>}
     */
    public function apply(Project $project, array $ids, string $action, array $fields, User $actor, ?string $ip): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $found = $project->tasks()->whereIn('id', $ids)->with(['status', 'project'])->get()->keyBy('id');
        $result = ['updated' => [], 'failed' => []];

        foreach ($ids as $id) {
            $task = $found->get($id);
            if ($task === null) {
                $result['failed'][] = ['id' => $id, 'key' => null, 'reason' => 'Task not found in this project.'];

                continue;
            }

            try {
                $this->authorizeRow($actor, $task, $action);
                DB::transaction(fn () => $this->applyOne($task, $action, $fields, $actor, $ip));
                $result['updated'][] = ['id' => $task->id, 'key' => $task->key];
            } catch (ValidationException $e) {
                $result['failed'][] = ['id' => $task->id, 'key' => $task->key, 'reason' => collect($e->errors())->flatten()->first()];
            } catch (AuthorizationException) {
                $result['failed'][] = ['id' => $task->id, 'key' => $task->key, 'reason' => 'You are not allowed to change this task.'];
            }
        }

        return $result;
    }

    private function authorizeRow(User $actor, Task $task, string $action): void
    {
        $gate = Gate::forUser($actor);
        $gate->authorize($action === 'transition' ? 'move' : 'edit', $task);

        if ($action === 'assign') {
            $gate->authorize('assign', $task);
        }
    }

    private function applyOne(Task $task, string $action, array $fields, User $actor, ?string $ip): void
    {
        $oldAssignee = $task->assignee_id;
        $oldStatusId = $task->status_id;
        $oldStatus = $task->status;

        if ($action === 'transition') {
            $moved = $this->tasks->move($task, (int) $fields['status_id'], null);
            $this->changes->moved($moved, $oldStatusId, $oldStatus, $actor, $ip);

            return;
        }

        $data = $action === 'assign'
            ? ['assignee_id' => $fields['assignee_id'] ?? null]
            : $this->updateData($task, $fields);

        $updated = $this->tasks->update($task, $data);
        $this->changes->updated($updated, $data, $oldAssignee, $oldStatusId, $oldStatus, $actor, $ip);
    }

    /** @return array<string, mixed> */
    private function updateData(Task $task, array $fields): array
    {
        $data = array_intersect_key($fields, array_flip(self::UPDATE_FIELDS));

        if (! empty($fields['labels_add']) || ! empty($fields['labels_remove'])) {
            $current = $task->labels()->pluck('labels.id')->all();
            $labels = array_diff(array_merge($current, $fields['labels_add'] ?? []), $fields['labels_remove'] ?? []);
            $data['labels'] = array_values(array_unique(array_map('intval', $labels)));
        }

        if ($data === []) {
            throw ValidationException::withMessages(['fields' => 'Nothing to change.']);
        }

        return $data;
    }
}
