<?php

namespace App\Console\Commands;

use App\Models\ExportRun;
use App\Models\Tenant;
use App\Support\TenantDatabaseManager;
use Illuminate\Console\Command;

/**
 * Delete the ZIPs of full-tenant exports whose signed-download window has
 * closed. A tenant export holds the whole organisation's data, so leaving the
 * file on disk after the link expired keeps a complete copy around for nobody.
 * The `export_runs` row stays as history; only the file and its path go.
 */
class PruneTenantExports extends Command
{
    protected $signature = 'tenants:prune-exports
        {--tenant= : Central id of a single tenant}
        {--dry-run : List the files that would be deleted without touching them}';

    protected $description = 'Delete expired tenant export ZIPs from disk';

    public function handle(TenantDatabaseManager $dbm): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($query, $id) => $query->where('id', $id))
            ->get()
            ->filter(fn (Tenant $tenant) => $tenant->isServiceable());

        $deleted = 0;

        foreach ($tenants as $tenant) {
            $deleted += $dbm->using($tenant, function () use ($dryRun, $tenant) {
                $count = 0;

                $runs = ExportRun::query()
                    ->whereNotNull('file_path')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now())
                    ->get();

                foreach ($runs as $run) {
                    $full = storage_path('app/private/'.$run->file_path);
                    $this->line(($dryRun ? '[dry run] ' : '').$tenant->slug.': '.$run->file_path);

                    if (! $dryRun) {
                        if (is_file($full)) {
                            @unlink($full);
                        }
                        $run->update(['file_path' => null, 'file_size' => null]);
                    }

                    $count++;
                }

                return $count;
            });
        }

        $this->info(($dryRun ? 'Would delete ' : 'Deleted ')."{$deleted} expired export file(s).");

        return self::SUCCESS;
    }
}
