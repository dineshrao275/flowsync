<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The shared shape of every append-only audit ledger (P2.2).
 *
 * `PlatformAudit` (central `audit_logs`) and `HrmsAuditLogger` (tenant
 * `hrms_audit_logs`) both implement it, so a caller that only needs "record
 * that this changed" does not care which ledger it lands in. Both mask
 * sensitive values through `AuditMask`, reduce a before/after pair to the
 * keys that changed, and stamp the request's correlation id on the row.
 */
interface AuditWriter
{
    /**
     * Write one change row.
     *
     * @param  string  $action  dotted event name, e.g. `tenant.suspended`
     * @param  string|null  $subjectType  morph class / table the row is about
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context  caller-curated metadata kept even when unchanged
     * @param  int|null  $actorId  explicit actor; null derives it from the current request
     */
    public function recordChange(
        string $action,
        ?string $subjectType,
        int|string|null $subjectId,
        ?array $before,
        ?array $after,
        array $context = [],
        ?int $actorId = null,
        ?string $ipAddress = null,
    ): Model;
}
