<?php

namespace Tests\Feature;

use App\Jobs\ProvisionTenantJob;
use App\Models\Hrms\Employee\EmploymentType;
use App\Models\Hrms\Org\Department;
use App\Models\Hrms\Org\Designation;
use App\Models\Hrms\Org\Location;
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

    public function test_the_employment_type_catalog_is_seeded_for_every_tenant(): void
    {
        foreach (['acme', 'globex'] as $slug) {
            $this->assertSame(
                count(config('hrms.employment_types')),
                $this->typeCount($slug),
                "Tenant {$slug} should have the employment-type catalog",
            );
        }
    }

    public function test_a_tenant_provisioned_before_the_catalog_existed_gets_it_on_re_provision(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = Tenant::on($dbm->centralConnectionName())->where('slug', 'acme')->firstOrFail();

        // The state a tenant is actually in when this ships: provisioned long
        // ago, settings row present, catalog never written. The old single
        // "the settings row exists, nothing to do" guard short-circuited the
        // whole method, so `tenants:provision` could never catch this tenant up.
        $dbm->using($tenant, function (): void {
            EmploymentType::query()->delete();
        });

        $this->assertSame(0, $this->typeCount('acme'));

        app(TenantProvisioner::class)->provisionIsolated($tenant, $dbm, app(TenantLifecycle::class));

        $this->assertSame(count(config('hrms.employment_types')), $this->typeCount('acme'));
    }

    public function test_re_provisioning_keeps_a_tenants_own_employment_type_names(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = Tenant::on($dbm->centralConnectionName())->where('slug', 'acme')->firstOrFail();

        $dbm->using($tenant, function (): void {
            EmploymentType::query()->where('code', 'full_time')->update(['name' => 'Salaried']);
        });

        app(TenantProvisioner::class)->provisionIsolated($tenant, $dbm, app(TenantLifecycle::class));

        $dbm->using($tenant, function (): void {
            // Same rule as the settings row and the project roles: seeding a
            // default must never overwrite what the tenant owns.
            $this->assertSame('Salaried', EmploymentType::query()->where('code', 'full_time')->value('name'));
        });
    }

    /**
     * P3.5 — the org starters.
     *
     * A tenant that opens the org page to a blank chart cannot tell "nobody is
     * assigned yet" from "this feature is broken", and the second reading is the
     * one that becomes a support ticket. The starters exist so a new tenant is
     * deleting rows rather than inventing a structure.
     */
    public function test_the_org_catalogs_are_seeded_for_every_tenant(): void
    {
        foreach (['acme', 'globex'] as $slug) {
            $this->assertSame(
                count(config('hrms.departments')),
                $this->orgCount($slug, Department::class),
                "Tenant {$slug} should have the starter departments",
            );
            $this->assertSame(
                count(config('hrms.designations')),
                $this->orgCount($slug, Designation::class),
                "Tenant {$slug} should have the starter designations",
            );
            $this->assertSame(
                count(config('hrms.locations')),
                $this->orgCount($slug, Location::class),
                "Tenant {$slug} should have the starter locations",
            );
        }
    }

    public function test_a_tenant_provisioned_before_the_org_catalogs_existed_gets_them_on_re_provision(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = Tenant::on($dbm->centralConnectionName())->where('slug', 'acme')->firstOrFail();

        // The state every live tenant is actually in when P3.5 ships: settings
        // row and employment types present, org chart untouched because nothing
        // had ever written those three tables. Each catalogue is guarded
        // individually, so re-provisioning catches all three up.
        $dbm->using($tenant, function (): void {
            Department::query()->delete();
            Designation::query()->delete();
            Location::query()->delete();
        });

        $this->assertSame(0, $this->orgCount('acme', Department::class));

        app(TenantProvisioner::class)->provisionIsolated($tenant, $dbm, app(TenantLifecycle::class));

        $this->assertSame(count(config('hrms.departments')), $this->orgCount('acme', Department::class));
        $this->assertSame(count(config('hrms.designations')), $this->orgCount('acme', Designation::class));
        $this->assertSame(count(config('hrms.locations')), $this->orgCount('acme', Location::class));
    }

    public function test_re_provisioning_keeps_a_tenants_own_org_names(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = Tenant::on($dbm->centralConnectionName())->where('slug', 'acme')->firstOrFail();

        $dbm->using($tenant, function (): void {
            Department::query()->where('slug', 'engineering')->update(['name' => 'Platform']);
            Designation::query()->where('slug', 'specialist')->update(['name' => 'Associate']);
        });

        app(TenantProvisioner::class)->provisionIsolated($tenant, $dbm, app(TenantLifecycle::class));

        $dbm->using($tenant, function (): void {
            // Same rule as the settings row, the employment types and the project
            // roles: seeding a default must never overwrite what the tenant owns.
            $this->assertSame('Platform', Department::query()->where('slug', 'engineering')->value('name'));
            $this->assertSame('Associate', Designation::query()->where('slug', 'specialist')->value('name'));
        });
    }

    /**
     * The starters are skeleton rows, and a skeleton row that guesses a real
     * fact is worse than an empty one.
     *
     * A site's country and timezone are printed on a payslip's tax declaration
     * and decide whose statutory rules payroll applies, so they are left null for
     * the tenant to answer. `head_employee_id` is left null for the same reason:
     * a guessed department head is a person who does not work there. And the
     * levels are set, because a band chart that reads "no data" on four seeded
     * bands is a chart that lies about a tenant that has them.
     */
    public function test_the_org_starters_invent_no_facts(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = Tenant::on($dbm->centralConnectionName())->where('slug', 'acme')->firstOrFail();

        $dbm->using($tenant, function (): void {
            $this->assertSame(0, Department::query()->whereNotNull('head_employee_id')->count());
            $this->assertSame(0, Department::query()->whereNotNull('parent_id')->count());
            $this->assertSame(
                0,
                Designation::query()->whereNotNull('department_id')->count(),
                '"Manager" is a level, not a department.',
            );
            $this->assertSame(0, Location::query()->whereNotNull('country')->count());
            $this->assertSame(0, Location::query()->whereNotNull('timezone')->count());
            $this->assertSame(
                0,
                Location::query()->where('is_geo_fenced', true)->count(),
                'A geofence is a fact about a real site, and a starter site has no coordinates.',
            );

            $this->assertSame(
                0,
                Designation::query()->whereNull('level')->count(),
                'A seeded band with a null level cannot be grouped by seniority.',
            );
        });
    }

    private function orgCount(string $slug, string $model): int
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = Tenant::on($dbm->centralConnectionName())->where('slug', $slug)->firstOrFail();

        return $dbm->using($tenant, fn () => $model::query()->count());
    }

    private function typeCount(string $slug): int
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = Tenant::on($dbm->centralConnectionName())->where('slug', $slug)->firstOrFail();

        return $dbm->using($tenant, fn () => EmploymentType::query()->count());
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
