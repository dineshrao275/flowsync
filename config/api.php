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

    // The permission slugs a token may carry: exactly the ones a /api/v1 route is gated on.
    // A token's effective rights are always its abilities AND what its owner still holds.
    'abilities' => [
        'workspaces.view' => 'Read workspaces, projects and tasks',
        'hrms.view' => 'Read the HRMS employee directory',
    ],
];
