<?php

return [
    // Plaintext tokens look like `fst_<central tenant id>_<40 random chars>`; only a SHA-256 is stored.
    'token_prefix' => 'fst',

    // Requests per minute for a token that sets no limit of its own.
    'default_rate_limit' => 60,
    'max_rate_limit' => 600,

    // Longest lifetime an expiring token may be given; null expiry means "never" and is allowed.
    'max_expiry_days' => 365,

    'idempotency_ttl_hours' => 24,
    'log_retention_days' => 30,

    // The permission slugs / capability groups a token may carry: exactly the ones a /api/v1 route is gated on.
    // A token's effective rights are always its abilities AND what its owner still holds.
    'abilities' => [
        'tms.read' => 'Read workspaces, projects, tasks, comments, and work logs',
        'tms.write' => 'Create and update tasks, move statuses, comment, and log time',
        'workspaces.view' => 'Read workspaces, projects and tasks (legacy)',
        'hrms.view' => 'Read the HRMS employee directory',
    ],

    // Capability groups map high-level token abilities to one-of permission requirements.
    // A user can mint an ability if they hold ANY of the listed tenant permissions OR project-role grants.
    'ability_groups' => [
        'tms.read' => [
            'label' => 'Read workspaces, projects and tasks',
            'any_of' => [
                'workspaces.view',
                'tasks.view',
                'tasks.view_own',
                'tasks.view_assigned',
                'tasks.view_all',
            ],
        ],
        'tms.write' => [
            'label' => 'Create and modify tasks, move statuses, post comments, and log work',
            'any_of' => [
                'workspaces.manage',
                'tasks.create',
                'tasks.edit',
                'tasks.move',
            ],
        ],
        'workspaces.view' => [
            'label' => 'Read workspaces, projects and tasks (legacy)',
            'any_of' => [
                'workspaces.view',
            ],
        ],
        'hrms.view' => [
            'label' => 'Read the HRMS employee directory',
            'any_of' => [
                'hrms.view',
            ],
        ],
    ],
];
