<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Support\TenantDatabaseManager;
use Database\Seeders\Realistic\TenantBlueprints;
use Database\Seeders\Realistic\Verifier;
use Database\Seeders\RealisticDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * The realistic demo dataset at a tiny size: the blueprint plan is exact (150 unique tenants, all plans and states),
 * and a seeded slice passes every consistency check, respects entitlements and is safe to rerun.
 */
class RealisticSeedTest extends TestCase
{
    use IsolatesDatabase;

    public function test_the_full_blueprint_plan_is_exact_unique_and_covers_every_plan_and_state(): void
    {
        $all = TenantBlueprints::all();

        $this->assertCount(150, $all);
        $this->assertCount(150, array_unique(array_column($all, 'name')));
        $this->assertCount(150, array_unique(array_column($all, 'slug')));
        $this->assertSame(['both' => 50, 'hrms' => 50, 'tms' => 50], collect($all)->countBy('category')->sortKeys()->all());
        $this->assertSame(['active', 'canceled', 'expired', 'past_due', 'trialing'], collect($all)->pluck('state')->unique()->sort()->values()->all());
        $this->assertSame(['active', 'deactivated', 'expired', 'suspended', 'trial'], collect($all)->pluck('tenant_status')->unique()->sort()->values()->all());

        $plans = collect($all)->pluck('plans')->flatten()->unique()->sort()->values()->all();
        $this->assertSame(['business', 'enterprise', 'hrms-enterprise', 'hrms-professional', 'hrms-starter', 'pro', 'starter', 'tms-enterprise', 'tms-professional', 'tms-starter'], $plans);

        // every plan appears in every lifecycle state within its category
        foreach (['hrms', 'tms'] as $category) {
            $pairs = collect($all)->where('category', $category)->map(fn ($b) => $b['plans'][0].'|'.$b['state'])->unique();
            $this->assertCount(15, $pairs);
        }
        $bundles = collect($all)->where('category', 'both')->filter(fn ($b) => count($b['plans']) === 1)->map(fn ($b) => $b['plans'][0].'|'.$b['state'])->unique();
        $this->assertCount(20, $bundles);

        foreach ($all as $b) {
            $this->assertGreaterThanOrEqual(25, $b['users']);
            $this->assertLessThanOrEqual(200, $b['users']);
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.test$/', $b['domain']);
        }
    }

    public function test_a_seeded_slice_is_consistent_entitled_isolated_and_rerunnable(): void
    {
        config([
            'demo_seed.tenants' => ['hrms' => 2, 'tms' => 2, 'both' => 2],
            'demo_seed.users' => ['min' => 25, 'max' => 27],
            'demo_seed.projects' => ['min' => 2, 'max' => 2],
            'demo_seed.tasks_per_project' => ['min' => 8, 'max' => 9],
            'demo_seed.attendance_days' => 12,
            'demo_seed.payroll_months' => 2,
        ]);

        $seeder = app(RealisticDemoSeeder::class);
        $seeder->run();

        $dbm = app(TenantDatabaseManager::class);
        $dbm->connectSystem();
        $demo = Tenant::withTrashed()->get()->filter(fn ($t) => isset($t->settings['demo_seed']));
        $this->assertCount(6, $demo);

        $verifier = new Verifier;
        foreach ($demo as $tenant) {
            $verifier->tenant($tenant, $dbm);
            $dbm->connectSystem();
        }
        $this->assertSame([], $verifier->failures());

        // isolation: every login email is routed to exactly one tenant
        $this->assertSame(TenantUserRouting::whereIn('tenant_id', $demo->pluck('id'))->count(), TenantUserRouting::whereIn('tenant_id', $demo->pluck('id'))->distinct('email')->count('email'));

        // product separation by schema
        $byCategory = $demo->groupBy(fn ($t) => $t->settings['demo_seed']['category']);
        $dbm->using($byCategory['hrms']->first(), fn () => $this->assertFalse(Schema::hasTable('tasks')));
        $dbm->using($byCategory['tms']->first(), fn () => $this->assertFalse(Schema::hasTable('employees')));
        $dbm->connectSystem();

        // a second run changes nothing
        $ids = $demo->pluck('id')->sort()->values()->all();
        $subscriptions = DB::connection(config('tenancy.system.connection'))->table('subscriptions')->whereIn('tenant_id', $ids)->count();
        $seeder->run();
        $dbm->connectSystem();
        $after = Tenant::withTrashed()->get()->filter(fn ($t) => isset($t->settings['demo_seed']))->pluck('id')->sort()->values()->all();
        $this->assertSame($ids, $after);
        $this->assertSame($subscriptions, DB::connection(config('tenancy.system.connection'))->table('subscriptions')->whereIn('tenant_id', $ids)->count());
    }
}
