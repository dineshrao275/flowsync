<?php

namespace App\Console\Commands;

use App\Billing\Plans\PlanChangeScheduler;
use Illuminate\Console\Command;

/** Applies queued plan changes whose date has come, and reconciles provider-queued ones whose webhook was missed. */
class ApplyPlanChanges extends Command
{
    protected $signature = 'billing:apply-plan-changes';

    protected $description = 'Apply scheduled plan changes that have come due';

    public function handle(PlanChangeScheduler $scheduler): int
    {
        $r = $scheduler->applyDue();
        $this->info("applied {$r['applied']}, waiting on provider {$r['waiting']}, failed {$r['failed']}");

        return $r['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
