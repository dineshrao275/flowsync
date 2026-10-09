<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Tenant-wide writes that no catalog permission models yet (company profile,
 * onboarding wizard). Reads `admin` the same way the subscription mutations
 * do; R5 of the roadmap replaces role-name checks with catalog permissions.
 *
 * Call it AFTER the tenant-context 404 so a platform super admin without a
 * tenant keeps answering 404 rather than 403.
 */
trait RequiresTenantAdmin
{
    protected function requireTenantAdmin(Request $request, string $message = 'Only tenant admins can do this.'): void
    {
        abort_unless($request->user()?->hasRole('admin'), 403, $message);
    }
}
