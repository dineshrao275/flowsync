<?php

namespace App\Services\Security;

use App\Models\Tenant;
use App\Services\PlatformAudit;
use App\Services\TenantLimits;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\DB;

/**
 * Per-plan retention of the platform audit rows about a tenant (P8.6).
 *
 * The plan limit `audit_retention_days` (absent or null = keep forever, so a
 * plan that never heard of it loses nothing) is the age after which a tenant's
 * rows - events carrying its id in `data.tenant_id` or about its tenant record -
 * are removed. Each sealed row leaves a tombstone so the hash chain stays
 * continuous; platform-only rows (no tenant) are never pruned.
 */
class AuditRetention
{
    private const CHUNK = 500;

    public function __construct(
        private readonly AuditChain $chain,
        private readonly TenantLimits $limits,
        private readonly TenantDatabaseManager $dbm,
    ) {}

    /** @return array<int, int> tenant id => rows removed (or that would be, on a dry run) */
    public function run(bool $dryRun = false): array
    {
        $result = [];

        foreach (Tenant::query()->get() as $tenant) {
            $days = $this->limits->effective($tenant)['audit_retention_days'] ?? null;
            if (! is_numeric($days) || (int) $days <= 0) {
                continue;
            }

            $count = $this->pruneTenant($tenant, now()->subDays((int) $days), $dryRun);
            if ($count > 0) {
                $result[$tenant->id] = $count;
                if (! $dryRun) {
                    app(PlatformAudit::class)->record(null, 'audit.retention_pruned', 'tenants', $tenant->id, [
                        'tenant_id' => $tenant->id, 'rows' => $count, 'retention_days' => (int) $days,
                    ]);
                }
            }
        }

        return $result;
    }

    private function pruneTenant(Tenant $tenant, \DateTimeInterface $cutoff, bool $dryRun): int
    {
        $db = DB::connection($this->dbm->centralConnectionName());
        $total = 0;

        $base = fn () => $db->table('audit_logs')
            ->where('created_at', '<', $cutoff)
            ->where(function ($w) use ($tenant): void {
                $w->whereRaw("cast(audit_logs.data->>'tenant_id' as text) = ?", [(string) $tenant->id])
                    ->orWhere(function ($w2) use ($tenant): void {
                        $w2->whereIn('audit_logs.subject_type', ['tenants', Tenant::class])
                            ->where('audit_logs.subject_id', $tenant->id);
                    });
            });

        if ($dryRun) {
            return $base()->count();
        }

        while (($rows = $base()->orderBy('id')->limit(self::CHUNK)->get())->isNotEmpty()) {
            $db->transaction(function () use ($rows, $tenant, $db): void {
                foreach ($rows as $row) {
                    if ($row->hash !== null) {
                        $this->chain->tombstone($row, $tenant->id);
                    } else {
                        $db->table('audit_logs')->where('id', $row->id)->delete(); // legacy, before the chain
                    }
                }
            });
            $total += $rows->count();
        }

        return $total;
    }
}
