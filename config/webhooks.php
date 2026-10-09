<?php

return [
    // Local development / tests may point webhooks at http://localhost; production must not.
    'allow_http' => (bool) env('WEBHOOKS_ALLOW_HTTP', false),
    'allow_private' => (bool) env('WEBHOOKS_ALLOW_PRIVATE', false),

    'timeout_seconds' => 10,

    // Minutes to wait before attempt 2, 3, 4, 5 — then the delivery is marked failed.
    'retry_backoff_minutes' => [1, 5, 30, 120],

    // An endpoint that fails this many deliveries in a row is switched off (and can be re-enabled).
    'disable_after_failures' => 20,

    'retention_days' => 30,
];
