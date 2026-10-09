<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Services\Hrms\Defaults\HrmsDefaultsProvisioner;
use App\Services\Hrms\Employee\EmployeeBackfill;
use App\Services\TenantLimits;
use App\Support\Hrms\HrmsSchema;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings a tenant database in line with the products the tenant now has (FB-4).
 *
 * Enabling HRMS on a tenant that never had it creates the HRMS tables, seeds the
 * HRMS defaults and gives every existing login an employment record. Removing a
 * product never drops anything — data is kept until the product is switched back on.
 * Idempotent, and safe to call on every subscription change.
 */
class TenantProductSync
{
    public function __construct(
        private readonly TenantDatabaseManager $dbm,
        private readonly TenantLimits $limits,
    ) {}

    public function ensure(Tenant $tenant): void
    {
        if (! $tenant->isProvisioned()) {
            return; // provisioning builds the schema for the plans it was given
        }

        // Entitlement is read fresh: this runs right after a plan or switch changed.
        TenantLimits::resetMemo();
        $tenant = $tenant->fresh();

        if (! $this->limits->productEnabled($tenant, 'hrms')) {
            return;
        }

        try {
            $this->dbm->using($tenant, function () use ($tenant): void {
                HrmsSchema::flush();
                if (HrmsSchema::present()) {
                    return;
                }

                $this->dbm->migrateTenant($tenant, ['tms', 'hrms']);
                HrmsSchema::flush();
                app(HrmsDefaultsProvisioner::class)->provision();
                app(EmployeeBackfill::class)->run();
            });
        } catch (Throwable $e) {
            // Billing must not fail because a schema step did; `tenants:provision` is the repair path.
            Log::error('Could not enable the HRMS schema for a tenant.', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
        }
    }
}
