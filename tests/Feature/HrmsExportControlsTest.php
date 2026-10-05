<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P19.3 — exports are gated, logged and throttled.
 *
 * The HRMS has exactly two bulk exports (no leave or payroll CSV exists —
 * payslips and documents travel as signed single-file downloads, which are
 * per-record, policy-gated and short-lived by design). Each CSV requires
 * its module plus its read permission, writes one `accessed(..., Export)`
 * row per pull, and answers 429 past thirty pulls a minute.
 */
class HrmsExportControlsTest extends TestCase
{
    use IsolatesDatabase;

    public function test_the_attendance_export_is_gated_logged_and_throttled(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->employeeFor($user);
        $this->actAs($user);

        $query = "/api/hrms/attendance/export?from=2026-09-01&to=2026-09-07&employee_id={$employee->id}";

        // Self-service by policy: the caller owns this record, so no
        // attendance permission is needed — the pull succeeds and logs.
        $this->get($query)->assertOk();
        $this->assertTrue(HrmsDataAccessLog::query()
            ->where('actor_user_id', $user->id)
            ->where('action', 'export')
            ->exists());

        for ($i = 0; $i < 29; $i++) {
            $this->get($query)->assertOk();
        }

        // Thirty pulls in a minute pass; the thirty-first does not.
        $this->get($query)->assertStatus(429);
    }

    public function test_the_analytics_export_is_gated_logged_and_throttled(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.analytics.view']));

        // The domain permission is the gate: analytics.view alone 403s the
        // attendance domain, exactly like the tab route.
        $this->getJson('/api/hrms/analytics/export?domain=attendance')->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.analytics.view', 'hrms.attendance.view']));

        $query = '/api/hrms/analytics/export?domain=attendance';

        $this->get($query)->assertOk();
        $this->assertTrue(HrmsDataAccessLog::query()
            ->where('action', 'export')
            ->exists());

        for ($i = 0; $i < 29; $i++) {
            $this->get($query)->assertOk();
        }

        $this->get($query)->assertStatus(429);
    }

    // ------------------------------------------------------------ helpers

    private function employeeFor(User $user): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        return Employee::create([
            'employee_code' => "EMP-EXP-{$sequence}",
            'name' => "Export Employee {$sequence}",
            'status' => EmployeeStatus::Active,
            'user_id' => $user->id,
        ]);
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
            'name' => "Export Control User {$sequence}",
            'email' => "export.control.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Export Control Role {$sequence}",
            'slug' => "export-control-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
