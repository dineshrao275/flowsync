<?php

namespace App\Console\Commands;

use App\Services\Security\AuditRetention;
use Illuminate\Console\Command;

/** Applies each plan's `audit_retention_days` to the platform audit trail (P8.6); scheduled nightly. */
class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune {--dry-run : Report what would be removed without deleting}';

    protected $description = 'Remove platform audit rows older than the tenant plan retention (leaves hash-chain tombstones)';

    public function handle(AuditRetention $retention): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $retention->run($dry);

        if ($result === []) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        $this->table(['Tenant', $dry ? 'Would remove' : 'Removed'], collect($result)->map(fn ($n, $id) => [$id, $n])->values()->all());

        return self::SUCCESS;
    }
}
