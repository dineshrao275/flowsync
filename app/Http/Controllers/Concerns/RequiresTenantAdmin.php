<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Tenant-wide writes (company profile, onboarding wizard). The `admin` role
 * always passes — its stored permission snapshot can lag a catalog addition
 * until the next `tenants:provision`, and a lagging admin must not be locked
 * out — and so does any custom role holding the named catalog permission
 * (R5: a role can be delegated these without being made an admin).
 *
 * Call it AFTER the tenant-context 404 so a platform super admin without a
 * tenant keeps answering 404 rather than 403.
 */
trait RequiresTenantAdmin
{
    protected function requireTenantAdmin(Request $request, string $message = 'Only tenant admins can do this.', string $permission = 'tenant.manage'): void
    {
        $user = $request->user();

        abort_unless($user?->hasRole('admin') || $user?->hasPermission($permission), 403, $message);
    }
}
