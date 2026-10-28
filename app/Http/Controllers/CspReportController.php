<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives Content-Security-Policy violation reports (the `report-uri` target).
 *
 * Browsers POST these with no cookies or CSRF token, so the route is public and
 * throttled; the payload is untrusted, so only a short allow-list of fields is
 * logged, each truncated. The `security` log lines are what an operator reviews
 * during the report-only window before flipping SECURITY_CSP_MODE=enforce.
 */
class CspReportController extends Controller
{
    private const FIELDS = [
        'violated-directive', 'effective-directive', 'blocked-uri', 'document-uri',
        'source-file', 'line-number', 'disposition',
    ];

    public function store(Request $request): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        $report = is_array($body) ? ($body['csp-report'] ?? $body) : [];

        if (is_array($report) && $report !== []) {
            $fields = [];
            foreach (self::FIELDS as $key) {
                if (isset($report[$key]) && is_scalar($report[$key])) {
                    $fields[str_replace('-', '_', $key)] = mb_substr((string) $report[$key], 0, 300);
                }
            }

            if ($fields !== []) {
                Log::warning('csp.violation', $fields + ['user_agent' => mb_substr((string) $request->userAgent(), 0, 200)]);
            }
        }

        return response()->noContent();
    }
}
