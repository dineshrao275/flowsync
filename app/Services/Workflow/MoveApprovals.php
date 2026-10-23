<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\Task;
use App\Models\TaskMoveRequest;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Tasks\TaskChangeLogger;
use App\Services\TaskService;
use Illuminate\Validation\ValidationException;

/**
 * Approval-gated transitions. A status carrying the `approval` entry rule can only be entered directly by
 * someone who manages the project's workflow; everyone else files a request that a workflow manager approves
 * (the move then runs through the normal service, so blockers, WIP and the other rules still apply) or rejects.
 */
class MoveApprovals
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskChangeLogger $changes,
        private readonly ActivityLogger $activity,
        private readonly WorkflowGuard $guard,
    ) {}

    public function required(Task $task, TaskStatus $to, User $user): bool
    {
        return in_array('approval', $to->entry_rules ?? [], true)
            && (int) $task->status_id !== (int) $to->id
            && ! $user->can('manageWorkflow', $task->project);
    }

    public function request(Task $task, TaskStatus $to, ?int $position, User $user): TaskMoveRequest
    {
        $this->guard->assertCanMove($task, $to); // a closed transition or unmet entry rule is refused up front

        if (TaskMoveRequest::query()->where('task_id', $task->id)->where('to_status_id', $to->id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['form' => __('This move is already waiting for approval.')]);
        }

        $request = TaskMoveRequest::create([
            'task_id' => $task->id, 'project_id' => $task->project_id, 'from_status_id' => $task->status_id,
            'to_status_id' => $to->id, 'position' => $position, 'requested_by' => $user->id, 'status' => 'pending',
        ]);
        $this->activity->log(Task::class, $task->id, 'task.move_requested', ['key' => $task->key, 'to_status' => ['id' => $to->id, 'name' => $to->name]], $user);

        return $request;
    }

    public function approve(TaskMoveRequest $request, User $actor, ?string $note): TaskMoveRequest
    {
        $this->requirePending($request);
        $task = $request->task;
        $oldStatusId = $task->status_id;
        $oldStatus = $task->status;

        $moved = $this->tasks->move($task, $request->to_status_id, $request->position);
        $this->changes->moved($moved, $oldStatusId, $oldStatus, $actor, null);

        $request->update(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now(), 'note' => $note]);
        $this->activity->log(Task::class, $task->id, 'task.move_approved', ['key' => $task->key], $actor);

        return $request->refresh();
    }

    public function reject(TaskMoveRequest $request, User $actor, string $note): TaskMoveRequest
    {
        $this->requirePending($request);
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => __('A rejection needs a reason the requester can act on.')]);
        }

        $request->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'note' => mb_substr(trim($note), 0, 500)]);
        $this->activity->log(Task::class, $request->task_id, 'task.move_rejected', ['key' => $request->task->key], $actor);

        return $request->refresh();
    }

    public function withdraw(TaskMoveRequest $request, User $actor): TaskMoveRequest
    {
        $this->requirePending($request);
        $request->update(['status' => 'withdrawn', 'decided_by' => $actor->id, 'decided_at' => now()]);

        return $request->refresh();
    }

    private function requirePending(TaskMoveRequest $request): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['form' => __('This request has already been decided.')]);
        }
    }
}
