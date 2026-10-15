<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
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
