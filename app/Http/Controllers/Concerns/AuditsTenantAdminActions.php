<?php

namespace App\Http\Controllers\Concerns;

use App\Services\PlatformAudit;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Audit rows for tenant-admin actions that change who can do what (roles,
 * role assignment). They land in the central `audit_logs` ledger — the one
 * place a platform auditor reads — keyed by the central `tenant_id` the audit
 * feed already filters on. `subject_id` is tenant-LOCAL (like every id inside
 * a tenant DB), so the tenant id in the payload is what disambiguates it.
 */
trait AuditsTenantAdminActions
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context
     */
    protected function auditTenantAdmin(
        Request $request,
        string $action,
        string $subjectType,
        ?int $subjectId,
        ?array $before,
        ?array $after,
        array $context = [],
    ): void {
        $tenant = app(TenantContext::class);
        $actor = $request->user();

        app(PlatformAudit::class)->diff($request, $action, $subjectType, $subjectId, $before, $after, $context + [
            'tenant_id' => $tenant->currentId(),
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'impersonating' => $tenant->impersonating(),
            // The super admin behind an impersonated session (central user id).
            'impersonator_id' => $request->session()->get('impersonate.original_user_id'),
        ]);
    }
}
