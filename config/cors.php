<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing
    |--------------------------------------------------------------------------
    |
    | The SPA is served from the same origin as the API (`/app` + `/api` on one
    | host), so the default posture is same-origin-only with credentials.
    | Cross-origin deployments set FRONTEND_URL to the SPA origin explicitly —
    | never `*` together with `supports_credentials` (browsers reject that
    | combination, and a wildcard with credentials would leak sessions).
    |
    */

    'paths' => ['api/*', 'broadcasting/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter([
        env('APP_URL', 'http://localhost'),
        env('FRONTEND_URL'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'Accept', 'Authorization'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
