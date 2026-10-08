<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P19.1 — the append-only trail, readable.
 *
 * Both endpoints answer to `hrms.audit.view` alone (the ledger is
 * cross-cutting, so no per-record policy can gate it); the index filters
 * by actor, subject, action and free text with the standard pagination
 * shape; the trail returns one record's before/after history newest-first;
 * and sensitive values arrive masked, exactly as the writer stored them.
 */
class HrmsAuditApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_both_endpoints_answer_to_the_audit_permission(): void
    {
        $employee = $this->employee();
        $this->actAs($this->userWith(['hrms.view']));

        $this->getJson('/api/hrms/audit')->assertForbidden();
        $this->getJson("/api/hrms/audit/{$this->trailType($employee)}/{$employee->id}")->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.audit.view']));

        $this->getJson('/api/hrms/audit')->assertOk()->assertJsonStructure([
            'audit_logs', 'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $this->getJson("/api/hrms/audit/{$this->trailType($employee)}/{$employee->id}")->assertOk();
    }

    public function test_the_index_filters_by_actor_subject_action_and_text(): void
    {
        $auditor = $this->userWith(['hrms.view', 'hrms.audit.view']);
        $other = $this->userWith(['hrms.view']);
        $anna = $this->employee('EMP-AUD-ANNA', 'Audit Anna');
        $bob = $this->employee('EMP-AUD-BOB', 'Audit Bob');
        $this->log($anna, 'employee.created', $auditor);
        $this->log($anna, 'employee.status_changed', $auditor);
        $this->log($bob, 'employee.created', $other);
        $this->actAs($auditor);

        $this->assertSame(2, $this->getJson('/api/hrms/audit?action=employee.created')->json('pagination.total'));
        $this->assertSame(2, $this->getJson("/api/hrms/audit?actor_user_id={$auditor->id}")->json('pagination.total'));
        $this->assertSame(
            2,
            $this->getJson("/api/hrms/audit?subject_type={$this->trailType($anna)}&subject_id={$anna->id}")->json('pagination.total'),
        );
        $this->assertSame(1, $this->getJson('/api/hrms/audit?q=status_changed')->json('pagination.total'));
        $this->assertSame(3, $this->getJson('/api/hrms/audit')->json('pagination.total'));
    }

    public function test_the_trail_returns_one_records_history_newest_first(): void
    {
        $auditor = $this->userWith(['hrms.view', 'hrms.audit.view']);
        $anna = $this->employee('EMP-AUD-TR1', 'Audit Trail One');
        $bob = $this->employee('EMP-AUD-TR2', 'Audit Trail Two');
        $this->log($anna, 'employee.created', $auditor);
        $this->log($anna, 'employee.status_changed', $auditor);
        $this->log($bob, 'employee.created', $auditor);
        $this->actAs($auditor);

        $trail = $this->getJson("/api/hrms/audit/{$this->trailType($anna)}/{$anna->id}")->assertOk()->json();

        $this->assertSame(2, $trail['total']);
        $this->assertSame('employee.status_changed', $trail['audit_logs'][0]['action']);
        $this->assertSame($auditor->name, $trail['audit_logs'][0]['actor']['name']);

        $missing = $this->getJson("/api/hrms/audit/{$this->trailType($anna)}/999999")->assertOk()->json();
        $this->assertSame(0, $missing['total']);
    }

    public function test_sensitive_values_arrive_masked(): void
    {
        $auditor = $this->userWith(['hrms.view', 'hrms.audit.view']);
        $anna = $this->employee('EMP-AUD-MASK', 'Audit Mask');
        $this->connectTenant('acme');
        app(HrmsAuditLogger::class)->log($anna, 'employee.compensation_changed', null, ['salary' => 90000], $auditor);
        $this->actAs($auditor);

        $row = $this->getJson('/api/hrms/audit?action=employee.compensation_changed')
            ->assertOk()
            ->json('audit_logs.0');

        $this->assertSame('***', $row['data']['after']['salary']);
    }

    public function test_the_employee_show_carries_its_trail_key(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.employees.view', 'hrms.audit.view']));
        $employee = $this->employee('EMP-AUD-SHOW', 'Audit Show');

        $this->getJson("/api/hrms/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('subject_type', $employee->getMorphClass());
    }

    // ------------------------------------------------------------ helpers

    private function employee(string $code = 'EMP-AUD-ONE', string $name = 'Audit One'): Employee
    {
        $this->connectTenant('acme');

        return Employee::create([
            'employee_code' => $code, 'name' => $name,
            'status' => EmployeeStatus::Active,
        ]);
    }

    private function log(Employee $subject, string $action, User $actor): void
    {
        $this->connectTenant('acme');
        app(HrmsAuditLogger::class)->log($subject, $action, null, null, $actor);
    }

    private function trailType(Employee $employee): string
    {
        return urlencode($employee->getMorphClass());
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
            'name' => "Audit User {$sequence}",
            'email' => "audit.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Audit Role {$sequence}",
            'slug' => "audit-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
