<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationSender;
use App\Events\NotificationSent;
use App\Mail\TaskNotificationMail;
use App\Models\NotificationDigestItem;
use App\Models\NotificationPolicy;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Locales;
use App\Support\Realtime;
use App\Support\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

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
        // The same notification (recipient + type + payload) inside the dedupe window is
        // written and sent once: bursts of identical updates (a rule firing twice, a
        // double click) must not double-ping anyone.
        $dedupeKey = $this->dedupeKey($recipient, $type, $data);
        $duplicate = $this->recentDuplicate($recipient, $dedupeKey);
        if ($duplicate !== null) {
            return $duplicate;
        }

        $notification = new UserNotification([
            'user_id' => $recipient->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'data' => $data,
        ]);
        if ($dedupeKey !== null) {
            $notification->dedupe_key = $dedupeKey;
        }
        $notification->save();

        Realtime::send(new NotificationSent($notification));

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

        // Tenant policy first (an admin can switch an event's email off for everyone),
        // then the recipient's own per-event preference. In-app rows are unaffected.
        if (! NotificationPolicy::allows($type)) {
            return;
        }

        if (! NotificationPreference::wants($recipient, $type)) {
            return;
        }

        $url = $this->taskDeepLink($type, $data);

        // Digest mode — or a burst past the per-hour email cap — holds the event;
        // `notifications:send-digests` sends one mail per user.
        if (NotificationPreference::emailMode($recipient) === NotificationPreference::MODE_DIGEST
            || $this->overEmailThrottle($recipient)) {
            NotificationDigestItem::create([
                'user_id' => $recipient->id,
                'type' => $type,
                'actor_name' => $actor?->name,
                'data' => $data + ['_url' => $url],
                'created_at' => now(),
            ]);

            return;
        }

        Mail::to($recipient->email, $recipient->name)->queue(
            TaskNotificationMail::fromData($type, $actor?->name ?? '', $data, $url, $this->localeFor($recipient)),
        );
    }

    private function dedupeKey(User $recipient, string $type, array $data): ?string
    {
        if ((int) config('notifications.dedupe_window_seconds', 0) <= 0 || ! Schema::hasColumn('notifications', 'dedupe_key')) {
            return null;
        }

        return substr(sha1($recipient->id.'|'.$type.'|'.json_encode($data)), 0, 40);
    }

    private function recentDuplicate(User $recipient, ?string $key): ?UserNotification
    {
        if ($key === null) {
            return null;
        }

        return UserNotification::query()
            ->where('user_id', $recipient->id)
            ->where('dedupe_key', $key)
            ->where('created_at', '>=', now()->subSeconds((int) config('notifications.dedupe_window_seconds')))
            ->first();
    }

    /**
     * True once this person has been emailed `max` times inside the window; the
     * caller then routes the event to the digest instead of sending it. The
     * counter lives in the shared cache, keyed by tenant so ids never collide.
     */
    private function overEmailThrottle(User $recipient): bool
    {
        $max = (int) config('notifications.email_throttle.max', 0);
        $minutes = (int) config('notifications.email_throttle.per_minutes', 60);

        if ($max <= 0) {
            return false;
        }

        $key = 'notif-mail:'.(app(TenantContext::class)->currentId() ?? 0).':'.$recipient->id;
        Cache::add($key, 0, now()->addMinutes($minutes));

        return Cache::increment($key) > $max;
    }

    /**
     * The language the recipient reads their mail in: their own
     * `user_settings.locale` when set and supported, otherwise the locale the
     * request/tenant already resolved (the actor and recipient share a tenant,
     * so that is the tenant default). The mailable snapshots the composed copy
     * at construction, so this decision never rides the queue.
     */
    private function localeFor(User $recipient): string
    {
        return Locales::normalize($recipient->settings?->settings['locale'] ?? null)
            ?? app()->getLocale();
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
            if (! empty($data['comment_id'])) {
                $query['comment'] = $data['comment_id'];
            }
        } elseif ($type === 'task.work_logged') {
            $query['section'] = 'time';
        } elseif ($type === 'task.unblocked') {
            $query['section'] = 'dependencies';
        }

        return url('/app/projects/'.($data['project_id'] ?? '0').'?'.http_build_query($query));
    }
}
