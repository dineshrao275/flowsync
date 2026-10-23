<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\PriceChanges\PlanPriceChanges;
use Illuminate\Console\Command;

/** Moves subscribers to a plan's new price shortly before the renewal they were told about (idempotent). */
class ApplyPriceChanges extends Command
{
    protected $signature = 'billing:apply-price-changes';

    protected $description = 'Apply announced plan price changes that have come due';

    public function handle(PlanPriceChanges $changes): int
    {
        $r = $changes->apply();
        $this->info("applied {$r['applied']}, cancelled {$r['cancelled']}, failed {$r['failed']}");

        return $r['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
