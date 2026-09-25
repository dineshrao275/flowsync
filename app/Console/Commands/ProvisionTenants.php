<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantLifecycle;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Illuminate\Console\Command;

class ProvisionTenants extends Command
{
    protected $signature = 'tenants:provision {--tenant= : Provision a single tenant by ID}';

    protected $description = 'Provision tenants for isolated mode (create/migrate/seed tenant DB + routing). Idempotent — also repairs partially-provisioned tenants.';

    public function handle(
        TenantProvisioner $provisioner,
        TenantDatabaseManager $dbm,
        TenantLifecycle $lifecycle
    ): int {
        $query = Tenant::query();

        if ($tenantId = $this->option('tenant')) {
            $query->whereKey($tenantId);
        }

        $count = 0;

        foreach ($query->get() as $tenant) {
            $dbm->connectSystem();
            $provisioner->provisionIsolated($tenant, $dbm, $lifecycle);

            $this->info("Provisioned tenant: {$tenant->name} (#{$tenant->id})");
            $count++;
        }

        $this->info("Done. {$count} tenant(s) provisioned.");

        return self::SUCCESS;
    }
}
