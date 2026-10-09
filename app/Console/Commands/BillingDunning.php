<?php

namespace App\Console\Commands;

use App\Billing\Dunning\DunningService;
use Illuminate\Console\Command;

/**
 * Billing (P6.2) — advance every past-due subscription through the dunning
 * sequence: reminders, a final notice, then tenant suspension after the grace
 * period. Scheduled daily; idempotent (a step runs at most once per cycle).
 */
class BillingDunning extends Command
{
    protected $signature = 'billing:dunning
        {--tenant= : Central id of a single tenant}
        {--dry-run : Report what would happen without writing or notifying}';

    protected $description = 'Run the failed-payment dunning sequence and suspend tenants past their grace period';

    public function handle(DunningService $dunning): int
    {
        $rows = $dunning->run(
            $this->option('tenant') !== null ? (int) $this->option('tenant') : null,
            (bool) $this->option('dry-run'),
        );

        if ($rows === []) {
            $this->info('No dunning steps due. Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(['Tenant', 'Product', $this->option('dry-run') ? 'Would do' : 'Did'], $rows);

        return self::SUCCESS;
    }
}
