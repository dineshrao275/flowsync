<?php

namespace App\Services\Notifications\Families;

use App\Mail\TaskNotificationMail;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkLog;
use App\Services\Notifications\BaseNotificationFamily;

/**
 * The `task` notification family (moved verbatim out of NotificationService, P1.6).
 */
class TaskNotifications extends BaseNotificationFamily
{
    public function family(): string
    {
        return 'task';
    }

    public function taskAssigned(User $actor, Task $task, ?User $assignee = null): ?UserNotification
    {
        $assignee ??= $task->assignee;

        if ($assignee === null || $assignee->id === $actor->id) {
            return null;
        }

        return $this->notify($assignee, 'task.assigned', $this->taskPayload($task), $actor);
    }

    public function taskStatusChanged(User $actor, Task $task, string $fromStatus, string $toStatus): ?UserNotification
    {
        $watcherIds = $task->relationLoaded('watchers')
            ? $task->watchers->pluck('id')
            : $task->watchers()->pluck('users.id');

        $recipientIds = collect([$task->assignee_id])
            ->filter()
            ->concat($watcherIds)
            ->unique()
            ->reject(fn ($id) => (int) $id === $actor->id)
            ->values();

        $primaryNotification = null;
        $recipients = $this->usersById($recipientIds);
        foreach ($recipientIds as $recipientId) {
            $recipient = $recipients->get((int) $recipientId);

            if ($recipient === null) {
                continue;
            }

            $notification = $this->notify($recipient, 'task.status_changed', array_merge($this->taskPayload($task), [
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
            ]), $actor);

            if ($primaryNotification === null || (int) $recipientId === (int) $task->assignee_id) {
                $primaryNotification = $notification;
            }
        }

        return $primaryNotification;
    }

    /**
     * Notifies the task assignee/reporter, task watchers, plus any @mentioned
     * users (excluding the acting user). Mentions beyond the per-comment cap
     * are dropped and `truncated` comes back true so the client can warn the
     * author. Returns the notifications created alongside the flag.
     *
     * @return array{notifications: list<UserNotification>, truncated: bool}
     */
    public function taskCommented(User $actor, Task $task, Comment $comment): array
    {
        $mentioned = $this->mentionUsers($comment->comment)->pluck('id');
        $truncated = $mentioned->count() > TaskNotificationMail::MAX_MENTIONS_PER_COMMENT;

        $watcherIds = $task->relationLoaded('watchers')
            ? $task->watchers->pluck('id')
            : $task->watchers()->pluck('users.id');

        $recipientIds = collect([$task->assignee_id, $task->reporter_id])
            ->filter()
            ->concat($watcherIds)
            ->concat($mentioned->take(TaskNotificationMail::MAX_MENTIONS_PER_COMMENT))
            ->unique()
            ->reject(fn ($id) => (int) $id === $actor->id)
            ->values();

        $sent = [];
        $recipients = $this->usersById($recipientIds);
        foreach ($recipientIds as $recipientId) {
            $recipient = $recipients->get((int) $recipientId);

            if ($recipient === null) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'task.commented', array_merge($this->taskPayload($task), [
                'comment_id' => $comment->id,
                'snippet' => mb_strimwidth($comment->comment, 0, 120, '…'),
            ]), $actor);
        }

        return ['notifications' => $sent, 'truncated' => $truncated];
    }

    public function taskUnblocked(User $actor, Task $task, ?Task $blocker = null): ?UserNotification
    {
        $watcherIds = $task->relationLoaded('watchers')
            ? $task->watchers->pluck('id')
            : $task->watchers()->pluck('users.id');

        $recipientIds = collect([$task->assignee_id])
            ->filter()
            ->concat($watcherIds)
            ->unique()
            ->reject(fn ($id) => (int) $id === $actor->id)
            ->values();

        $data = $this->taskPayload($task);

        if ($blocker !== null) {
            $data['blocked_by'] = [
                'id' => $blocker->id,
                'key' => $blocker->key,
                'title' => $blocker->title,
            ];
        }

        $primaryNotification = null;
        $recipients = $this->usersById($recipientIds);
        foreach ($recipientIds as $recipientId) {
            $recipient = $recipients->get((int) $recipientId);

            if ($recipient === null) {
                continue;
            }

            $notification = $this->notify($recipient, 'task.unblocked', $data, $actor);

            if ($primaryNotification === null || (int) $recipientId === (int) $task->assignee_id) {
                $primaryNotification = $notification;
            }
        }

        return $primaryNotification;
    }

    public function workLogAdded(User $actor, Task $task, WorkLog $log): ?UserNotification
    {
        $assignee = $task->assignee;

        if ($assignee === null || $assignee->id === $actor->id) {
            return null;
        }

        return $this->notify($assignee, 'task.work_logged', array_merge($this->taskPayload($task), [
            'work_log_id' => $log->id,
            'duration_minutes' => $log->duration_minutes,
        ]), $actor);
    }

    private function taskPayload(Task $task): array
    {
        return [
            'task_id' => $task->id,
            'key' => $task->key,
            'title' => $task->title,
            'project_id' => $task->project_id,
            'project_name' => $task->project?->name,
            'workspace_id' => $task->workspace_id,
        ];
    }
}
