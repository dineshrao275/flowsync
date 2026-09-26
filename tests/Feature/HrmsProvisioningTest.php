<?php

namespace Tests\Feature;

use App\Jobs\ProvisionTenantJob;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\Tenant;
use App\Services\TenantLifecycle;
use App\Services\TenantLimits;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P1.10 — the HRMS settings singleton must exist for every tenant, including
 * ones whose HRMS module is switched off and tenants created before the tables
 * existed.
 */
class HrmsProvisioningTest extends TestCase
{
    use IsolatesDatabase;

    private function hrmsTenants(): array
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenants = Tenant::on($dbm->centralConnectionName())
            ->whereIn('slug', ['acme', 'globex'])
            ->get();

        $counts = [];

        foreach ($tenants as $tenant) {
            $dbm->using($tenant, function () use (&$counts, $tenant): void {
                $counts[$tenant->slug] = HrmsSetting::query()->count();
            });
        }

        return $counts;
    }

    public function test_every_seeded_tenant_has_exactly_one_settings_row(): void
    {
        $counts = $this->hrmsTenants();

        $this->assertArrayHasKey('acme', $counts);
        $this->assertArrayHasKey('globex', $counts);

        foreach ($counts as $slug => $count) {
            $this->assertSame(1, $count, "Tenant {$slug} should have exactly one settings row");
        }
    }

    public function test_the_seeded_row_carries_the_config_defaults(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = Tenant::on($dbm->centralConnectionName())->where('slug', 'acme')->firstOrFail();

        $dbm->using($tenant, function (): void {
            $settings = HrmsSetting::current();

            $this->assertSame(config('hrms.settings_defaults.week_start'), $settings->week_start);
            $this->assertSame(config('hrms.settings_defaults.timezone'), $settings->timezone);
            $this->assertSame(config('hrms.settings_defaults.currency'), $settings->currency);
            $this->assertSame(480, $settings->setting('attendance.full_day_minutes'));
        });
    }

    public function test_re_provisioning_does_not_duplicate_the_row(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = Tenant::on($dbm->centralConnectionName())->where('slug', 'acme')->firstOrFail();
        $provisioner = app(TenantProvisioner::class);
        $lifecycle = app(TenantLifecycle::class);

        $provisioner->provisionIsolated($tenant, $dbm, $lifecycle);
        $provisioner->provisionIsolated($tenant, $dbm, $lifecycle);

        $counts = $this->hrmsTenants();

        $this->assertSame(1, $counts['acme']);
    }

    public function test_re_provisioning_keeps_a_tenants_own_settings(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = Tenant::on($dbm->centralConnectionName())->where('slug', 'acme')->firstOrFail();

        // The tenant configures itself, then the platform runs a repair.
        $dbm->using($tenant, function (): void {
            HrmsSetting::query()->whereKey(1)->update([
                'currency' => 'INR',
                'week_start' => 7,
                'country' => 'IN',
            ]);
        });

        app(TenantProvisioner::class)->provisionIsolated($tenant, $dbm, app(TenantLifecycle::class));

        $dbm->using($tenant, function (): void {
            $settings = HrmsSetting::current();

            // Seeding defaults must never overwrite what the tenant owns.
            $this->assertSame('INR', $settings->currency);
            $this->assertSame(7, $settings->week_start);
            $this->assertSame('IN', $settings->country);
        });
    }

    public function test_a_tenant_created_through_the_provision_job_gets_a_row(): void
    {
        $dbm = app(TenantDatabaseManager::class);

        $tenant = Tenant::on($dbm->centralConnectionName())->create([
            'name' => 'Hrms Fresh',
            'slug' => 'hrms-fresh',
            'status' => Tenant::STATUS_PENDING,
            'provisioning_status' => Tenant::PROVISIONING_PENDING,
        ]);

        ProvisionTenantJob::dispatch($tenant);

        $tenant->refresh();

        $this->assertSame(Tenant::PROVISIONING_PROVISIONED, $tenant->provisioning_status);

        $dbm->using($tenant, function (): void {
            $this->assertSame(1, HrmsSetting::query()->count());
            $this->assertSame('UTC', HrmsSetting::current()->timezone);
        });
    }

    public function test_the_row_exists_even_when_the_hrms_module_is_off(): void
    {
        // Tables and the settings row always exist; `ensure_module` is a
        // behavioural gate, not a schema gate. A tenant with the module
        // switched off must therefore still have its row, or switching it back
        // on later would meet a half-provisioned database.
        $dbm = app(TenantDatabaseManager::class);
        $tenant = Tenant::on($dbm->centralConnectionName())->where('slug', 'globex')->firstOrFail();

        $features = $tenant->features_override ?? [];
        $features['modules']['hrms.attendance'] = false;
        $tenant->update(['features_override' => $features]);

        $this->assertFalse(
            app(TenantLimits::class)->hasModule($tenant->fresh(), 'hrms.attendance'),
        );

        $dbm->using($tenant->fresh(), function (): void {
            $this->assertSame(1, HrmsSetting::query()->count());
            $this->assertSame('UTC', HrmsSetting::current()->timezone);
        });
    }
}
