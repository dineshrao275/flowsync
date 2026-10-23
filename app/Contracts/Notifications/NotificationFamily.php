<?php

declare(strict_types=1);

namespace App\Contracts\Notifications;

/**
 * A group of related notification events (task, time off, expense, ...).
 * Each family owns its recipient rules and payload shapes and sends through
 * a NotificationSender, so a family can be tested or swapped on its own.
 */
interface NotificationFamily
{
    /** Stable family key, e.g. `task` or `hrms.expense`. */
    public function family(): string;
}
