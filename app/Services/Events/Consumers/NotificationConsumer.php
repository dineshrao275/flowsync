<?php

namespace App\Services\Events\Consumers;

use App\Models\Comment;
use App\Models\DomainEvent;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\NotificationService;

/**
 * Turns the task domain events into the in-app (and emailable) notifications
 * that controllers used to send inline (P2.3).
 *
 * The producing controllers opt in by putting the facts a notification needs
 * on the event (`assignee_id` on created, `assignee_changed`/`status_changed`
 * on updated, `unblocked` on dependency_deleted). Events written by other
 * emitters — the automation engine tags its own with `automation_rule_id` —
 * lack them or carry the tag and are skipped, so they notify exactly as
 * before: not at all.
 */
class NotificationConsumer
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(DomainEvent $event): void
    {
        $data = $event->data ?? [];

        if (isset($data['automation_rule_id'])) {
            return;
        }

        $actor = $event->actor_user_id !== null ? User::find($event->actor_user_id) : null;
        $task = $event->subject_id !== null ? Task::find($event->subject_id) : null;

        if ($actor === null || $task === null) {
            return;
        }

        match ($event->type) {
            'task.created' => $this->created($actor, $task, $data),
            'task.updated' => $this->updated($actor, $task, $data),
            'task.moved' => $this->moved($actor, $task, $data),
            'task.commented' => $this->commented($actor, $task, $data),
            'task.work_logged' => $this->workLogged($actor, $task, $data),
            'task.dependency_deleted' => $this->dependencyDeleted($actor, $task, $data),
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function created(User $actor, Task $task, array $data): void
    {
        if (array_key_exists('assignee_id', $data)) {
            $this->notifications->taskAssigned($actor, $task);
        }
    }

    /** @param array<string, mixed> $data */
    private function updated(User $actor, Task $task, array $data): void
    {
        if (! empty($data['assignee_changed'])) {
            $this->notifications->taskAssigned($actor, $task);
        }

        if (! empty($data['status_changed'])) {
            $this->notifications->taskStatusChanged(
                $actor,
                $task,
                $data['from_status'] ?? 'Unknown',
                $data['to_status'] ?? 'Unknown',
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function moved(User $actor, Task $task, array $data): void
    {
        if (($data['from_status_id'] ?? null) !== ($data['to_status_id'] ?? null)) {
            $this->notifications->taskStatusChanged(
                $actor,
                $task,
                $data['from_status'] ?? 'Unknown',
                $data['to_status'] ?? 'Unknown',
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function commented(User $actor, Task $task, array $data): void
    {
        $comment = isset($data['comment_id']) ? Comment::find($data['comment_id']) : null;

        if ($comment !== null) {
            $this->notifications->taskCommented($actor, $task, $comment);
        }
    }

    /** @param array<string, mixed> $data */
    private function workLogged(User $actor, Task $task, array $data): void
    {
        $log = isset($data['work_log_id']) ? WorkLog::find($data['work_log_id']) : null;

        if ($log !== null) {
            $this->notifications->workLogAdded($actor, $task, $log);
        }
    }

    /** @param array<string, mixed> $data */
    private function dependencyDeleted(User $actor, Task $task, array $data): void
    {
        if (! empty($data['unblocked'])) {
            $blocker = isset($data['depends_on_task_id']) ? Task::find($data['depends_on_task_id']) : null;

            $this->notifications->taskUnblocked($actor, $task, $blocker);
        }
    }
}
