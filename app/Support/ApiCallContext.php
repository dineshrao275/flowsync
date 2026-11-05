<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether the current call arrived through a personal API token (the MCP's door). Read from the request that
 * `AuthenticateApiToken` marked, so it is false in queued jobs, commands and every session request.
 *
 * HR data is never written from inside such a call: a token may change TMS data, but the HRMS side effects
 * that would normally ride along (refreshing performance-goal evidence) are skipped.
 */
final class ApiCallContext
{
    public static function viaToken(): bool
    {
        return (bool) request()->attributes->get('api_token');
    }
}
