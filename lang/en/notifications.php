<?php

/** Email copy for task notifications and digests. One file per supported locale (config/notifications.php `locales`). */
return [
    'task' => [
        'assigned' => ['line' => ':actor assigned :key — :title to you', 'subject' => '[:key] You were assigned :title'],
        'commented' => ['line' => ':actor commented on :key — :title', 'subject' => '[:key] New comment from :actor'],
        'status_changed' => ['line' => ':actor moved :key to :status', 'subject' => '[:key] Moved to :status'],
        'unblocked' => ['line' => ':actor unblocked :key — :title', 'subject' => '[:key] Unblocked'],
        'other' => ['line' => ':actor sent you a task notification', 'subject' => '[:key] Task notification'],
    ],
    'fallback' => ['task' => 'a task', 'key' => 'Task', 'status' => 'a new status', 'actor' => 'Someone'],
    'labels' => ['project' => 'Project', 'task' => 'Task', 'comment' => 'Comment', 'status' => 'Status', 'unblocked_by' => 'Unblocked by', 'open_task' => 'Open task'],
    'digest' => [
        'subject' => 'Your FlowSync digest: :count update|Your FlowSync digest: :count updates',
        'intro' => 'Here is what happened since your last email:',
    ],
];
