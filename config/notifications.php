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
];
