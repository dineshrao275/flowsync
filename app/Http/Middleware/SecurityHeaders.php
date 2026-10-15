<?php

namespace App\Http\Middleware;

use App\Support\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response (P8.5): CSP (enforced or
 * report-only per `security.csp.mode`), nosniff, frame, referrer and
 * permissions policy, and HSTS on secure requests only. Headers a route set
 * itself are never overwritten.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('security.headers.enabled', true)) {
            return $response;
        }

        $cfg = config('security.headers');

        $this->setDefault($response, 'X-Content-Type-Options', 'nosniff');
        $this->setDefault($response, 'X-Frame-Options', $cfg['frame_options']);
        $this->setDefault($response, 'Referrer-Policy', $cfg['referrer_policy']);
        $this->setDefault($response, 'Permissions-Policy', $cfg['permissions_policy']);

        if ($request->isSecure() && (int) $cfg['hsts_max_age'] > 0) {
            $this->setDefault($response, 'Strict-Transport-Security', 'max-age='.(int) $cfg['hsts_max_age'].'; includeSubDomains');
        }

        $mode = config('security.csp.mode', 'report_only');
        if (in_array($mode, ['enforce', 'report_only'], true)) {
            $name = $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
            $this->setDefault($response, $name, app(ContentSecurityPolicy::class)->build());
        }

        return $response;
    }

    private function setDefault(Response $response, string $name, ?string $value): void
    {
        if ($value !== null && $value !== '' && ! $response->headers->has($name)) {
            $response->headers->set($name, $value);
        }
    }
}
