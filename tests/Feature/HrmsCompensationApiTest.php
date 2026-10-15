<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Payroll\SalaryStructure;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrms\Compensation\CompensationService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P9.5a — the compensation write surface.
 *
 * Reads take the view permission, every mutation takes manage alone; starter
 * heads refuse repurposing, in-use rows refuse deletion, templates version
 * instead of editing money, and every mutation audits. The assignment and
 * revision flows ride HTTP end to end, including self-service salary reads
 * and the nested revision belonging check.
 */
class HrmsCompensationApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_viewer_reads_but_writes_nothing(): void
    {
        $employee = $this->makeEmployee();
        $structure = $this->standardStructure();
        $this->actAs($this->userWith(['hrms.view', 'hrms.compensation.view']));

        $this->getJson('/api/hrms/payroll/components')->assertOk();
        $this->getJson('/api/hrms/payroll/structures')->assertOk();
        $this->getJson("/api/hrms/payroll/employees/{$employee->id}/salary")->assertOk();

        $this->postJson('/api/hrms/payroll/components', ['name' => 'Nope', 'type' => 'earning'])->assertForbidden();
        $this->postJson('/api/hrms/payroll/structures', ['name' => 'Nope', 'effective_from' => '2026-04-01'])->assertForbidden();
        $this->postJson("/api/hrms/payroll/employees/{$employee->id}/salary", [
            'structure_id' => $structure->id,
            'ctc_annual' => '600000',
            'effective_from' => '2026-04-01',
        ])->assertForbidden();
    }

    public function test_a_manager_runs_the_component_catalogue(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.compensation.view', 'hrms.compensation.manage']));

        $created = $this->postJson('/api/hrms/payroll/components', [
            'name' => 'Night Allowance',
            'code' => 'night_allowance',
            'type' => 'earning',
            'calculation_type' => 'fixed',
            'default_value' => 1500,
        ])->assertCreated()->json('component');

        $this->assertSame('night_allowance', $created['code']);

        $this->putJson("/api/hrms/payroll/components/{$created['id']}", ['name' => 'Night Shift Allowance'])
            ->assertOk()->assertJsonPath('component.name', 'Night Shift Allowance');

        $this->deleteJson("/api/hrms/payroll/components/{$created['id']}")->assertOk();
        $this->assertDatabaseMissing('salary_components', ['id' => $created['id']]);

        $this->assertTrue(HrmsAuditLog::query()->where('action', 'compensation.component_created')->exists());
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'compensation.component_deleted')->exists());
    }

    public function test_starter_heads_are_renamed_never_repurposed(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.compensation.view', 'hrms.compensation.manage']));
        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();

        $this->putJson("/api/hrms/payroll/components/{$basic->id}", ['type' => 'deduction'])->assertStatus(422);
        $this->deleteJson("/api/hrms/payroll/components/{$basic->id}")->assertStatus(422);
    }

    public function test_a_head_on_a_structure_is_not_deleted(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.compensation.view', 'hrms.compensation.manage']));
        $structure = $this->standardStructure();
        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();

        $this->deleteJson("/api/hrms/payroll/components/{$basic->id}")->assertStatus(422);
        $this->assertDatabaseHas('salary_components', ['id' => $basic->id]);
    }

    public function test_a_manager_runs_templates_but_money_does_not_move(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.compensation.view', 'hrms.compensation.manage']));

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();

        $structure = $this->postJson('/api/hrms/payroll/structures', [
            'name' => 'Template 6L',
            'currency' => 'INR',
            'effective_from' => '2026-04-01',
            'components' => [
                ['component_id' => $basic->id, 'value' => 25000],
                ['component_id' => $hra->id, 'value' => 50],
            ],
        ])->assertCreated()->json('structure');

        $this->assertCount(2, $structure['components']);

        // Money knobs are create-only: a template versions, it is not edited
        // under its assignments.
        $this->putJson("/api/hrms/payroll/structures/{$structure['id']}", ['currency' => 'USD'])->assertStatus(422);
        $this->putJson("/api/hrms/payroll/structures/{$structure['id']}", ['effective_from' => '2026-05-01'])->assertStatus(422);

        $this->putJson("/api/hrms/payroll/structures/{$structure['id']}", ['name' => 'Template Six L'])
            ->assertOk()->assertJsonPath('structure.name', 'Template Six L');

        // The sync replaces the whole list: HRA leaves, PT arrives.
        $pt = SalaryComponent::query()->where('code', 'professional_tax')->firstOrFail();
        $synced = $this->putJson("/api/hrms/payroll/structures/{$structure['id']}/components", [
            'components' => [
                ['component_id' => $basic->id, 'value' => 25000],
                ['component_id' => $pt->id, 'value' => 200],
            ],
        ])->assertOk()->json('structure');

        $this->assertSame(['basic', 'professional_tax'], collect($synced['components'])->pluck('code')->sort()->values()->all());
    }

    public function test_a_priced_structure_is_not_deleted(): void
    {
        $employee = $this->makeEmployee();
        $structure = $this->standardStructure();
        app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');

        $this->actAs($this->userWith(['hrms.view', 'hrms.compensation.view', 'hrms.compensation.manage']));

        $this->deleteJson("/api/hrms/payroll/structures/{$structure->id}")->assertStatus(422);
        $this->assertDatabaseHas('salary_structures', ['id' => $structure->id]);
    }

    public function test_assigning_and_reading_a_pay_basis(): void
    {
        $employee = $this->makeEmployee();
        $structure = $this->standardStructure();
        $this->actAs($this->userWith(['hrms.view', 'hrms.compensation.view', 'hrms.compensation.manage']));

        $body = $this->postJson("/api/hrms/payroll/employees/{$employee->id}/salary", [
            'structure_id' => $structure->id,
            'ctc_annual' => '600000',
            'effective_from' => '2026-04-01',
            'reason' => 'Joining CTC.',
        ])->assertCreated()->json('assignment');

        $this->assertSame('600000.00', $body['ctc_annual']);
        $this->assertNotEmpty($body['heads']);

        $this->getJson("/api/hrms/payroll/employees/{$employee->id}/salary")
            ->assertOk()->assertJsonPath('assignment.ctc_annual', '600000.00');
    }

    public function test_a_person_reads_their_own_basis(): void
    {
        $employee = $this->makeEmployee(withUser: true);
        $structure = $this->standardStructure();
        app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');

        // Self reads their own basis with or without the view permission —
        // the employment-record rule, not the payslip one: a person knows
        // their own offer letter. Anyone else needs the view permission.
        $this->actAs($this->userWith(['hrms.view', 'hrms.compensation.view'], $employee));
        $this->getJson("/api/hrms/payroll/employees/{$employee->id}/salary")->assertOk();

        $this->actAs($this->userWith(['hrms.view'], $employee));
        $this->getJson("/api/hrms/payroll/employees/{$employee->id}/salary")->assertOk();

        $this->actAs($this->userWith(['hrms.view']));
        $this->getJson("/api/hrms/payroll/employees/{$employee->id}/salary")->assertForbidden();
    }

    public function test_a_small_raise_applies_while_a_large_one_waits(): void
    {
        [$manager, $employee] = $this->reportingLine();
        $structure = $this->standardStructure();
        app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');
        $this->actAs($this->userWith(['hrms.view', 'hrms.compensation.view', 'hrms.compensation.manage']));

        // +5%: under the threshold, applies immediately with its letter.
        $small = $this->postJson("/api/hrms/payroll/employees/{$employee->id}/revisions", [
            'to_ctc' => '630000',
            'effective_from' => '2026-10-01',
            'reason' => 'Annual correction.',
        ])->assertCreated()->json('revision');

        $this->assertSame('applied', $small['status']);
        $this->assertNotNull($small['letter_document_id']);

        // +50%: waits for the chain, and applying early is refused.
        $large = $this->postJson("/api/hrms/payroll/employees/{$employee->id}/revisions", [
            'to_ctc' => '900000',
            'effective_from' => '2026-11-01',
        ])->assertCreated()->json('revision');

        $this->assertSame('draft', $large['status']);
        $this->postJson("/api/hrms/payroll/employees/{$employee->id}/revisions/{$large['id']}/apply")->assertStatus(422);
    }

    public function test_a_revision_from_another_record_is_not_applied(): void
    {
        $first = $this->makeEmployee();
        $second = $this->makeEmployee();
        $structure = $this->standardStructure();
        $service = app(CompensationService::class);
        $service->assign($first, $structure, '600000', '2026-04-01');
        $service->assign($second, $structure, '600000', '2026-04-01');
        $this->actAs($this->userWith(['hrms.view', 'hrms.compensation.view', 'hrms.compensation.manage']));

        $revision = $this->postJson("/api/hrms/payroll/employees/{$first->id}/revisions", [
            'to_ctc' => '900000',
            'effective_from' => '2026-11-01',
        ])->assertCreated()->json('revision');

        $this->postJson("/api/hrms/payroll/employees/{$second->id}/revisions/{$revision['id']}/apply")->assertNotFound();
    }

    // ------------------------------------------------------------ helpers

    /**
     * A manager and their report. The report's large revision opens a chain
     * behind a real approver — without one the engine auto-approves (the
     * P6.3 rule) and there is no waiting to test.
     *
     * @return array{Employee, Employee}
     */
    private function reportingLine(): array
    {
        $this->connectTenant('acme');

        $managerUser = User::create([
            'name' => 'Api Pay Manager',
            'email' => 'api.pay.manager@flowsync.test',
            'password' => 'password',
        ]);

        $manager = Employee::create([
            'employee_code' => 'EMP-API-MGR',
            'name' => 'Api Pay Manager',
            'status' => EmployeeStatus::Active,
            'user_id' => $managerUser->id,
        ]);

        $report = $this->makeEmployee();
        $report->update(['manager_id' => $manager->id]);

        return [$manager, $report->refresh()];
    }

    private function standardStructure(): SalaryStructure
    {
        $this->connectTenant('acme');

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();
        $pt = SalaryComponent::query()->where('code', 'professional_tax')->firstOrFail();

        return app(CompensationService::class)->createStructure(
            ['name' => 'Api 6L', 'currency' => 'INR', 'effective_from' => '2026-04-01'],
            [
                ['component_id' => $basic->id, 'value' => 25000, 'sequence' => 10],
                ['component_id' => $hra->id, 'value' => 50, 'sequence' => 20],
                ['component_id' => $pt->id, 'value' => 200, 'sequence' => 30],
            ],
        );
    }

    private function makeEmployee(bool $withUser = false): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $userId = $withUser ? User::create([
            'name' => "Api Owner {$sequence}",
            'email' => "api.owner.{$sequence}@flowsync.test",
            'password' => 'password',
        ])->id : null;

        return Employee::create([
            'employee_code' => 'EMP-API-'.$sequence,
            'name' => "Api Employee {$sequence}",
            'status' => EmployeeStatus::Active,
            'user_id' => $userId,
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
    private function userWith(array $permissionSlugs, ?Employee $employee = null): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        if ($employee !== null && $employee->user_id !== null) {
            $user = User::findOrFail($employee->user_id);
        } else {
            $user = User::create([
                'name' => "Api User {$sequence}",
                'email' => "api.user.{$sequence}@flowsync.test",
                'password' => 'password',
            ]);
        }

        $role = Role::create([
            'name' => "Api Role {$sequence}",
            'slug' => "api-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
