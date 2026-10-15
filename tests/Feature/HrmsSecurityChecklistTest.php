<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\OneOnOne;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P19.5 — the checklist, pinned where it was bare.
 *
 * Tenant isolation is the physical database, so cross-tenant reads 404 by
 * construction on every surface — pinned here representatively rather than
 * once per policy. Same for the platform super admin (no tenant context,
 * no rows) and the one policy that shipped with no HTTP test at all
 * (1:1s: participant-or-manage reads and writes, strangers refused).
 */
class HrmsSecurityChecklistTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_non_impersonating_super_admin_is_blocked_on_tenant_hrms_routes(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        // Inside the tenant_context group with no tenant behind the login:
        // blocked (403 per TenantContextMiddlewareTest), never data and
        // never a table-missing 500.
        $this->getJson('/api/hrms/employees')->assertForbidden();
        $this->getJson('/api/hrms/audit')->assertForbidden();
        $this->getJson('/api/hrms/analytics/overview')->assertForbidden();
    }

    public function test_one_on_ones_answer_to_the_room_and_to_managers(): void
    {
        [$report, $manager] = $this->reportingLine();
        $this->actAs($report->user);

        $oneOnOne = $this->postJson('/api/hrms/performance/one-on-ones', [
            'employee_id' => $report->employee->id,
            'manager_employee_id' => $manager->employee->id,
            'scheduled_at' => now()->addWeek()->toDateTimeString(),
            'agenda' => 'Growth.',
        ])->assertCreated()->json('one_on_one');

        $this->getJson("/api/hrms/performance/one-on-ones/{$oneOnOne['id']}")->assertOk();

        $this->putJson("/api/hrms/performance/one-on-ones/{$oneOnOne['id']}", [
            'notes' => 'Agreed on two goals.',
        ])->assertOk()->assertJsonPath('one_on_one.notes', 'Agreed on two goals.');

        // A stranger with the view permission may read the room (the
        // policy's canView) but writes nothing there.
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view']));
        $this->getJson("/api/hrms/performance/one-on-ones/{$oneOnOne['id']}")->assertOk();
        $this->putJson("/api/hrms/performance/one-on-ones/{$oneOnOne['id']}", [
            'notes' => 'Hijacked.',
        ])->assertForbidden();

        // And a login with no performance permission reaches no 1:1 step.
        $this->actAs($this->userWith(['hrms.view']));
        $this->getJson('/api/hrms/performance/one-on-ones')->assertForbidden();
        $this->getJson("/api/hrms/performance/one-on-ones/{$oneOnOne['id']}")->assertForbidden();
    }

    public function test_cross_tenant_records_are_unreachable_by_construction(): void
    {
        $anna = $this->employee('acme', 'EMP-XT-ANNA', 'Cross Anna');
        $this->connectTenant('acme');
        $oneOnOne = OneOnOne::create([
            'employee_id' => $anna->id,
            'scheduled_at' => now()->addWeek(),
            'status' => 'scheduled',
        ]);

        // A Globex login naming Acme rows: the tenant IS the database, so
        // every surface answers 404 — employees, 1:1s, and (by the same
        // boundary) everything else behind the tenant connection.
        $this->actAs($this->globexUser(), 'globex');

        $this->getJson("/api/hrms/employees/{$anna->id}")->assertNotFound();
        $this->getJson("/api/hrms/performance/one-on-ones/{$oneOnOne->id}")->assertNotFound();
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array{report: object, manager: object}
     */
    private function reportingLine(): array
    {
        $managerUser = $this->userWith(['hrms.view', 'hrms.performance.manage']);
        $reportUser = $this->userWith(['hrms.view']);

        $manager = $this->employee('acme', 'EMP-SEC-MGR', 'Security Manager', $managerUser);
        $report = $this->employee('acme', 'EMP-SEC-REP', 'Security Report', $reportUser);
        $report->update(['manager_id' => $manager->id]);

        return [
            (object) ['employee' => $report, 'user' => $reportUser],
            (object) ['employee' => $manager, 'user' => $managerUser],
        ];
    }

    private function employee(string $tenant, string $code, string $name, ?User $user = null): Employee
    {
        $this->connectTenant($tenant);

        return Employee::create([
            'employee_code' => $code, 'name' => $name,
            'status' => EmployeeStatus::Active,
            'user_id' => $user?->id,
        ]);
    }

    private function globexUser(): User
    {
        $this->connectTenant('globex');

        $user = User::create([
            'name' => 'Globex Reader',
            'email' => 'globex.reader@flowsync.test',
            'password' => 'password',
        ]);

        $role = Role::create(['name' => 'Globex Reader', 'slug' => 'globex-reader']);
        $role->permissions()->sync(
            Permission::whereIn('slug', ['hrms.view', 'hrms.employees.view', 'hrms.performance.view'])->pluck('id')->all(),
        );
        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    private function actAs(User $user, string $tenant = 'acme'): void
    {
        $this->connectTenant($tenant);
        $central = Tenant::query()->where('slug', $tenant)->firstOrFail();
        $this->actingAs($user)->withSession(['login.tenant_id' => $central->id]);
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
            'name' => "Security User {$sequence}",
            'email' => "security.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Security Role {$sequence}",
            'slug' => "security-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
