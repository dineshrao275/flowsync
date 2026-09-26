<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\TenantLimits;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Per-tenant HRMS entitlement: plan modules ⊕ `tenants.features_override`
 * (additive — a super admin can grant a module the plan omits, or switch off
 * one the plan grants; plan 3.3 / D2.6).
 */
class HrmsEntitlementTest extends TestCase
{
    use IsolatesDatabase;

    private TenantLimits $limits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limits = app(TenantLimits::class);
    }

    public function test_tenant_without_a_subscription_is_unlimited(): void
    {
        $tenant = $this->acme();

        $this->assertNull($tenant->subscription);
        $this->assertTrue($this->limits->hasModule($tenant, 'hrms.payroll'));
        $this->assertTrue($this->limits->isHrmsEnabled($tenant));
        $this->assertSame(config('subscriptions.modules'), $this->limits->enabledModules($tenant));
    }

    public function test_plan_modules_gate_when_a_subscription_exists(): void
    {
        $tenant = $this->acme();
        $tenant->update(['limits_override' => ['modules' => ['time_tracking', 'hrms.core']]]);

        $this->assertTrue($this->limits->hasModule($tenant, 'hrms.core'));
        $this->assertFalse($this->limits->hasModule($tenant, 'hrms.payroll'));
        $this->assertTrue($this->limits->isHrmsEnabled($tenant));
    }

    public function test_starter_tenants_see_no_hrms_modules_at_all(): void
    {
        $tenant = $this->acme();
        $tenant->update(['limits_override' => ['modules' => ['time_tracking']]]);

        $this->assertFalse($this->limits->hasModule($tenant, 'hrms.core'));
        $this->assertFalse($this->limits->isHrmsEnabled($tenant));
        $this->assertSame(
            [],
            collect($this->limits->enabledModules($tenant))
                ->filter(fn (string $m) => str_starts_with($m, 'hrms.'))
                ->all(),
        );
    }

    public function test_override_can_grant_a_module_the_plan_omits(): void
    {
        $tenant = $this->acme();
        $tenant->update([
            'limits_override' => ['modules' => ['time_tracking']],
            'features_override' => ['modules' => ['hrms.payroll' => true]],
        ]);

        $this->assertTrue($this->limits->hasModule($tenant, 'hrms.payroll'));
        $this->assertTrue($this->limits->hasModule($tenant, 'time_tracking'));
    }

    public function test_override_can_switch_off_a_module_the_plan_grants(): void
    {
        $tenant = $this->acme();
        $tenant->update([
            'limits_override' => ['modules' => ['hrms.core', 'hrms.payroll']],
            'features_override' => ['modules' => ['hrms.payroll' => false]],
        ]);

        $this->assertTrue($this->limits->hasModule($tenant, 'hrms.core'));
        $this->assertFalse($this->limits->hasModule($tenant, 'hrms.payroll'));
        $this->assertTrue($this->limits->isHrmsEnabled($tenant));
    }

    public function test_override_is_additive_and_cannot_grant_itself_without_a_subscription(): void
    {
        // A tenant with no subscription and no override stays unlimited; the
        // override only *refines* a decision the plan already made.
        $tenant = $this->acme();
        $tenant->update(['features_override' => ['modules' => ['hrms.talent' => true]]]);

        $this->assertTrue($this->limits->hasModule($tenant, 'hrms.talent'));
        $this->assertTrue($this->limits->hasModule($tenant, 'hrms.expenses'));
    }

    public function test_disabling_the_last_hrms_module_reports_hrms_off(): void
    {
        $tenant = $this->acme();
        $tenant->update([
            'limits_override' => ['modules' => ['hrms.core', 'time_tracking']],
            'features_override' => ['modules' => ['hrms.core' => false]],
        ]);

        $this->assertFalse($this->limits->isHrmsEnabled($tenant));
    }

    public function test_enabled_modules_follow_catalog_order_not_plan_order(): void
    {
        $tenant = $this->acme();
        $tenant->update(['limits_override' => ['modules' => ['reports', 'hrms.core', 'time_tracking']]]);

        $enabled = $this->limits->enabledModules($tenant);

        $this->assertSame(['time_tracking', 'reports', 'hrms.core'], $enabled);
    }

    public function test_enabled_modules_ignores_keys_outside_the_catalog(): void
    {
        $tenant = $this->acme();
        $tenant->update([
            'limits_override' => ['modules' => ['hrms.core']],
            'features_override' => ['modules' => ['hrms.not_a_real_module' => true]],
        ]);

        $this->assertSame(['hrms.core'], $this->limits->enabledModules($tenant));
    }

    public function test_a_module_missing_from_both_plan_and_override_is_off(): void
    {
        $tenant = $this->acme();
        $tenant->update(['limits_override' => ['modules' => ['hrms.core']]]);

        $this->assertFalse($this->limits->hasModule($tenant, 'hrms.engagement'));
    }

    public function test_override_false_beats_plan_and_true_beats_absence(): void
    {
        $tenant = $this->acme();
        $tenant->update([
            'limits_override' => ['modules' => ['hrms.core']],
            'features_override' => ['modules' => ['hrms.core' => false, 'hrms.assets' => true]],
        ]);

        $this->assertFalse($this->limits->hasModule($tenant, 'hrms.core'));
        $this->assertTrue($this->limits->hasModule($tenant, 'hrms.assets'));
    }

    public function test_a_subtractive_override_on_an_unlimited_tenant_keeps_the_rest_unlimited(): void
    {
        // The override refines a plan decision; on a subscription-less tenant
        // the "decision" is everything-is-on, so switching one module off must
        // not empty the list.
        $tenant = $this->acme();
        $tenant->update(['features_override' => ['modules' => ['hrms.core' => false]]]);

        $enabled = $this->limits->enabledModules($tenant);

        $this->assertNotContains('hrms.core', $enabled);
        $this->assertContains('hrms.payroll', $enabled);
        $this->assertTrue($this->limits->isHrmsEnabled($tenant));
    }
}
