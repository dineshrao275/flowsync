<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P18.3 — one gate per dashboard tab.
 *
 * Each domain route answers to its own permission (never a blanket
 * `workspaces.view`); unknown filter ids 422 against the tenant's own
 * tables; payroll logs every read with its actor; and the tiers inside
 * shared endpoints follow the caller's permissions, not the URL.
 */
class HrmsAnalyticsApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_each_tab_has_its_own_gate(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.analytics.view']));

        $this->getJson('/api/hrms/analytics/overview')->assertOk();
        $this->getJson('/api/hrms/analytics/attendance')->assertForbidden();
        $this->getJson('/api/hrms/analytics/payroll')->assertForbidden();
        $this->getJson('/api/hrms/analytics/lifecycle')->assertOk();
        $this->getJson('/api/hrms/analytics/performance')->assertOk();
        $this->getJson('/api/hrms/analytics/documents')->assertForbidden();
        $this->getJson('/api/hrms/analytics/assets')->assertForbidden();
    }

    public function test_headcount_breakdowns_never_order_a_grouped_query_by_a_bare_column(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.analytics.view']));

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->getJson('/api/hrms/analytics/overview')->assertOk();

        // PostgreSQL answers 42803 for `group by x order by employees.id`; sqlite tolerates it,
        // so the shape is pinned here instead of waiting for a Postgres tenant to 500.
        $offenders = array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, ' group by ') && str_contains($sql, ' order by '),
        ));

        $this->assertSame([], $offenders);
    }

    public function test_lifecycle_time_to_first_day_survives_fractional_day_differences(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.analytics.view']));

        // Carbon 3's diffInDays() is a signed float; the reader once typed it `int` and 500d the moment an
        // employee had a joining date (fractional day vs created_at). Backdated joiners are the common case.
        Employee::create([
            'employee_code' => 'EMP-ANA-JOIN', 'name' => 'Analytics Joiner',
            'status' => EmployeeStatus::Active, 'joining_date' => now()->subDays(40)->toDateString(),
        ]);

        $this->getJson('/api/hrms/analytics/lifecycle')
            ->assertOk()
            ->assertJsonPath('time_to_first_day_avg', fn ($days) => is_numeric($days) && $days >= 0);
    }

    public function test_cached_analytics_never_cross_tenants(): void
    {
        // Production keeps the cache in the shared system database (DB_CACHE_CONNECTION=system), so a key
        // without the tenant in it serves tenant A's dashboard to tenant B for the length of the TTL.
        $acme = $this->userWith(['hrms.view', 'hrms.analytics.view']);
        foreach (['EMP-LEAK-1', 'EMP-LEAK-2'] as $code) {
            Employee::create(['employee_code' => $code, 'name' => $code, 'status' => EmployeeStatus::Active]);
        }
        $acmeTotal = Employee::count();
        $this->actAs($acme);
        $this->getJson('/api/hrms/analytics/overview')->assertOk()->assertJsonPath('headcount.total', $acmeTotal);

        $this->connectTenant('globex');
        $globex = User::create(['name' => 'Globex Analyst', 'email' => 'analyst@globex.test', 'password' => 'password']);
        $role = Role::create(['name' => 'Globex Analytics', 'slug' => 'globex-analytics']);
        $role->permissions()->sync(Permission::whereIn('slug', ['hrms.view', 'hrms.analytics.view'])->pluck('id')->all());
        $globex->roles()->sync([$role->id]);
        $globexTotal = Employee::count();
        $this->assertNotSame($acmeTotal, $globexTotal);

        $this->actingAs($globex->fresh(['roles.permissions']))->withSession(['login.tenant_id' => $this->globex()->id]);
        $this->getJson('/api/hrms/analytics/overview')->assertOk()->assertJsonPath('headcount.total', $globexTotal);
    }

    public function test_unknown_filters_422_against_tenant_tables(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.analytics.view', 'hrms.attendance.view']));

        $this->getJson('/api/hrms/analytics/attendance?department_id=999999')->assertStatus(422);
        $this->getJson('/api/hrms/analytics/overview?location_id=999999')->assertStatus(422);
    }

    public function test_payroll_reads_log_their_actor(): void
    {
        $reader = $this->userWith(['hrms.view', 'hrms.payroll.run']);
        $this->actAs($reader);

        $this->getJson('/api/hrms/analytics/payroll')->assertOk()->assertJsonPath('total_gross', '0.00');

        $this->assertTrue(HrmsDataAccessLog::query()
            ->where('model', (new Payslip)->getMorphClass())
            ->where('actor_user_id', $reader->id)
            ->exists());
    }

    public function test_liability_and_ratings_follow_permissions(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.analytics.view', 'hrms.leave.manage']));

        $leave = $this->getJson('/api/hrms/analytics/leave')->assertOk()->json();
        $this->assertNull($leave['expiry_liability']);

        $performance = $this->getJson('/api/hrms/analytics/performance')->assertOk()->json();
        $this->assertNull($performance['ratings']);
    }

    public function test_a_manager_scope_reads_reports_only(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->actAs($this->userWith(['hrms.view', 'hrms.analytics.view']));

        $scoped = $this->getJson("/api/hrms/analytics/overview?manager_id={$manager->id}")->assertOk()->json();

        $this->assertSame(1, $scoped['headcount']['total']);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array{Employee, Employee} Manager and report.
     */
    private function reportingLine(): array
    {
        $this->connectTenant('acme');

        $manager = Employee::create([
            'employee_code' => 'EMP-ANA-MGR', 'name' => 'Analytics Manager',
            'status' => EmployeeStatus::Active,
        ]);
        $report = Employee::create([
            'employee_code' => 'EMP-ANA-REP', 'name' => 'Analytics Report',
            'status' => EmployeeStatus::Active, 'manager_id' => $manager->id,
        ]);

        return [$manager, $report];
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $user = User::create([
            'name' => "Analytics User {$sequence}",
            'email' => "analytics.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Analytics Role {$sequence}",
            'slug' => "analytics-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
