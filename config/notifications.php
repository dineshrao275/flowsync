<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-event notification rules
    |--------------------------------------------------------------------------
    |
    | Events listed here can be toggled per user via the `notification_preferences`
    | table (a boolean per event, defaulting to on). The set mirrors the events a
    | tenant user actually receives; HRMS nudges are intentionally absent until a
    | page exists to toggle them. Absence means "cannot be turned off", never
    | "disabled".
    |
    */
    'events' => [
        'task.assigned',
        'task.status_changed',
        'task.commented',
        'task.unblocked',
        'task.work_logged',
    ],

    /*
    | Events that may also send an email (HRMS nudges never email). The tenant policy and each
    | user's preferences can only switch these off, never add to them.
    */
    'email_events' => [
        'task.assigned',
        'task.status_changed',
        'task.commented',
        'task.unblocked',
    ],

    // An identical notification (same recipient, type and payload) inside this window is not written twice.
    'dedupe_window_seconds' => 60,

    // More than `max` emails to one person inside `per_minutes` overflow into their next digest.
    'email_throttle' => ['max' => 20, 'per_minutes' => 60],

    // Languages the email copy exists in: a folder under lang/ per entry. Anything else falls back to `en`.
    'locales' => ['en' => 'English', 'es' => 'Español'],
];
