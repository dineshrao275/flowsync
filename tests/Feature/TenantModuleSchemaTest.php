<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProductSync;
use App\Services\TenantLifecycle;
use App\Services\TenantLimits;
use App\Support\TenantProvisioner;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Tenant Core / TMS / HRMS schema separation.
 *
 * Provisioning builds the schema per product: Tenant Core (`users`, `roles`,
 * …) always runs, the TMS set only for a TMS tenant, the HRMS set only for
 * an HRMS tenant, and the cross-product bridge only when both are on.
 * Upgrading a tenant (TenantProductSync) adds the missing sets without
 * disturbing existing data, and single-product tenants 403 — never 500 —
 * on the other product's routes.
 */
class TenantModuleSchemaTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_tms_only_tenant_gets_core_and_tms_but_no_hrms_or_bridge(): void
    {
        $tenant = $this->provisionWithPlans('tmsonly', ['tms-starter']);

        $this->assertSchema($tenant, tms: true, hrms: false, bridge: false);
    }

    public function test_an_hrms_only_tenant_gets_core_and_hrms_but_no_tms_or_bridge(): void
    {
        $tenant = $this->provisionWithPlans('hrmsonly', ['hrms-starter']);

        $this->assertSchema($tenant, tms: false, hrms: true, bridge: false);
    }

    public function test_a_suite_tenant_gets_all_four_sets(): void
    {
        $tenant = $this->provisionWithPlans('suiteten', ['starter']);

        $this->assertSchema($tenant, tms: true, hrms: true, bridge: true);
    }

    public function test_upgrading_a_tms_tenant_to_hrms_adds_hrms_and_bridge_without_touching_tms(): void
    {
        $tenant = $this->provisionWithPlans('upgradeten', ['tms-starter']);
        $this->assertSchema($tenant, tms: true, hrms: false, bridge: false);

        $before = $this->countRows($tenant, 'users');

        $this->subscribe($tenant, 'hrms-starter');
        app(TenantProductSync::class)->ensure($tenant);

        $this->assertSchema($tenant, tms: true, hrms: true, bridge: true);

        // The upgrade seeds HRMS defaults and backfills an employment record
        // for every existing login, without touching existing rows.
        $this->assertSame($before, $this->countRows($tenant, 'users'));
        $this->assertGreaterThan(0, $this->countRows($tenant, 'employees'));
        $this->assertGreaterThan(0, $this->countRows($tenant, 'employment_types'));
    }

    public function test_tms_catalogs_are_seeded_only_where_the_tms_schema_exists(): void
    {
        $tms = $this->provisionWithPlans('catatms', ['tms-starter']);
        $hrms = $this->provisionWithPlans('catahrms', ['hrms-starter']);

        $this->assertGreaterThan(0, $this->countRows($tms, 'priorities'));
        $this->assertGreaterThan(0, $this->countRows($tms, 'project_roles'));

        // Core identity/permissions always seed, even without TMS.
        $this->assertGreaterThan(0, $this->countRows($hrms, 'roles'));
        $this->assertGreaterThan(0, $this->countRows($hrms, 'permissions'));

        // HRMS defaults seed where the HRMS schema exists.
        $this->assertGreaterThan(0, $this->countRows($hrms, 'employment_types'));
    }

    public function test_tms_write_routes_403_on_an_hrms_only_tenant(): void
    {
        $tenant = $this->provisionWithPlans('notmsapi', ['hrms-starter']);
        $this->actAsOwner($tenant);

        $this->postJson('/api/workspaces', ['name' => 'Nope', 'slug' => 'nope'])
            ->assertForbidden();

        $this->postJson('/api/issue-types', ['name' => 'Bug', 'color' => '#ff0000'])
            ->assertForbidden();
    }

    public function test_bridge_routes_403_on_single_product_tenants(): void
    {
        $tmsOnly = $this->provisionWithPlans('bridgetms', ['tms-starter']);
        $this->actAsOwner($tmsOnly);

        // The bridge tables (and the HRMS product) are absent: 403, not 500.
        $this->getJson('/api/hrms/tasks/999/links')->assertForbidden();

        $hrmsOnly = $this->provisionWithPlans('bridgehrms', ['hrms-starter']);
        $this->actAsOwner($hrmsOnly);

        // The TMS product gate fires before `Task` binding touches a missing table.
        $this->getJson('/api/hrms/tasks/999/links')->assertForbidden();
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param  list<string>  $planSlugs
     */
    private function provisionWithPlans(string $slug, array $planSlugs): Tenant
    {
        $tenant = Tenant::create(['slug' => $slug, 'name' => ucfirst($slug)]);

        foreach ($planSlugs as $planSlug) {
            $this->subscribe($tenant, $planSlug);
        }

        app(TenantProvisioner::class)->provisionIsolated(
            $tenant->fresh(),
            $this->dbm,
            app(TenantLifecycle::class),
        );

        return $tenant->fresh();
    }

    private function subscribe(Tenant $tenant, string $planSlug): Subscription
    {
        $plan = SubscriptionPlan::where('slug', $planSlug)->firstOrFail();
        TenantLimits::resetMemo();

        return Subscription::updateOrCreate(
            ['tenant_id' => $tenant->id, 'product' => $plan->product ?? SubscriptionPlan::PRODUCT_SUITE],
            [
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ],
        );
    }

    private function assertSchema(Tenant $tenant, bool $tms, bool $hrms, bool $bridge): void
    {
        $this->dbm->using($tenant, function () use ($tms, $hrms, $bridge): void {
            // Tenant Core is unconditional.
            foreach (['users', 'roles', 'permissions', 'role_user', 'permission_role', 'user_settings', 'activities', 'notifications'] as $table) {
                $this->assertTrue(Schema::hasTable($table), "Core table missing: {$table}");
            }

            $this->assertSame($tms, Schema::hasTable('tasks'), 'TMS schema mismatch: tasks');
            $this->assertSame($tms, Schema::hasTable('workspaces'), 'TMS schema mismatch: workspaces');
            $this->assertSame($tms, Schema::hasTable('projects'), 'TMS schema mismatch: projects');
            $this->assertSame($tms, Schema::hasTable('priorities'), 'TMS schema mismatch: priorities');

            $this->assertSame($hrms, Schema::hasTable('employees'), 'HRMS schema mismatch: employees');
            $this->assertSame($hrms, Schema::hasTable('departments'), 'HRMS schema mismatch: departments');

            $this->assertSame($bridge, Schema::hasTable('hrms_task_links'), 'Bridge mismatch: hrms_task_links');
            $this->assertSame($bridge, Schema::hasTable('goal_task_links'), 'Bridge mismatch: goal_task_links');
        });
    }

    private function countRows(Tenant $tenant, string $table): int
    {
        return $this->dbm->using($tenant, fn (): int => Schema::hasTable($table)
            ? (int) \Illuminate\Support\Facades\DB::table($table)->count()
            : -1);
    }

    private function actAsOwner(Tenant $tenant): User
    {
        $this->connectTenant($tenant->slug);

        $user = User::where('email', "owner@{$tenant->slug}.test")->firstOrFail();
        $this->actingAs($user)->withSession(['login.tenant_id' => $tenant->id]);

        return $user;
    }
}
