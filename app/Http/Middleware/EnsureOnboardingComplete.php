<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantOnboarding;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate domain routes (workspaces/projects/tasks/dashboard/reports/…) until the
 * tenant finishes onboarding. Bypasses non-impersonating super admins (they have
 * no tenant context) and tenants that never started the wizard (admin/seed
 * provisioned). Relies on the middleware priority list so it runs before
 * route-model binding.
 */
class EnsureOnboardingComplete
{
    public function __construct(
        private readonly TenantOnboarding $onboarding,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = app(TenantContext::class)->currentId();

        if (! $tenantId) {
            return $next($request);
        }

        $tenant = Tenant::find($tenantId);

        if ($tenant && ! $this->onboarding->isComplete($tenant)) {
            if (! $request->expectsJson()) {
                return redirect()->to('/app/onboarding');
            }

            return response()->json([
                'message' => 'Complete onboarding before continuing.',
                'redirect' => '/onboarding',
            ], Response::HTTP_FORBIDDEN, [
                'X-Onboarding-Redirect' => '/onboarding',
            ]);
        }

        return $next($request);
    }
}
