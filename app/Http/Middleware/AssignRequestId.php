<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for every request (P2.2).
 *
 * Reuses a sane inbound `X-Request-Id` (a proxy or the SPA may already have
 * one) and otherwise mints a UUID. It is bound as shared log context — every
 * line this request writes carries `request_id` — and echoed on the response so
 * a user can quote it and support can find the exact trail. A hostile or
 * oversized inbound value is replaced, never trusted into the logs.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->sanitize($request->headers->get(self::HEADER)) ?? (string) Str::uuid();

        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    private function sanitize(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._-]{8,64}$/', $value) === 1 ? $value : null;
    }
}
