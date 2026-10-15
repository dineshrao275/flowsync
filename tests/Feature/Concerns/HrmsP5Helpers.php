<?php

namespace Tests\Feature\Concerns;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;

/**
 * Shared fixtures for the HRMS Phase 5 feature tests (acme tenant, pro plan
 * with an explicit module list, role-scoped users, a minimal employee).
 * Use together with `Tests\IsolatesDatabase`.
 */
trait HrmsP5Helpers
{
    protected function setAcmeModules(array $modules): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }

    protected function loginAdmin(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');
    }

    protected function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /** @param  list<string>  $permissionSlugs */
    protected function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;
        $sequence++;

        $user = User::create([
            'name' => "P5 User {$sequence}",
            'email' => "p5.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create(['name' => "P5 Role {$sequence}", 'slug' => "p5-role-{$sequence}"]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all());
        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function makeEmployee(string $name, array $overrides = []): Employee
    {
        static $sequence = 0;
        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-P5-'.$sequence,
            'name' => $name,
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }
}
