<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantLimits;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module gate (subscription feature control, Phase 14 item 12).
 *
 * 403s when the current tenant's effective plan lacks the given module. A
 * non-impersonating super admin has no tenant context and bypasses; an
 * impersonating super admin has a pinned tenant context and is therefore
 * bound to the target tenant's plan.
 *
 * Registration: alias `ensure_module:<module>` in bootstrap/app.php, listed in
 * the middleware priority BEFORE SubstituteBindings (it runs on routes that may
 * do implicit model binding).
 */
class EnsureModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $tenantId = app(TenantContext::class)->currentId();

        // No tenant context => non-impersonating super admin / provisioning.
        if ($tenantId === null) {
            return $next($request);
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant || app(TenantLimits::class)->hasModule($tenant, $module)) {
            return $next($request);
        }

        abort(403, 'This feature is not included in your current plan.');
    }
}
