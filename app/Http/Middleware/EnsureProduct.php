<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantLimits;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Whole-product gate (`ensure_product:tms|hrms`): 403 when the tenant has no
 * subscription covering the product, or a super admin switched it off. Mirrors
 * EnsureModule — a platform super admin without a tenant context passes, an
 * impersonating one is bound to the target tenant.
 */
class EnsureProduct
{
    public function handle(Request $request, Closure $next, string $product): Response
    {
        $tenantId = app(TenantContext::class)->currentId();

        if ($tenantId === null) {
            if ($request->user()?->is_super_admin) {
                return $next($request);
            }
            abort(403, 'A valid tenant context is required.');
        }

        $tenant = Tenant::find($tenantId);
        abort_unless($tenant, 403, 'A valid tenant context is required.');

        if (app(TenantLimits::class)->productEnabled($tenant, $product)) {
            return $next($request);
        }

        abort(403, 'This product is not part of your subscription.', ['X-Product-Denied' => $product]);
    }
}
