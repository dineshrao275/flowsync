<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * "My performance" is self-service: the page needs the cycle list before it can show a person's
 * own goals. A plain Employee holds only `hrms.performance.view_own`, so the cycle reads must
 * answer to any view scope — while writes and a login with no performance grant stay refused.
 */
class HrmsPerformanceSelfServiceTest extends TestCase
{
    use IsolatesDatabase;

    public function test_an_own_scope_holder_can_list_and_open_cycles(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.performance']);

        $manager = $this->userWith(['hrms.view', 'hrms.performance.manage']);
        $this->actAs($manager);
        $cycle = $this->postJson('/api/hrms/performance/cycles', [
            'name' => 'Self Service Cycle', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30',
        ])->assertCreated()->json('cycle');

        $employee = $this->userWith(['hrms.view', 'hrms.performance.view_own']);
        Employee::create(['employee_code' => 'EMP-SS-1', 'name' => 'Self Service', 'status' => EmployeeStatus::Active, 'user_id' => $employee->id]);
        $this->actAs($employee);

        $this->assertContains($cycle['id'], $this->getJson('/api/hrms/performance/cycles')->assertOk()->json('cycles.*.id'));
        $this->getJson("/api/hrms/performance/cycles/{$cycle['id']}")->assertOk()->assertJsonPath('cycle.id', $cycle['id']);
    }

    public function test_an_own_scope_holder_still_cannot_create_or_advance_a_cycle(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.performance']);

        $manager = $this->userWith(['hrms.view', 'hrms.performance.manage']);
        $this->actAs($manager);
        $cycle = $this->postJson('/api/hrms/performance/cycles', [
            'name' => 'Guarded Cycle', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30',
        ])->assertCreated()->json('cycle');

        $employee = $this->userWith(['hrms.view', 'hrms.performance.view_own']);
        $this->actAs($employee);

        $this->postJson('/api/hrms/performance/cycles', [
            'name' => 'Nope', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30',
        ])->assertForbidden();
        $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/open-check-in")->assertForbidden();
    }

    public function test_a_login_with_no_performance_grant_is_still_refused(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.performance']);

        $nobody = $this->userWith(['hrms.view']);
        $this->actAs($nobody);

        $this->getJson('/api/hrms/performance/cycles')->assertForbidden();
    }

    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;

        $user = User::create([
            'name' => "Self Service User {$sequence}",
            'email' => 'hrms.perf.selfservice.'.$sequence.'@flowsync.test',
            'password' => 'password',
        ]);

        $role = Role::create(['name' => "Self Service Role {$sequence}", 'slug' => 'hrms-perf-selfservice-role-'.$sequence]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all());
        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /**
     * @param  list<string>  $modules
     */
    private function setAcmeModules(array $modules): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }
}
