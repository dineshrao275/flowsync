<?php

namespace App\Http\Controllers\Concerns;

use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * A non-impersonating super admin lives in the central system database, which
 * holds none of the tenant-scoped tables. Requests from that account (tenant
 * notifications, per-user theme settings, …) must not query them.
 */
trait DetectsPlatformUsers
{
    protected function isPlatformSuperAdmin(Request $request): bool
    {
        return (bool) $request->user()->is_super_admin
            && ! app(TenantContext::class)->hasTenant();
    }
}
