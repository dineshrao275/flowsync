<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Structured HTTP request & response logger.
 *
 * Logs method, route path, status code, duration (ms), IP, authenticated user,
 * and tenant context to the configured daily channel. Automatically masks
 * sensitive fields and highlights warnings/errors for rapid debugging.
 */
class LogHttpRequests
{
    /** Sensitive fields scrubbed from logged payloads */
    private const SENSITIVE_FIELDS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'secret',
        'db_password',
        'api_key',
        'key',
        'card_number',
        'cvv',
        'pin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        $this->logRequest($request, $response, $durationMs);

        return $response;
    }

    private function logRequest(Request $request, Response $response, float $durationMs): void
    {
        $path = $request->path();
        $isHealthCheck = $path === 'up' || $path === 'api/health';

        $status = $response->getStatusCode();
        $method = strtoupper($request->method());

        $userId = $request->user()?->id;
        $tenantId = app(TenantContext::class)->currentId()
            ?? ($request->hasSession() ? ($request->session()->get('login.tenant_id') ?? $request->session()->get('impersonate.tenant_id')) : null);

        $context = [
            'method' => $method,
            'path' => '/'.$path,
            'status' => $status,
            'duration_ms' => $durationMs,
            'ip' => $request->ip(),
            'user_id' => $userId,
            'tenant_id' => $tenantId,
        ];

        // Format message
        $message = sprintf('HTTP %s /%s [%d] (%sms)', $method, $path, $status, $durationMs);

        // Routine health checks log at debug level
        if ($isHealthCheck) {
            Log::debug($message, $context);

            return;
        }

        if ($status >= 500) {
            $context['request_input'] = $this->sanitizeInput($request->except(['_token']));
            Log::error($message.' - Server Error', $context);
        } elseif ($status >= 400) {
            $context['request_input'] = $this->sanitizeInput($request->except(['_token']));
            if ($status === 422 && method_exists($response, 'getData')) {
                $data = (array) $response->getData(true);
                if (isset($data['errors'])) {
                    $context['validation_errors'] = $data['errors'];
                }
            }
            Log::warning($message.' - Client Error', $context);
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $context['request_input'] = $this->sanitizeInput($request->except(['_token']));
            Log::info($message, $context);
        } else {
            Log::debug($message, $context);
        }
    }

    /**
     * Mask sensitive values in request arrays before writing to logs.
     */
    private function sanitizeInput(array $input): array
    {
        $sanitized = [];

        foreach ($input as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (in_array($lowerKey, self::SENSITIVE_FIELDS, true)) {
                $sanitized[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeInput($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
