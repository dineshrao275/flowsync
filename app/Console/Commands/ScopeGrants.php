<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ScopeGrantBackfill;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;

class ScopeGrants extends Command
{
    protected $signature = 'tenants:scope-grants
        {--tenant= : A single tenant by ID}
        {--force : Apply the grants (default: report only)}';

    protected $description = 'Make legacy scope grants explicit per tenant role: for every role holding X.view, add X.view_all + X.view_own (hrms.payroll.view maps to view_own only — never widened). Report-only without --force.';

    public function handle(
        ScopeGrantBackfill $backfill,
        TenantDatabaseManager $dbm
    ): int {
        $query = Tenant::query();

        if ($tenantId = $this->option('tenant')) {
            $query->whereKey($tenantId);
        }

        $force = (bool) $this->option('force');
        $changedTenants = 0;
        $totalGrants = 0;

        foreach ($query->get() as $tenant) {
            $dbm->connectSystem();

            $plan = $dbm->using($tenant, fn (): array => $backfill->plan());

            if ($plan === []) {
                continue;
            }

            $changedTenants++;
            $totalGrants += count($plan);

            $this->newLine();
            $this->info("{$tenant->name} (#{$tenant->id})");
            $this->table(
                ['Role', 'Explicit grant to add'],
                array_map(
                    static fn (array $g): array => [$g['role'], $g['slug']],
                    $plan,
                ),
            );

            if ($force) {
                $applied = $dbm->using($tenant, fn (): int => $backfill->apply($plan));
                $this->info("  -> {$applied} explicit grant(s) applied.");
            }
        }

        $this->newLine();

        if ($changedTenants === 0) {
            $this->info('No legacy scope grants to make explicit — all tenants already carry their variants.');
        } elseif (! $force) {
            $this->warn(
                "Report only: {$totalGrants} explicit grant(s) across {$changedTenants} tenant(s) would be added. "
                .'Re-run with --force to apply.'
            );
        } else {
            $this->info("Applied {$totalGrants} explicit grant(s) across {$changedTenants} tenant(s).");
        }

        return self::SUCCESS;
    }
}
