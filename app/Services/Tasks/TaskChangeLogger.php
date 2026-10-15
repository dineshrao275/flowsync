<?php

namespace App\Services\Tasks;

use App\Events\TaskSynced;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\ActivityLogger;

/**
 * The activity row + realtime broadcast that follow a task write. The controllers (edit, move)
 * and the bulk/clone/move-project operations all record the same shapes through here, so the
 * domain-event consumers (notifications, automation, webhooks) see one vocabulary.
 */
class TaskChangeLogger
{
    /** Task fields an edit can change; the activity row lists the ones present in the request. */
    public const EDITABLE = ['title', 'description', 'status_id', 'priority_id', 'assignee_id', 'parent_id', 'epic_id', 'due_date', 'estimate_minutes', 'labels', 'start_date', 'story_points', 'issue_type_id', 'version_id', 'components'];

    public function __construct(private readonly ActivityLogger $logger) {}

    public function created(Task $task, User $actor, ?string $ip, array $extra = []): void
    {
        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.created',
            data: ['key' => $task->key, 'title' => $task->title, 'assignee_id' => $task->assignee_id] + $extra,
            actor: $actor,
            ipAddress: $ip,
        );

        broadcast(new TaskSynced($task, 'created'));
    }

    /** @param array<string, mixed> $data the validated fields the edit carried */
    public function updated(Task $updated, array $data, ?int $oldAssigneeId, ?int $oldStatusId, ?TaskStatus $oldStatus, User $actor, ?string $ip): void
    {
        $assigneeChanged = array_key_exists('assignee_id', $data) && $updated->assignee_id !== $oldAssigneeId;
        $statusChanged = array_key_exists('status_id', $data) && $updated->status_id !== $oldStatusId;

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $updated->id,
            action: 'task.updated',
            data: [
                'fields' => array_values(array_intersect(array_keys($data), self::EDITABLE)),
                'key' => $updated->key,
                'to_is_done' => $updated->status_id !== $oldStatusId && (bool) $updated->status?->is_done,
                'assignee_changed' => $assigneeChanged,
                'status_changed' => $statusChanged,
                'from_status' => $oldStatus?->name ?? 'Unknown',
                'to_status' => $updated->status?->name ?? 'Unknown',
            ],
            actor: $actor,
            ipAddress: $ip,
        );

        broadcast(new TaskSynced($updated, 'updated'));
    }

    public function moved(Task $moved, ?int $oldStatusId, ?TaskStatus $oldStatus, User $actor, ?string $ip): void
    {
        $this->logger->log(
            subjectType: Task::class,
            subjectId: $moved->id,
            action: 'task.moved',
            data: [
                'from_status_id' => $oldStatusId,
                'from_status' => $oldStatus?->name,
                'to_status_id' => $moved->status_id,
                'to_status' => $moved->status?->name,
                'key' => $moved->key,
                'to_is_done' => $moved->status_id !== $oldStatusId && (bool) $moved->status?->is_done,
            ],
            actor: $actor,
            ipAddress: $ip,
        );

        broadcast(new TaskSynced($moved, 'moved'));
    }
}
