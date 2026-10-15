<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationFamily;
use App\Contracts\Notifications\NotificationSender;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;

/**
 * Shared plumbing for the notification families: sending and recipient
 * lookups delegate to the injected sender/resolver so family bodies read the
 * same as they did inside the old monolithic service.
 */
abstract class BaseNotificationFamily implements NotificationFamily
{
    public function __construct(
        protected NotificationSender $sender,
        protected RecipientResolver $resolver,
    ) {}

    protected function notify(User $recipient, string $type, array $data = [], ?User $actor = null): UserNotification
    {
        return $this->sender->notify($recipient, $type, $data, $actor);
    }

    /**
     * @param  iterable<int|string>  $ids
     * @return Collection<int, User>
     */
    protected function usersById(iterable $ids): Collection
    {
        return $this->resolver->usersById($ids);
    }

    /** @return list<int> */
    protected function usersWith(string $permission): array
    {
        return $this->resolver->usersWith($permission);
    }

    /** @return list<int> */
    protected function usersWithRole(int $roleId): array
    {
        return $this->resolver->usersWithRole($roleId);
    }

    /** @return Collection<int, User> */
    protected function mentionUsers(string $text): Collection
    {
        return $this->resolver->mentionUsers($text);
    }
}
