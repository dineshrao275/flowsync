<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * The P1.5 system migration merges the `hrms.*` module keys into the plan
 * catalog. Its dangerous edge is the *merge*: replacing `limits` wholesale
 * would drop every numeric limit and silently mark all tenants unlimited, and
 * re-planning tenants would change what they are billed. Both are asserted here.
 */
class HrmsPlanEntitlementMigrationTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/system/2026_09_27_000014_add_hrms_to_plan_modules.php');
    }

    private function central(): void
    {
        DB::setDefaultConnection('iso_system');
    }

    public function test_it_seeds_the_business_plan_with_its_module_set(): void
    {
        $this->central();

        $this->migration()->up();

        $business = SubscriptionPlan::where('slug', 'business')->first();

        $this->assertNotNull($business);
        $this->assertSame('Business', $business->name);
        $this->assertTrue($business->is_active);
        $this->assertFalse($business->is_default);
        $this->assertContains('hrms.core', $business->limits['modules']);
        $this->assertContains('hrms.analytics', $business->limits['modules']);
    }

    public function test_pro_gains_the_plan_b_hrms_set(): void
    {
        $this->central();

        // Simulate a pre-HRMS pro row: no hrms keys at all.
        $pro = SubscriptionPlan::where('slug', 'pro')->first();
        $limits = $pro->limits;
        $limits['modules'] = array_values(array_filter(
            $limits['modules'],
            fn (string $m) => ! str_starts_with($m, 'hrms.'),
        ));
        $pro->update(['limits' => $limits]);

        $this->migration()->up();

        $modules = SubscriptionPlan::where('slug', 'pro')->first()->limits['modules'];

        $this->assertContains('hrms.core', $modules);
        $this->assertContains('hrms.attendance', $modules);
        $this->assertNotContains('hrms.payroll', $modules);
    }

    public function test_starter_gains_no_hrms_modules(): void
    {
        $this->central();

        $this->migration()->up();

        $modules = SubscriptionPlan::where('slug', 'starter')->first()->limits['modules'];

        $this->assertSame(
            [],
            array_values(array_filter($modules, fn (string $m) => str_starts_with($m, 'hrms.'))),
        );
    }

    public function test_numeric_limits_survive_the_merge(): void
    {
        $this->central();

        $business = SubscriptionPlan::where('slug', 'business')->first();
        $before = $business->limits;

        $this->migration()->up();

        $after = SubscriptionPlan::where('slug', 'business')->first()->limits;

        // Every numeric key (not just modules) must be identical afterwards.
        $numericBefore = array_filter($before, fn ($v) => ! is_array($v), ARRAY_FILTER_USE_KEY);
        $numericAfter = array_filter($after, fn ($v) => ! is_array($v), ARRAY_FILTER_USE_KEY);

        $this->assertSame($numericBefore, $numericAfter);
        $this->assertSame(200, $after['users']);
        $this->assertSame(200, $after['employees']);
    }

    public function test_merging_preserves_unknown_keys_but_config_wins_on_known_limits(): void
    {
        $this->central();

        // An operator hand-added a module and a key config knows nothing about,
        // and also edited a numeric limit. Modules merge; config stays
        // authoritative for numeric limits (same contract as
        // SubscriptionPlanSeeder, which updateOrCreate's the whole row).
        $pro = SubscriptionPlan::where('slug', 'pro')->first();
        $limits = $pro->limits;
        $limits['modules'] = [...array_values(array_filter(
            $limits['modules'],
            fn (string $m) => ! str_starts_with($m, 'hrms.'),
        )), 'custom_module'];
        $limits['users'] = 999;
        $limits['bespoke_knob'] = 'keep-me';
        $pro->update(['limits' => $limits]);

        $this->migration()->up();

        $after = SubscriptionPlan::where('slug', 'pro')->first()->limits;

        $this->assertContains('custom_module', $after['modules']);
        $this->assertContains('hrms.core', $after['modules']);
        $this->assertSame('keep-me', $after['bespoke_knob']);
        $this->assertSame(config('subscriptions.plans.pro.limits.users'), $after['users']);
    }

    public function test_it_never_re_plans_a_tenant(): void
    {
        $this->central();

        $pro = SubscriptionPlan::where('slug', 'pro')->first();
        $before = DB::table('subscriptions')
            ->where('plan_id', $pro->id)
            ->pluck('id')
            ->all();

        $this->migration()->up();

        $business = SubscriptionPlan::where('slug', 'business')->first();
        $moved = DB::table('subscriptions')
            ->where('plan_id', $business->id)
            ->pluck('id')
            ->all();

        // A migration must never change what a customer is billed.
        $this->assertSame([], array_values(array_intersect($before, $moved)));
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->central();

        $this->migration()->up();
        $snapshot = SubscriptionPlan::orderBy('slug')
            ->pluck('limits', 'slug')
            ->all();

        $this->migration()->up();

        $this->assertSame(
            $snapshot,
            SubscriptionPlan::orderBy('slug')->pluck('limits', 'slug')->all(),
        );
    }
}
