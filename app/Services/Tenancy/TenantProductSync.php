<?php

namespace App\Services\Tenancy;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\Hrms\Defaults\HrmsDefaultsProvisioner;
use App\Services\Hrms\Employee\EmployeeBackfill;
use App\Services\TenantLimits;
use App\Support\Hrms\HrmsSchema;
use App\Support\Integration\BridgeSchema;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use App\Support\Tms\TmsSchema;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings a tenant database in line with the products the tenant now has (FB-4).
 *
 * Enabling a product on a tenant that never had it creates that product's tables
 * (plus the bridge set when both are on), seeds its starter catalogs and, for
 * HRMS, gives every existing login an employment record. Removing a product never
 * drops anything — data is kept until the product is switched back on.
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

        $wantsTms = $this->limits->productEnabled($tenant, SubscriptionPlan::PRODUCT_TMS);
        $wantsHrms = $this->limits->productEnabled($tenant, SubscriptionPlan::PRODUCT_HRMS);

        try {
            $this->dbm->using($tenant, function () use ($tenant, $wantsTms, $wantsHrms): void {
                TmsSchema::flush();
                HrmsSchema::flush();
                BridgeSchema::flush();

                $missingTms = $wantsTms && ! TmsSchema::present();
                $missingHrms = $wantsHrms && ! HrmsSchema::present();
                // The bridge only matters when both products are on and it is absent.
                $missingBridge = $wantsTms && $wantsHrms && TmsSchema::present() && HrmsSchema::present() && ! BridgeSchema::present();

                if (! $missingTms && ! $missingHrms && ! $missingBridge) {
                    return;
                }

                $products = [];
                if (TmsSchema::present() || $missingTms) {
                    $products[] = SubscriptionPlan::PRODUCT_TMS;
                }
                if (HrmsSchema::present() || $missingHrms) {
                    $products[] = SubscriptionPlan::PRODUCT_HRMS;
                }

                $this->dbm->migrateTenant($tenant, $products);
                TmsSchema::flush();
                HrmsSchema::flush();
                BridgeSchema::flush();

                if ($missingTms) {
                    app(TenantProvisioner::class)->seedTmsCatalogs();
                }

                if ($missingHrms) {
                    app(HrmsDefaultsProvisioner::class)->provision();
                    app(EmployeeBackfill::class)->run();
                }
            });
        } catch (Throwable $e) {
            // Billing must not fail because a schema step did; `tenants:provision` is the repair path.
            Log::error('Could not enable a product schema for a tenant.', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
        }
    }
}
