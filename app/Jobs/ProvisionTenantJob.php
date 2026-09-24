<?php

namespace App\Jobs;

use App\Models\ProvisioningRun;
use App\Models\Tenant;
use App\Services\TenantLifecycle;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provision a tenant for isolated (one-database-per-tenant) mode.
 *
 * Pipeline (idempotent + resumable; see TenantProvisioner::provisionIsolated):
 * status=provisioning → create database (PG role / sqlite file) → migrate the
 * tenant DB → seed catalogs + owner → insert local tenants row → mirror users
* into the central tenant_users routing index → status=trial|active + provisioned.
 *
 * Failures record a failed ProvisioningRun + provisioning_error and move the
 * tenant to provisioning_failed; repair/retry happens via `tenants:provision`.
 */
class ProvisionTenantJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public Tenant $tenant) {}

    public function handle(
        TenantDatabaseManager $dbm,
        TenantLifecycle $lifecycle
    ): void {
        $this->tenant->refresh();

        $run = ProvisioningRun::create([
            'tenant_id' => $this->tenant->id,
            'status' => ProvisioningRun::STATUS_RUNNING,
            'step' => 'started',
            'started_at' => now(),
        ]);

        try {
            $dbm->connectSystem();
            $provisioner->provisionIsolated($this->tenant, $dbm, $lifecycle);

            $run->update([
                'status' => ProvisioningRun::STATUS_SUCCEEDED,
                'step' => null,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->tenant->refresh();
            $this->tenant->update(['provisioning_error' => $e->getMessage()]);

            $run->update([
                'status' => ProvisioningRun::STATUS_FAILED,
                'step' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            try {
                $lifecycle->transition($this->tenant, Tenant::STATUS_PROVISIONING_FAILED);
            } catch (Throwable $transitionError) {
                // Lifecycle guard rejected the transition (e.g. failure before
                // 'provisioning'); the run failure is still recorded.
            }

            Log::error('Tenant provisioning failed.', [
                'tenant_id' => $this->tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
