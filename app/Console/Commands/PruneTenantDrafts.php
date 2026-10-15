<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/** Removes abandoned sign-up drafts (no database was ever created for them). */
class PruneTenantDrafts extends Command
{
    protected $signature = 'tenants:prune-drafts {--days=7 : Drafts untouched for this many days are removed}';

    protected $description = 'Delete tenant drafts that were never completed';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));
        $count = 0;

        Tenant::withTrashed()->where('status', Tenant::STATUS_DRAFT)->where('updated_at', '<', $cutoff)
            ->each(function (Tenant $tenant) use (&$count): void {
                // A draft has no tenant database, so there is nothing to drop beyond the row.
                $tenant->forceDelete();
                $count++;
            });

        $this->info("Pruned {$count} draft tenant(s).");

        return self::SUCCESS;
    }
}
