<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ErrorLog;
use App\Models\ErrorOccurrence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Applies the error-console retention window (default 90 days).
 *
 * The rollup row is kept while the error is still recurring; once nothing has
 * been seen for the window the rollup and its notes/history go too. Per-event
 * occurrences are pruned independently so a noisy error cannot grow the table
 * without bound. Scheduled nightly.
 */
class PruneErrorLogs extends Command
{
    protected $signature = 'errors:prune {--days= : Override the configured retention window} {--dry-run : Report what would be removed without deleting}';

    protected $description = 'Remove application error occurrences and stale rollups past the retention window';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('error_logging.retention_days', 90)));
        $cutoff = now()->subDays($days);
        $dry = (bool) $this->option('dry-run');

        if (! Schema::connection((new ErrorLog)->getConnectionName())->hasTable('error_logs')) {
            $this->info('Error log tables are not migrated yet.');

            return self::SUCCESS;
        }

        $occurrences = ErrorOccurrence::query()->where('occurred_at', '<', $cutoff)->count();
        $rollups = ErrorLog::query()->where('last_seen_at', '<', $cutoff)->count();

        if ($occurrences === 0 && $rollups === 0) {
            $this->info("Nothing older than {$days} days.");

            return self::SUCCESS;
        }

        $this->table(
            ['Scope', $dry ? 'Would remove' : 'Removed'],
            [
                ['Occurrences before '.$cutoff->toDateString(), $occurrences],
                ['Rollups untouched for '.$days.' days', $rollups],
            ],
        );

        if ($dry) {
            return self::SUCCESS;
        }

        // Rollup deletes cascade to the surviving occurrences, notes and history.
        ErrorLog::query()->where('last_seen_at', '<', $cutoff)->delete();
        ErrorOccurrence::query()->where('occurred_at', '<', $cutoff)->delete();

        return self::SUCCESS;
    }
}
