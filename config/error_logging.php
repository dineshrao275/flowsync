<?php

declare(strict_types=1);

return [
    /*
     | Central application error log (Super Admin → Errors).
     |
     | Capture is intentionally opt-out at the process level: the listener reads
     | this flag on every log event, so a test (or a host that wants file logs
     | only) can turn it off without changing the logging stack.
     */
    'enabled' => (bool) env('ERROR_LOG_ENABLED', true),

    /*
     | Log levels that become rows. `warning` is included because a swallowed
     | warning is often the only trace of a degraded path; info/debug are noisy
     | and stay file-only.
     */
    'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],

    /*
     | Exception classes we never store. They are either expected control flow
     | (validation, auth, 404) or already answerable from the response.
     */
    'ignore_exceptions' => [
        Illuminate\Validation\ValidationException::class,
        Illuminate\Auth\AuthenticationException::class,
        Illuminate\Auth\Access\AuthorizationException::class,
        Illuminate\Database\Eloquent\ModelNotFoundException::class,
        Illuminate\Session\TokenMismatchException::class,
        Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
    ],

    /*
     | Requests that are probed constantly and have no diagnostic value.
     */
    'ignore_paths' => ['up', 'api/health', 'api/metrics', 'api/maintenance'],

    /*
     | Input is never stored for these prefixes (they carry other people's
     | content or one-time credentials). Mirrors LogHttpRequests.
     */
    'never_store_input' => ['api/integrations/*', 'api/webhooks/*', 'api/ai/*'],

    'max_message_chars' => 4000,
    'max_stack_chars' => 20000,
    'max_context_chars' => 16000,

    /*
     | Occurrences, notes and status history older than this are pruned nightly.
     | An error log row is removed once it has not been seen in the window.
     */
    'retention_days' => (int) env('ERROR_LOG_RETENTION_DAYS', 90),
];
