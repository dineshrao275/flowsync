<?php

namespace App\Console\Commands;

use App\Billing\PeriodEnd\PeriodEndService;
use Illuminate\Console\Command;

/**
 * Billing (P6.3) — settle subscriptions whose paid period has lapsed: expire
 * the cancelled, roll platform-billed ones forward, send unpaid ones into
 * dunning. Scheduled daily before the dunning sweep; idempotent.
 */
class BillingPeriodEnds extends Command
{
    protected $signature = 'billing:period-ends
        {--tenant= : Central id of a single tenant}
        {--dry-run : Report what would happen without changing anything}';

    protected $description = 'Expire or renew subscriptions whose paid period has ended';

    public function handle(PeriodEndService $periodEnds): int
    {
        $rows = $periodEnds->run(
            $this->option('tenant') !== null ? (int) $this->option('tenant') : null,
            (bool) $this->option('dry-run'),
        );

        if ($rows === []) {
            $this->info('No subscription periods have ended. Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(['Tenant', 'Product', $this->option('dry-run') ? 'Would' : 'Did'], $rows);

        return self::SUCCESS;
    }
}
