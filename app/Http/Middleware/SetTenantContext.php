<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        $user = $request->user();

        if ($user && $user->is_super_admin) {
            $context->setTenantId(null);
            $context->setImpersonating(false);

            return $next($request);
        }

        $session = $request->session();
        $impersonation = $session->get('impersonate');

        // The active tenant comes from the session (the DB was already switched
        // by 'switch_tenant'); the authenticated user row lives in that tenant
        // DB, so a tenant_id column is not authoritative.
        $context->setTenantId($impersonation['tenant_id'] ?? $session->get('login.tenant_id'));
        $context->setImpersonating($impersonation !== null);

        return $next($request);
    }
}
