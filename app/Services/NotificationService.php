<?php

namespace App\Services;

use App\Events\NotificationSent;
use App\Models\Comment;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class NotificationService
{
    public function notify(
        User $recipient,
        string $type,
        array $data = [],
        ?User $actor = null,
    ): UserNotification {
        $notification = new UserNotification([
            'user_id' => $recipient->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'data' => $data,
        ]);
        $notification->save();

        broadcast(new NotificationSent($notification));

        return $notification;
    }

    public function forUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return UserNotification::query()
            ->with('actor:id,name')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function markAllRead(User $user): int
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
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
        $assignee = $task->assignee;

        if ($assignee === null || $assignee->id === $actor->id) {
            return null;
        }

        return $this->notify($assignee, 'task.status_changed', array_merge($this->taskPayload($task), [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
        ]), $actor);
    }

    /**
     * Notifies the task assignee/reporter plus any @mentioned users
     * (excluding the acting user). Returns the notifications created.
     *
     * @return list<UserNotification>
     */
    public function taskCommented(User $actor, Task $task, Comment $comment): array
    {
        $recipientIds = collect([$task->assignee_id, $task->reporter_id])
            ->filter()
            ->concat($this->mentionUsers($comment->comment)->pluck('id'))
            ->unique()
            ->reject(fn ($id) => (int) $id === $actor->id)
            ->values();

        $sent = [];
        foreach ($recipientIds as $recipientId) {
            $recipient = User::find((int) $recipientId);

            if ($recipient === null) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'task.commented', array_merge($this->taskPayload($task), [
                'comment_id' => $comment->id,
                'snippet' => mb_strimwidth($comment->comment, 0, 120, '…'),
            ]), $actor);
        }

        return $sent;
    }

    public function taskUnblocked(User $actor, Task $task, ?Task $blocker = null): ?UserNotification
    {
        $assignee = $task->assignee;

        if ($assignee === null || $assignee->id === $actor->id) {
            return null;
        }

        $data = $this->taskPayload($task);

        if ($blocker !== null) {
            $data['blocked_by'] = [
                'id' => $blocker->id,
                'key' => $blocker->key,
                'title' => $blocker->title,
            ];
        }

        return $this->notify($assignee, 'task.unblocked', $data, $actor);
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

    /**
     * Nudge whoever owns an onboarding checklist item that is coming due.
     *
     * Two legs, deliberately asymmetric: the owner always hears about their
     * own item, and the HR managers additionally hear about hr-scoped items —
     * because an hr item with no named owner belongs to the pool, and a pool
     * nobody nudges is a pile nobody works. Anyone else’s item is none of
     * their business, which is why manager- and employee-scoped items notify
     * only the owner.
     *
     * @return list<UserNotification>
     */
    public function onboardingTaskDue(Employee $employee, OnboardingCaseTask $task, ?User $actor = null): array
    {
        $recipientIds = collect();

        $owner = $task->owner_employee_id !== null ? Employee::find($task->owner_employee_id) : null;

        if ($owner?->user_id !== null) {
            $recipientIds->push((int) $owner->user_id);
        }

        if ($task->owner_scope === 'hr') {
            $recipientIds = $recipientIds->concat($this->usersWith('hrms.onboarding.manage'));
        }

        $sent = [];
        foreach ($recipientIds->unique()->reject(fn ($id) => $actor !== null && (int) $id === $actor->id)->values() as $recipientId) {
            $recipient = User::find((int) $recipientId);

            if ($recipient === null) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'hrms.onboarding.task_due', [
                'onboarding_case_id' => $task->case_id,
                'case_task_id' => $task->id,
                'title' => $task->title,
                'employee_name' => $employee->displayName(),
                'due_date' => $task->due_date?->toDateString(),
            ], $actor);
        }

        return $sent;
    }

    /**
     * Every login holding a permission, for pool-owned notifications (an hr
     * item belongs to whoever holds manage, not to a named person).
     *
     * @return list<int>
     */
    private function usersWith(string $permission): array
    {
        return User::whereHas('roles.permissions', fn ($query) => $query->where('slug', $permission))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Resolves @mention tokens in text to users in the current tenant database.
     * Tokens match a user's full email, email local part (e.g. @viewer matches
     * viewer@flowsync.test), or name, case-insensitively.
     *
     * @return Collection<int, User>
     */
    public function mentionUsers(string $text): Collection
    {
        preg_match_all('/@([A-Za-z0-9._-]+)/', $text, $matches);

        $users = collect();
        foreach ($matches[1] as $token) {
            $token = mb_strtolower($token);

            $query = User::query()->where(function ($query) use ($token) {
                if (str_contains($token, '@')) {
                    $query->whereRaw('LOWER(email) = ?', [$token]);
                } else {
                    $query->whereRaw('LOWER(email) LIKE ?', [$token.'@%'])
                        ->orWhereRaw('LOWER(name) = ?', [$token]);
                }
            });

            $users = $users->concat($query->get());
        }

        return $users->unique('id')->values();
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
