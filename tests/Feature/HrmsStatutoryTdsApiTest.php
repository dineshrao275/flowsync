<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Hrms\Statutory\StatutoryConfiguration;
use App\Models\Hrms\Statutory\StatutoryDeclaration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Payroll\PayrollService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P10.6b — TDS projections and the recompute twin over HTTP.
 *
 * Projecting and surrendering answer to manage alone; reading one quarter
 * is self-or-manage; the recompute endpoint answers to the run's own
 * calculate ability and refuses past review like the command does.
 */
class HrmsStatutoryTdsApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_non_manager_moves_nothing(): void
    {
        $employee = $this->pricedEmployee();
        $this->connectTenant('acme');
        $run = app(PayrollService::class)->openRun([
            'period_year' => 2026,
            'period_month' => 8,
            'pay_period_start' => '2026-08-01',
            'pay_period_end' => '2026-08-31',
            'pay_date' => '2026-09-05',
        ]);

        $this->actAs($this->userWith(['hrms.view']));

        $this->getJson('/api/hrms/payroll/statutory/tds-projects')->assertForbidden();
        $this->postJson('/api/hrms/payroll/statutory/tds-projects', [
            'employee_id' => $employee->id,
            'fiscal_year' => 2026,
        ])->assertForbidden();
        $this->postJson('/api/hrms/payroll/statutory/recompute', ['run_id' => $run->id])->assertForbidden();
    }

    public function test_a_manager_projects_warns_and_surrenders(): void
    {
        $employee = $this->pricedEmployee();
        $this->declare($employee);
        $this->seedConfig();
        $this->calculateAugust();
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.statutory.manage']));

        $body = $this->postJson('/api/hrms/payroll/statutory/tds-projects', [
            'employee_id' => $employee->id,
            'fiscal_year' => 2026,
        ])->assertOk()->json();

        $this->assertCount(4, $body['projects']);
        $this->assertSame([1, 2, 4], collect($body['warnings'])->pluck('quarter')->sort()->values()->all());

        $first = collect($body['projects'])->firstWhere('quarter', 1);

        $surrendered = $this->postJson("/api/hrms/payroll/statutory/tds-projects/{$first['id']}/surrender", [
            'challan_ref' => 'CHALLAN-281-002',
        ])->assertOk()->json('project');

        $this->assertSame('1250.00', $surrendered['tds_surrendered']);
        $this->assertSame('CHALLAN-281-002', $surrendered['challan_ref']);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'statutory.tds_surrendered')->exists());
    }

    public function test_a_person_reads_their_own_quarter(): void
    {
        $employee = $this->pricedEmployee(withUser: true);
        $this->declare($employee);
        $this->seedConfig();
        $this->calculateAugust();

        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.statutory.manage']));
        $projectId = $this->postJson('/api/hrms/payroll/statutory/tds-projects', [
            'employee_id' => $employee->id,
            'fiscal_year' => 2026,
        ])->assertOk()->json('projects.0.id');

        $this->actAs($this->userWith(['hrms.view'], $employee));
        $this->getJson("/api/hrms/payroll/statutory/tds-projects/{$projectId}")->assertOk();
        $this->getJson('/api/hrms/payroll/statutory/tds-projects')->assertForbidden();
    }

    public function test_the_recompute_twin_mirrors_the_command(): void
    {
        $this->pricedEmployee();
        $this->seedConfig();
        $run = $this->calculateAugust();
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.run']));

        $this->postJson('/api/hrms/payroll/statutory/recompute', ['run_id' => $run->id])
            ->assertOk()->assertJsonPath('summary.full', false);

        $this->postJson('/api/hrms/payroll/statutory/recompute', ['run_id' => $run->id, 'force' => true])
            ->assertOk()->assertJsonPath('summary.full', true);
    }

    // ------------------------------------------------------------ helpers

    private function calculateAugust(): PayrollRun
    {
        $this->connectTenant('acme');

        $run = app(PayrollService::class)->openRun([
            'period_year' => 2026,
            'period_month' => 8,
            'pay_period_start' => '2026-08-01',
            'pay_period_end' => '2026-08-31',
            'pay_date' => '2026-09-05',
        ]);

        app(PayrollService::class)->calculate($run);

        return $run->refresh();
    }

    private function seedConfig(): void
    {
        $this->connectTenant('acme');

        StatutoryConfiguration::updateOrCreate(
            ['code' => 'test-us-api'],
            [
                'country' => 'US',
                'region' => null,
                'name' => 'Test US API',
                'is_active' => true,
                'config' => [
                    'tds' => ['enabled' => true, 'slabs' => [
                        ['up_to' => '250000', 'rate' => '0'],
                        ['up_to' => '500000', 'rate' => '5'],
                        ['up_to' => null, 'rate' => '10'],
                    ]],
                ],
            ],
        );
    }

    private function declare(Employee $employee): void
    {
        $this->connectTenant('acme');

        StatutoryDeclaration::create([
            'employee_id' => $employee->id,
            'fiscal_year' => 2026,
            'section' => '80c',
            'declared_amount' => '100000',
            'status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    private function pricedEmployee(bool $withUser = false): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $userId = $withUser ? User::create([
            'name' => "TDS Api Owner {$sequence}",
            'email' => "tds.api.owner.{$sequence}@flowsync.test",
            'password' => 'password',
        ])->id : null;

        $employee = Employee::create([
            'employee_code' => 'EMP-TDSA-'.$sequence,
            'name' => "TDS Api Employee {$sequence}",
            'status' => EmployeeStatus::Active,
            'country' => 'US',
            'user_id' => $userId,
        ]);

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();
        $pt = SalaryComponent::query()->where('code', 'professional_tax')->firstOrFail();

        $structure = app(CompensationService::class)->createStructure(
            ['name' => 'TDS Api 6L', 'currency' => 'INR', 'effective_from' => '2026-04-01'],
            [
                ['component_id' => $basic->id, 'value' => 25000, 'sequence' => 10],
                ['component_id' => $hra->id, 'value' => 50, 'sequence' => 20],
                ['component_id' => $pt->id, 'value' => 200, 'sequence' => 30],
            ],
        );

        app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');

        return $employee;
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs, ?Employee $employee = null): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        if ($employee !== null && $employee->user_id !== null) {
            $user = User::findOrFail($employee->user_id);
        } else {
            $user = User::create([
                'name' => "TDS Api User {$sequence}",
                'email' => "tds.api.user.{$sequence}@flowsync.test",
                'password' => 'password',
            ]);
        }

        $role = Role::create([
            'name' => "TDS Api Role {$sequence}",
            'slug' => "tds-api-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
