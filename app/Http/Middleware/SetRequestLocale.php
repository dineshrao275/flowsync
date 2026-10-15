<?php

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Support\Locales;
use App\Support\TenantContext;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request locale (i18n Phase 0, alias `locale`).
 *
 * Resolution order — user preference first, configured default last:
 * explicit `?locale=` → authenticated user's `user_settings.locale` →
 * session `locale` (guest's earlier choice, or a query-param remember) →
 * the session tenant's `tenants.locale` default → `Accept-Language` →
 * `config/locales.php` default. Anything unsupported at any step is
 * skipped, never trusted, and the chain always terminates on a supported
 * key (`Locales::default()`).
 *
 * Runs AFTER auth/session/tenant-context middleware (see the priority map
 * in `bootstrap/app.php`): `$request->user()` and the session store only
 * exist once those have run, and stateless API-token routes carry neither
 * a session nor — until `api_token` resolves them — a user, so every
 * request-state read here is guarded. Sets `Content-Language` on the way
 * out so caches, the SPA bootstrap, and tests can see the decision.
 */
class SetRequestLocale
{
    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);
        $request->attributes->set('locale', $locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function resolve(Request $request): string
    {
        $explicit = Locales::normalize($request->query('locale'));

        if ($explicit !== null) {
            if ($request->hasSession()) {
                $request->session()->put(self::SESSION_KEY, $explicit);
            }

            return $explicit;
        }

        try {
            $user = $request->user();
        } catch (\RuntimeException) {
            // Stateless route with neither a session store nor a user
            // resolver (a future `locale` attachment outside the session and
            // token stacks): no identity to resolve a preference from.
            $user = null;
        }

        $userLocale = Locales::normalize($this->userLocale($user));

        if ($userLocale !== null) {
            return $userLocale;
        }

        if ($request->hasSession()) {
            $sessionLocale = Locales::normalize($request->session()->get(self::SESSION_KEY));

            if ($sessionLocale !== null) {
                return $sessionLocale;
            }
        }

        $tenantLocale = Locales::normalize($this->tenant($request)?->locale);

        if ($tenantLocale !== null) {
            return $tenantLocale;
        }

        return Locales::negotiate($request->headers->get('Accept-Language'))
            ?? Locales::default();
    }

    /**
     * The caller's own preference. Platform super admins resolve through
     * `PlatformSetting` (their `user_settings` row would live in a tenant
     * database they have no connection to — the ThemeController precedent),
     * so this only reads the tenant `user_settings` JSON for tenant users.
     */
    private function userLocale(mixed $user): ?string
    {
        if (! is_object($user)) {
            return null;
        }

        if ((bool) ($user->is_super_admin ?? false)) {
            return PlatformSetting::value('locale.user.'.$user->id);
        }

        return $user->settings?->settings['locale'] ?? null;
    }

    /**
     * Whether this route pins a tenant for the request (`switch_tenant` from
     * session keys, `api_token` from the bearer prefix, or `tenant`
     * explicitly) — the only cases where TenantContext speaks for the
     * current request rather than leftover process state.
     */
    private function routeEstablishesTenant(Request $request): bool
    {
        $stack = $request->route()?->gatherMiddleware() ?? [];
        $flat = [];
        array_walk_recursive(
            $stack,
            function (mixed $middleware) use (&$flat): void {
                if (is_string($middleware)) {
                    $flat[] = explode(':', $middleware)[0];
                }
            }
        );

        return array_intersect($flat, ['switch_tenant', 'api_token', 'tenant']) !== [];
    }

    private function tenant(Request $request): ?Tenant
    {
        if (! $this->routeEstablishesTenant($request)) {
            // TenantContext is process state: seeders, provisioning, and prior
            // `using()` blocks can leave a tenant id pinned when the current
            // route never established one (guest routes carry no `tenant`
            // setter). Trusting it here would answer one tenant's default for
            // a tenant-less request.
            return null;
        }

        $tenantId = app(TenantContext::class)->currentId();

        if ($tenantId === null && $request->hasSession()) {
            $impersonate = $request->session()->get('impersonate');
            $tenantId = $impersonate['tenant_id'] ?? $request->session()->get('login.tenant_id');
        }

        return $tenantId ? Tenant::find($tenantId) : null;
    }
}
