<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeavePolicy;
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
 * P6.4a — the leave catalogue over HTTP.
 *
 * Reads are employee-open (filing needs the catalogue); writes take
 * `hrms.leave.manage`. System rows rename but never restructure or delete,
 * referenced rows refuse deletion, and promoting a policy demotes the
 * previous default in the same transaction.
 */
class HrmsLeaveCatalogApiTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.leave']);
    }

    public function test_any_employee_lists_types_and_policies(): void
    {
        $user = $this->userWith(['hrms.view']);
        $this->makeEmployee('Catalog Reader', ['user_id' => $user->id]);
        $this->actAs($user);

        $types = $this->getJson('/api/hrms/leave/types')->assertOk()->json('leave_types');

        $this->assertGreaterThanOrEqual(5, count($types));

        $policies = $this->getJson('/api/hrms/leave/policies')->assertOk()->json('leave_policies');

        $this->assertSame('Standard', $policies[0]['name']);
        $this->assertTrue($policies[0]['is_default']);
    }

    public function test_a_login_with_no_employment_record_cannot_list(): void
    {
        $this->actAs($this->userWith(['hrms.view']));

        $this->getJson('/api/hrms/leave/types')->assertForbidden();
    }

    public function test_managing_types_needs_the_manage_permission(): void
    {
        $user = $this->userWith(['hrms.view', 'hrms.leave.view']);
        $this->makeEmployee('Type Viewer', ['user_id' => $user->id]);
        $this->actAs($user);

        $this->postJson('/api/hrms/leave/types', ['name' => 'Study Leave'])->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.leave.manage']));

        $body = $this->postJson('/api/hrms/leave/types', [
            'name' => 'Study Leave',
            'accrual_method' => 'none',
        ])->assertCreated()->json();

        $this->assertSame('study-leave', $body['leave_type']['slug']);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'leave.type_created')->exists());
    }

    public function test_a_system_type_renames_but_never_restructures_or_deletes(): void
    {
        $this->loginAdmin();

        $annual = LeaveType::query()->where('code', 'annual')->firstOrFail();

        $this->putJson("/api/hrms/leave/types/{$annual->id}", ['name' => 'Annual Vacation'])
            ->assertOk()->assertJsonPath('leave_type.name', 'Annual Vacation');

        $this->putJson("/api/hrms/leave/types/{$annual->id}", ['accrual_method' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors('form');

        $this->deleteJson("/api/hrms/leave/types/{$annual->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('form');
    }

    public function test_a_type_in_use_refuses_deletion(): void
    {
        $this->loginAdmin();

        $id = $this->postJson('/api/hrms/leave/types', ['name' => 'Doomed Leave'])->assertCreated()->json('leave_type.id');

        $employee = $this->makeEmployee('Doomed Holder');
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $id,
            'year' => 2026,
        ]);

        $this->deleteJson("/api/hrms/leave/types/{$id}")
            ->assertUnprocessable()->assertJsonValidationErrors('form');
    }

    public function test_promoting_a_policy_demotes_the_previous_default(): void
    {
        $this->loginAdmin();

        $id = $this->postJson('/api/hrms/leave/policies', [
            'name' => 'April Year',
            'accrual_period' => 'annual',
            'start_month' => 4,
            'is_default' => true,
        ])->assertCreated()->json('leave_policy.id');

        $this->assertSame($id, LeavePolicy::query()->default()->value('id'));
        $this->assertSame(1, LeavePolicy::query()->default()->count());
        $this->assertFalse(LeavePolicy::query()->where('slug', 'standard')->firstOrFail()->is_default);
    }

    public function test_the_default_and_linked_policies_refuse_deletion(): void
    {
        $this->loginAdmin();

        $standard = LeavePolicy::query()->where('slug', 'standard')->firstOrFail();

        $this->deleteJson("/api/hrms/leave/policies/{$standard->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('form');

        $id = $this->postJson('/api/hrms/leave/policies', ['name' => 'Spare'])->assertCreated()->json('leave_policy.id');

        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $type->policies()->syncWithoutDetaching([$id]);

        $this->deleteJson("/api/hrms/leave/policies/{$id}")
            ->assertUnprocessable()->assertJsonValidationErrors('form');

        $type->policies()->detach([$id]);

        $this->deleteJson("/api/hrms/leave/policies/{$id}")->assertOk();
    }

    public function test_a_bad_accrual_method_is_a_422(): void
    {
        $this->loginAdmin();

        $this->postJson('/api/hrms/leave/types', ['name' => 'Weird Leave', 'accrual_method' => 'whenever'])
            ->assertUnprocessable()->assertJsonValidationErrors('accrual_method');
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
            'name' => "Catalog User {$sequence}",
            'email' => "catalog.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Catalog Role {$sequence}",
            'slug' => "catalog-role-{$sequence}",
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
            'employee_code' => 'EMP-CAT-'.$sequence,
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
