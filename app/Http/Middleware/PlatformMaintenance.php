<?php

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use App\Models\SystemUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform maintenance mode (Super Admin → Settings → Maintenance mode).
 *
 * While the `maintenance_mode` platform setting is on, every tenant-facing API call answers 503
 * `{code: "maintenance"}` (+ `Retry-After`), so the SPA swaps itself for the maintenance screen. Still served:
 *  - the SPA shell, the public marketing site and the health/status/webhook/public-form endpoints (nothing tenant-private);
 *  - the platform: super admins sign in (login is refused for any other email) and keep working, including while
 *    impersonating — that is how they verify a fix before switching maintenance off.
 * Tenant data is never touched; switching the setting off reopens everything on the next request.
 */
class PlatformMaintenance
{
    /** API paths reachable by anyone during maintenance. */
    private const OPEN = [
        'api/maintenance', 'api/health', 'api/locales', 'api/language', 'api/csp-report', 'api/contact',
        'api/webhooks/*', 'api/signatures/verify/*', 'api/auth/logout',
    ];

    public static function active(): bool
    {
        // Short cache keeps this off the hot path; the settings controller forgets it on save.
        return (bool) Cache::remember('platform.maintenance', 10, fn () => PlatformSetting::bool('maintenance_mode', false));
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*') || ! self::active() || $request->is(self::OPEN)) {
            return $next($request);
        }

        if ($request->is('api/auth/login')) {
            if ($this->isSuperAdminEmail((string) $request->input('email'))) {
                return $next($request);
            }

            return $this->refuse();
        }

        // A signed-in platform session (no tenant in it) is a super admin: let them through.
        $session = $request->hasSession() ? $request->session() : null;
        if ($session && ! $session->has('login.tenant_id') && $session->has(auth()->guard('web')->getName())) {
            return $next($request);
        }

        return $this->refuse();
    }

    private function isSuperAdminEmail(string $email): bool
    {
        return SystemUser::query()->where('email', Str::lower(trim($email)))->where('is_super_admin', true)->exists();
    }

    private function refuse(): Response
    {
        return response()->json([
            'code' => 'maintenance',
            'message' => __('The platform is undergoing scheduled maintenance. Please try again shortly.'),
        ], 503, ['Retry-After' => '60']);
    }
}
