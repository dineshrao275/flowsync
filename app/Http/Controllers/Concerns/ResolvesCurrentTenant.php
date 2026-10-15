<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;

/**
 * The central Tenant row for the active tenant context (404 without one).
 */
trait ResolvesCurrentTenant
{
    private function currentTenant(): Tenant
    {
        $tenantId = app(TenantContext::class)->currentId();
        abort_unless($tenantId, 404, $this->missingTenantMessage());

        return Tenant::findOrFail($tenantId);
    }

    protected function missingTenantMessage(): string
    {
        return '';
    }
}
