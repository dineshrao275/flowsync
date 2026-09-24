<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Tenant status/lifecycle state machine (Phase 11 foundation).
 *
 * Statuses: pending → provisioning → trial/active → suspended/expired/deactivated
 *           provisioning_failed (retryable back to provisioning)
 * Allowed transitions live in $transitions; every transition is recorded in
 * audit_logs. Subscription-aware transitions (expired, plan changes) arrive in
 * later phases but respect the same state machine.
 */
class TenantLifecycle
{
    /**
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        'pending' => ['provisioning'],
        'provisioning' => [Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE, 'provisioning_failed'],
        'provisioning_failed' => ['provisioning', 'pending', 'deactivated'],
        'trial' => ['active', 'expired', 'suspended', 'deactivated'],
        'active' => ['suspended', 'expired', 'trial', 'deactivated'],
        'suspended' => ['active', 'expired', 'deactivated'],
        'expired' => ['active', 'suspended', 'deactivated'],
        'deactivated' => [],
    ];

    public function canTransition(Tenant $tenant, string $to): bool
    {
        return in_array($to, static::TRANSITIONS[$tenant->status] ?? [], true);
    }

    public function assertTransition(Tenant $tenant, string $to): void
    {
        if (! $this->canTransition($tenant, $to)) {
            throw ValidationException::withMessages([
                'status' => "Tenant cannot transition from '{$tenant->status}' to '{$to}'.",
            ]);
        }
    }

    /**
     * Transition the tenant to $to and record an audit row. No-op when already there.
     */
    public function transition(Tenant $tenant, string $to, ?User $actor = null, array $data = []): Tenant
    {
        if ($tenant->status === $to) {
            return $tenant;
        }

        $this->assertTransition($tenant, $to);

        $audit = [
            'from' => $tenant->status,
            'to' => $to,
        ];

        $tenant->forceFill(['status' => $to]);

        if ($to === Tenant::STATUS_PROVISIONING) {
            $tenant->provisioning_status = Tenant::PROVISIONING_PENDING;
            $tenant->provisioning_error = null;
        }

        if (in_array($to, [Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE], true) && ! $tenant->provisioned_at) {
            $tenant->provisioned_at = now();
        }

        if ($to === Tenant::STATUS_PROVISIONING_FAILED) {
            $tenant->provisioning_status = Tenant::PROVISIONING_FAILED;
            $audit = array_merge($audit, [
                'error' => $tenant->provisioning_error,
            ]);
        }

        $tenant->save();

        AuditLog::create([
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
            'action' => 'tenant.status_changed',
            'data' => $data ? array_merge($audit, $data) : $audit,
            'actor_id' => $actor?->id,
            'ip_address' => request()->ip(),
        ]);

        return $tenant;
    }
}
