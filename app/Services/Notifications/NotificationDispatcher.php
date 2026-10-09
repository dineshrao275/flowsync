<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationSender;
use App\Events\NotificationSent;
use App\Mail\TaskNotificationMail;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;

/**
 * Persists, broadcasts and (for the emailable TMS events) queues mail for a
 * single notification, plus the self-scoped inbox reads.
 */
class NotificationDispatcher implements NotificationSender
{
    /**
     * The TMS events that also trigger a queued email next to the in-app row.
     * HRMS nudges never email; their receipts live in the app only.
     *
     * @var list<string>
     */
    private const EMAIL_EVENTS = [
        'task.assigned',
        'task.status_changed',
        'task.commented',
        'task.unblocked',
    ];

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

        $this->maybeQueueMail($recipient, $type, $data, $actor);

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

    /**
     * Queues the task email for an emailable event, honoring the recipient's
     * per-event preference. The mailable snapshots scalars at construction so
     * nothing is re-queried at delivery; the queue stamping listener still has
     * the tenant id from this request if the worker ever needs it.
     */
    private function maybeQueueMail(User $recipient, string $type, array $data, ?User $actor): void
    {
        if (! in_array($type, self::EMAIL_EVENTS, true)) {
            return;
        }

        if (! NotificationPreference::wants($recipient, $type)) {
            return;
        }

        Mail::to($recipient->email, $recipient->name)->queue(
            TaskNotificationMail::fromData($type, $actor?->name ?? 'Someone', $data, $this->taskDeepLink($type, $data)),
        );
    }

    /**
     * The SPA deep link for a task notification, mirroring the client-side
     * taskUrl(): the Tasks tab with the drawer auto-opened on the relevant
     * section (comments for task.commented).
     */
    private function taskDeepLink(string $type, array $data): string
    {
        $query = ['tab' => 'tasks', 'task' => $data['key'] ?? null];

        if ($type === 'task.commented') {
            $query['section'] = 'comments';
        }

        return url('/app/projects/'.($data['project_id'] ?? '0').'?'.http_build_query($query));
    }
}
