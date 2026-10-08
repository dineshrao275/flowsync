<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\ScopeGrantBackfill;
use App\Support\TenantDatabaseManager;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase B2 of the member-access plan (step 7): the explicit-grant migration.
 *
 * The migration maps a legacy unsuffixed slug onto the scope variant(s) that
 * slug has always meant — never wider. The payroll exception (`view` = own
 * only) must not start reading every payslip just because the alias period
 * says "all" for everyone else.
 */
class ScopeGrantBackfillTest extends TestCase
{
    use IsolatesDatabase;

    private function acme(): Tenant
    {
        $dbm = app(TenantDatabaseManager::class);

        return Tenant::on($dbm->centralConnectionName())->where('slug', 'acme')->firstOrFail();
    }

    private function makeRole(array $permissions): void
    {
        $role = Role::create(['name' => 'Scope QA', 'slug' => 'scope-qa']);
        $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id'));
    }

    private function roleSlugs(string $slug = 'scope-qa'): array
    {
        return Role::where('slug', $slug)->firstOrFail()
            ->permissions()->pluck('slug')->sort()->values()->all();
    }

    public function test_a_legacy_holder_receives_all_plus_own_and_stays_legacy(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = $this->acme();

        $dbm->using($tenant, fn () => $this->makeRole(['hrms.leave.view']));

        $plan = collect($dbm->using($tenant, fn (): array => app(ScopeGrantBackfill::class)->plan()))
            ->where('role', 'Scope QA')
            ->values()
            ->all();

        $this->assertSame(
            [
                ['role_id' => Role::where('slug', 'scope-qa')->value('id'), 'role' => 'Scope QA', 'slug' => 'hrms.leave.view_all'],
                ['role_id' => Role::where('slug', 'scope-qa')->value('id'), 'role' => 'Scope QA', 'slug' => 'hrms.leave.view_own'],
            ],
            $plan,
        );

        $dbm->using($tenant, fn (): int => app(ScopeGrantBackfill::class)->apply($plan));

        $this->assertSame(
            ['hrms.leave.view', 'hrms.leave.view_all', 'hrms.leave.view_own'],
            $dbm->using($tenant, fn (): array => $this->roleSlugs()),
        );
    }

    public function test_apply_is_idempotent(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = $this->acme();
        $service = app(ScopeGrantBackfill::class);

        $dbm->using($tenant, fn () => $this->makeRole(['hrms.leave.view']));

        $mine = fn (): array => collect($dbm->using($tenant, fn (): array => $service->plan()))
            ->where('role', 'Scope QA')
            ->values()
            ->all();

        $this->assertCount(2, $mine());
        $this->assertSame(2, $dbm->using($tenant, fn (): int => $service->apply($mine())));
        $this->assertSame([], $mine());
        $this->assertSame(0, $dbm->using($tenant, fn (): int => $service->apply([])));
    }

    public function test_the_payroll_legacy_slug_maps_to_own_only(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = $this->acme();

        $dbm->using($tenant, fn () => $this->makeRole(['hrms.payroll.view']));

        $plan = $dbm->using($tenant, fn (): array => app(ScopeGrantBackfill::class)->plan());

        $mySlugs = collect($plan)->where('role', 'Scope QA')->pluck('slug')->all();
        $this->assertContains('hrms.payroll.view_own', $mySlugs);
        $this->assertNotContains('hrms.payroll.view_all', $mySlugs);
        $this->assertNotContains('hrms.payroll.view_assigned', $mySlugs);

        $dbm->using($tenant, fn (): int => app(ScopeGrantBackfill::class)->apply($plan));

        $this->assertSame(
            ['hrms.payroll.view', 'hrms.payroll.view_own'],
            $dbm->using($tenant, fn (): array => $this->roleSlugs()),
        );
    }

    public function test_a_role_that_already_carries_the_variants_is_untouched(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = $this->acme();

        $dbm->using(
            $tenant,
            fn () => $this->makeRole(['hrms.leave.view', 'hrms.leave.view_own', 'hrms.leave.view_all']),
        );

        $mine = collect($dbm->using($tenant, fn (): array => app(ScopeGrantBackfill::class)->plan()))
            ->where('role', 'Scope QA')
            ->values()
            ->all();

        $this->assertSame([], $mine);
    }

    public function test_system_roles_stay_out_of_the_report_except_where_provisioning_cannot_help(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = $this->acme();

        // admin (*), editor/viewer (*_own), manager (*_own + *_assigned) and
        // hr_manager (hrms.* selector) all carry the variants today — their
        // legacy bases are either not held or the variants are, so the report
        // names none of them.
        $plan = $dbm->using($tenant, fn (): array => app(ScopeGrantBackfill::class)->plan());

        $roles = collect($plan)->unique('role_id')->pluck('role')->all();
        $this->assertSame(['Payroll Manager'], $roles);

        $payroll = collect($plan)->where('role', 'Payroll Manager')->pluck('slug')->sort()->values()->all();
        $this->assertSame(
            [
                'hrms.documents.view_all',
                'hrms.documents.view_own',
                'hrms.employees.view_all',
                'hrms.employees.view_own',
            ],
            $payroll,
        );
    }

    public function test_the_command_applies_only_with_force(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = $this->acme();

        $dbm->using($tenant, fn () => $this->makeRole(['hrms.leave.view']));

        $this->artisan('tenants:scope-grants', ['--tenant' => $tenant->id])
            ->assertSuccessful();

        $this->assertSame(
            ['hrms.leave.view'],
            $dbm->using($tenant, fn (): array => $this->roleSlugs()),
        );

        $this->artisan('tenants:scope-grants', ['--tenant' => $tenant->id, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(
            ['hrms.leave.view', 'hrms.leave.view_all', 'hrms.leave.view_own'],
            $dbm->using($tenant, fn (): array => $this->roleSlugs()),
        );
    }

    public function test_the_report_names_every_grant_it_would_make(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = $this->acme();

        $dbm->using(
            $tenant,
            fn () => $this->makeRole(['hrms.expenses.view', 'hrms.engagement.view']),
        );

        $plan = collect($dbm->using($tenant, fn (): array => app(ScopeGrantBackfill::class)->plan()))
            ->where('role', 'Scope QA')
            ->values()
            ->all();

        $this->assertCount(4, $plan);

        $slugs = array_column($plan, 'slug');
        $this->assertContains('hrms.expenses.view_all', $slugs);
        $this->assertContains('hrms.expenses.view_own', $slugs);
        $this->assertContains('hrms.engagement.view_all', $slugs);
        $this->assertContains('hrms.engagement.view_own', $slugs);
    }
}
