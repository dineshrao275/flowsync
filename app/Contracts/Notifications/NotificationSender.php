<?php

namespace App\Contracts\Notifications;

use App\Models\User;
use App\Models\UserNotification;

/**
 * The one door every notification family sends through: persist the in-app
 * row, broadcast it and queue mail where the event is emailable.
 */
interface NotificationSender
{
    public function notify(
        User $recipient,
        string $type,
        array $data = [],
        ?User $actor = null,
    ): UserNotification;
}
