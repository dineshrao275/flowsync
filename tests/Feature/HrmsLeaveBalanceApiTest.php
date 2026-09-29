<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P6.4b — balances and accrual runs over HTTP.
 *
 * Reads are policy-gated per employee (self-service included, strangers
 * 403); the run takes `hrms.leave.manage`, credits idempotently across the
 * scope, and audits once as a bulk.
 */
class HrmsLeaveBalanceApiTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.leave']);
    }

    public function test_an_employee_reads_their_own_balances(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Balance Reader', ['user_id' => $user->id]);
        $this->actAs($user);

        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        LeaveAdjustment::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => 2026,
            'kind' => 'opening',
            'quantity' => 10,
            'created_at' => now(),
        ]);

        $body = $this->getJson('/api/hrms/leave/balances?year=2026')->assertOk()->json();

        $this->assertSame($employee->id, $body['employee']['id']);

        $annual = collect($body['balances'])->firstWhere('leave_type_id', $type->id);

        // Whole-number floats lose their fraction in JSON (`10.0` encodes
        // as `10`), so the assertion casts back before comparing strictly.
        $this->assertSame(10.0, (float) $annual['balance']);
        $this->assertSame('Annual Leave', $annual['type']['name']);
    }

    public function test_another_persons_balances_need_the_view_permission(): void
    {
        $reader = $this->userWith(['hrms.view']);
        $this->makeEmployee('Reader', ['user_id' => $reader->id]);
        $other = $this->userWith(['hrms.view']);
        $otherEmployee = $this->makeEmployee('Other', ['user_id' => $other->id]);

        $this->actAs($reader);
        $this->getJson("/api/hrms/leave/balances?employee_id={$otherEmployee->id}")->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.leave.view']));
        $this->getJson("/api/hrms/leave/balances?employee_id={$otherEmployee->id}")->assertOk();
    }

    public function test_an_accrual_run_needs_the_manage_permission_and_audits_once(): void
    {
        $viewer = $this->userWith(['hrms.view', 'hrms.leave.view']);
        $this->makeEmployee('Viewer', ['user_id' => $viewer->id]);
        $this->actAs($viewer);

        $sick = LeaveType::query()->where('code', 'sick')->firstOrFail();

        $this->postJson('/api/hrms/leave/accrue', ['leave_type_id' => $sick->id])->assertForbidden();

        $this->loginAdmin();

        $this->makeEmployee('Bulk Earner One');
        $this->makeEmployee('Bulk Earner Two');

        $body = $this->postJson('/api/hrms/leave/accrue', [
            'leave_type_id' => $sick->id,
            'year' => 2026,
            'as_of' => '2026-03-10',
        ])->assertCreated()->json();

        // Sick accrues monthly: one credit per employee, everyone processed.
        $this->assertGreaterThanOrEqual(1, $body['employees']);
        $this->assertSame($body['employees'], $body['credited']);
        $this->assertSame(0, $body['skipped']);

        // A rerun credits nobody new — idempotent per period.
        $rerun = $this->postJson('/api/hrms/leave/accrue', [
            'leave_type_id' => $sick->id,
            'year' => 2026,
            'as_of' => '2026-03-20',
        ])->assertCreated()->json();

        $this->assertSame(0, $rerun['credited']);
        $this->assertSame($rerun['employees'], $rerun['skipped']);

        $this->assertSame(2, HrmsAuditLog::query()->where('action', 'leave.accrue_bulk')->count());
    }

    public function test_an_accrual_run_scopes_to_one_employee(): void
    {
        $this->loginAdmin();

        $employee = $this->makeEmployee('Scoped Earner');
        $sick = LeaveType::query()->where('code', 'sick')->firstOrFail();

        $body = $this->postJson('/api/hrms/leave/accrue', [
            'leave_type_id' => $sick->id,
            'employee_id' => $employee->id,
            'year' => 2026,
            'as_of' => '2026-03-10',
        ])->assertCreated()->json();

        $this->assertSame(1, $body['employees']);
        $this->assertSame(1, $body['credited']);
        $this->assertSame(0, $body['skipped']);
        $this->assertSame(2026, $body['year']);

        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $sick->id)
            ->firstOrFail();

        $this->assertSame(1.0, (float) $balance->balance);
    }

    public function test_an_accrual_run_validates_its_scope(): void
    {
        $this->loginAdmin();

        $sick = LeaveType::query()->where('code', 'sick')->firstOrFail();

        // A March date cannot credit leave year 2025.
        $this->postJson('/api/hrms/leave/accrue', [
            'leave_type_id' => $sick->id,
            'year' => 2025,
            'as_of' => '2026-03-10',
        ])->assertUnprocessable()->assertJsonValidationErrors('year');

        $this->postJson('/api/hrms/leave/accrue', ['leave_type_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
    }

    // ------------------------------------------------------------ helpers

    private function loginAdmin(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');
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

        $user = User::create([
            'name' => "Balance Api User {$sequence}",
            'email' => "balance.api.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Balance Api Role {$sequence}",
            'slug' => "balance-api-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmployee(string $name, array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-BAL-'.$sequence,
            'name' => $name,
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }

    private function setAcmeModules(array $modules): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }
}
