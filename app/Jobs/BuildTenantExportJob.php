<?php

namespace App\Jobs;

use App\Models\ExportRun;
use App\Models\Tenant;
use App\Services\ExportService;
use App\Support\TenantDatabaseManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 5 — queued full-tenant data export.
 *
 * Dispatched by ExportController::store(). Runs inside the tenant connection
 * so all chunked queries hit the right DB. On completion it stamps the
 * ExportRun as ready + sets expires_at = now +1 hour (the same window as the
 * signed download URL). On failure it stamps status=failed.
 *
 * `tries = 1` — exports are side-effectful (partial ZIPs on disk). If a retry
 * is needed, the user requests a fresh export from the UI.
 */
class BuildTenantExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600; // 10 min hard cap

    public function __construct(
        public readonly int $tenantId,
        public readonly int $exportRunId,
    ) {}

    public function handle(ExportService $service, TenantDatabaseManager $dbManager): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (! $tenant) {
            Log::warning("BuildTenantExportJob: tenant {$this->tenantId} not found, aborting.");

            return;
        }

        $dbManager->using($tenant, function () use ($service) {
            $run = ExportRun::find($this->exportRunId);

            if (! $run) {
                Log::warning("BuildTenantExportJob: ExportRun {$this->exportRunId} not found, aborting.");

                return;
            }

            $run->update(['status' => ExportRun::STATUS_PROCESSING]);

            try {
                $path = $service->build($run);

                $run->update([
                    'status' => ExportRun::STATUS_READY,
                    'file_path' => $path,
                    'file_size' => filesize(storage_path('app/private/'.$path)),
                    'expires_at' => now()->addHour(),
                ]);
            } catch (Throwable $e) {
                $run->update([
                    'status' => ExportRun::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);

                Log::error("BuildTenantExportJob: export {$this->exportRunId} failed.", [
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }
}
