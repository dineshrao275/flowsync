<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\ImpersonationGuard;
use App\Support\TenantDatabaseManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request database routing (Phase 13). Runs BEFORE 'auth' so the guard
 * resolves the user on the already-switched connection.
 *
 * - tenant in session (login.tenant_id, or impersonate.tenant_id) → connect the
 *   tenant's database
 * - otherwise (super admin, logged-out, unknown tenant) → connect central/system
 */
class SwitchTenant
{
    public function __construct(
        private readonly TenantDatabaseManager $dbm,
        private readonly ImpersonationGuard $impersonationGuard,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $impersonation = $session->get('impersonate');

        if (is_array($impersonation)) {
            $this->impersonationGuard->enforce($request, $impersonation);
        }

        $tenantId = $impersonation['tenant_id'] ?? $session->get('login.tenant_id');
        $tenant = $tenantId ? Tenant::find($tenantId) : null;

        if ($tenant && $tenant->isServiceable()) {
            $this->dbm->connect($tenant);
        } else {
            $this->dbm->connectSystem();
        }

        return $next($request);
    }
}
