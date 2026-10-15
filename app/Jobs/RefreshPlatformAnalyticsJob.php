<?php

namespace App\Jobs;

use App\Services\PlatformResourceTotals;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recompute the SA analytics resource fan-out off the request path.
 *
 * No tenant payload stamp — this job only reads the central tenant list and
 * then `using()` each tenant itself. tries=1: a failed refresh leaves the
 * stale cache in place until the next request schedules another attempt.
 */
class RefreshPlatformAnalyticsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function handle(PlatformResourceTotals $totals): void
    {
        $totals->computeAndStore();
    }
}
