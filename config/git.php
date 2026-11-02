<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Git provider integration (GitHub App)
|--------------------------------------------------------------------------
|
| Disabled by default: no route, job or command may call a provider while
| `enabled` is false. The GitHub App credentials live only in the operator's
| environment and are never persisted or returned by an API.
|
*/

return [
    'enabled' => (bool) env('GIT_ENABLED', false),

    'providers' => [
        'github' => [
            'app_id' => env('GITHUB_APP_ID'),
            'slug' => env('GITHUB_APP_SLUG'),
            'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
            'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
            'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
            // GitHub Enterprise Server uses https://<host>/api/v3.
            'api_url' => env('GITHUB_APP_API_URL', 'https://api.github.com'),
        ],
    ],

    // Seconds an installation token is cached; the client refreshes 120s before expiry.
    'token_cache_ttl' => 3300,

    'http_timeout' => 15,
    'per_page' => 100,

    // Event-processing caps (mirror the legacy integration's safety limits).
    'max_keys_per_event' => 20,
    'max_commits_per_push' => 20,
    'allow_cross_project' => false,

    // Per-project defaults; the link row carries the effective value.
    'smart_commits' => false,

    // Webhook delivery payload excerpts are pruned after this many days.
    'retention_days' => 30,
];
