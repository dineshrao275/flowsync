<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An expired tenant (trial ended / subscription lapsed with no paid plan) can still sign in — so the admin can pick a plan
 * and pay — and keeps all its data, but every product service answers 402 `subscription_required` until it subscribes.
 * Only the endpoints in `config('billing.restricted_allow')` (sign-out, profile, billing, plans, data export, support…) work.
 *
 * Reactivation is automatic: paying moves the tenant expired → active (SubscriptionService::renew/assign) and this gate
 * opens on the next request. Suspended/deactivated tenants are a different matter (they cannot sign in at all).
 */
class EnsureSubscribed
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = app(TenantContext::class)->currentId();
        if (! $tenantId) {
            return $next($request); // platform super admin
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant || $tenant->status !== Tenant::STATUS_EXPIRED) {
            return $next($request);
        }

        $path = ltrim(preg_replace('#^api/#', '', $request->path()), '/');
        foreach ((array) config('billing.restricted_allow', []) as $allowed) {
            $prefix = str_ends_with($allowed, '*');
            if ($prefix ? str_starts_with($path, rtrim($allowed, '*')) : $path === $allowed) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => __('Your trial or subscription has ended. Choose a plan to continue — your data is safe and untouched.'),
            'code' => 'subscription_required',
            'redirect' => '/subscription',
        ], 402, ['X-Subscription-Required' => 'true']);
    }
}
