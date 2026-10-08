<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Payroll\PayrollService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P9.5b — the payroll run write surface.
 *
 * Every run step answers to the one runner permission; adjustments land on
 * review payslips with recomputed totals and audit rows, and refuse past
 * review; a foreign adjustment id 404s rather than editing the wrong
 * person's pay; and `my-payslips` is self-scoped.
 */
class HrmsPayrollRunApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_non_runner_reaches_no_run_step(): void
    {
        $this->pricedEmployee();
        $this->connectTenant('acme');
        $run = app(PayrollService::class)->openRun($this->period());
        app(PayrollService::class)->calculate($run);
        $payslipId = $run->refresh()->payslips()->firstOrFail()->id;

        $this->actAs($this->userWith(['hrms.view']));

        $this->postJson('/api/hrms/payroll/runs', $this->period())->assertForbidden();
        $this->getJson('/api/hrms/payroll/runs')->assertForbidden();
        $this->postJson("/api/hrms/payroll/runs/{$run->id}/calculate", [])->assertForbidden();
        $this->postJson("/api/hrms/payroll/payslips/{$payslipId}/adjustments", [
            'kind' => 'earning', 'label' => 'Nope', 'amount' => '100',
        ])->assertForbidden();
    }

    public function test_a_runner_walks_a_run_from_open_to_lock(): void
    {
        $this->pricedEmployee();
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.run']));

        $run = $this->postJson('/api/hrms/payroll/runs', $this->period())
            ->assertCreated()->json('run');
        $this->assertSame('draft', $run['status']);

        // A month gets exactly one run.
        $this->postJson('/api/hrms/payroll/runs', $this->period())->assertStatus(422);

        $summary = $this->postJson("/api/hrms/payroll/runs/{$run['id']}/calculate", [])
            ->assertOk()->json('summary');
        $this->assertSame(['calculated' => 1, 'skipped' => []], $summary);

        $this->postJson("/api/hrms/payroll/runs/{$run['id']}/approve", [])->assertOk()
            ->assertJsonPath('run.status', 'approved');
        $this->postJson("/api/hrms/payroll/runs/{$run['id']}/publish", [])->assertOk()
            ->assertJsonPath('run.status', 'processing');
        $this->postJson("/api/hrms/payroll/runs/{$run['id']}/mark-paid", [])->assertOk()
            ->assertJsonPath('run.status', 'paid');
        $this->postJson("/api/hrms/payroll/runs/{$run['id']}/lock", [])->assertOk()
            ->assertJsonPath('run.status', 'locked');

        // A locked run refuses recalculation.
        $this->postJson("/api/hrms/payroll/runs/{$run['id']}/calculate", [])->assertStatus(422);

        foreach (['payroll.run_opened', 'payroll.calculated', 'payroll.approved', 'payroll.published', 'payroll.paid', 'payroll.locked'] as $action) {
            $this->assertTrue(
                HrmsAuditLog::query()->where('action', $action)->exists(),
                "Missing audit row: {$action}.",
            );
        }
    }

    public function test_adjustments_move_totals_and_audit(): void
    {
        $this->pricedEmployee();
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.run', 'hrms.payroll.view_all']));

        $runId = $this->openRun()['id'];
        $this->postJson("/api/hrms/payroll/runs/{$runId}/calculate", [])->assertOk();

        $payslipId = $this->payslipId($runId);

        // 37300 + 5000 bonus, then a 300 recovery.
        $body = $this->postJson("/api/hrms/payroll/payslips/{$payslipId}/adjustments", [
            'kind' => 'earning', 'label' => 'Joining bonus', 'amount' => '5000',
        ])->assertCreated()->json('payslip');
        $this->assertSame('42300.00', $body['net_pay']);

        $body = $this->postJson("/api/hrms/payroll/payslips/{$payslipId}/adjustments", [
            'kind' => 'deduction', 'label' => 'Advance recovery', 'amount' => '300',
        ])->assertCreated()->json('payslip');
        $this->assertSame('42000.00', $body['net_pay']);
        $this->assertSame('500.00', $body['total_deductions']);

        $adjustmentId = collect($body['adjustments'])->firstWhere('label', 'Advance recovery')['id'];
        $body = $this->deleteJson("/api/hrms/payroll/payslips/{$payslipId}/adjustments/{$adjustmentId}")
            ->assertOk()->json('payslip');
        $this->assertSame('42300.00', $body['net_pay']);

        $this->assertTrue(HrmsAuditLog::query()->where('action', 'payroll.adjustment_added')->exists());
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'payroll.adjustment_removed')->exists());
    }

    public function test_adjustments_refuse_past_review_and_foreign_ids(): void
    {
        $mineEmployee = $this->pricedEmployee();
        $other = $this->pricedEmployee();
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.run', 'hrms.payroll.view_all']));

        $runId = $this->openRun()['id'];
        $this->postJson("/api/hrms/payroll/runs/{$runId}/calculate", [])->assertOk();

        $mine = $this->payslipIdFor($runId, $mineEmployee->id);
        $theirs = $this->payslipIdFor($runId, $other->id);

        // An adjustment id from another payslip is not ours to delete.
        $foreign = $this->postJson("/api/hrms/payroll/payslips/{$theirs}/adjustments", [
            'kind' => 'earning', 'label' => 'Theirs', 'amount' => '100',
        ])->assertCreated()->json('payslip');
        $foreignId = collect($foreign['adjustments'])->firstWhere('label', 'Theirs')['id'];
        $this->deleteJson("/api/hrms/payroll/payslips/{$mine}/adjustments/{$foreignId}")->assertNotFound();

        // Past review the window closes.
        $this->postJson("/api/hrms/payroll/runs/{$runId}/approve", [])->assertOk();
        $this->postJson("/api/hrms/payroll/payslips/{$mine}/adjustments", [
            'kind' => 'earning', 'label' => 'Late', 'amount' => '100',
        ])->assertStatus(422);
        $this->deleteJson("/api/hrms/payroll/payslips/{$theirs}/adjustments/{$foreignId}")->assertStatus(422);
    }

    public function test_my_payslips_are_self_scoped(): void
    {
        $mine = $this->pricedEmployee();
        $theirs = $this->pricedEmployee();
        $this->actAs($this->userWith(['hrms.view', 'hrms.payroll.run']));

        $runId = $this->openRun()['id'];
        $this->postJson("/api/hrms/payroll/runs/{$runId}/calculate", [])->assertOk();

        $this->actAs($this->userWith(['hrms.view'], $mine));
        $body = $this->getJson('/api/hrms/payroll/my-payslips')->assertOk()->json();
        $this->assertSame($mine->id, $body['employee_id']);
        $this->assertCount(1, $body['payslips']);
        $this->assertSame('37300.00', $body['payslips'][0]['net_pay']);

        // A login with no employment record inherits nobody's pay.
        $this->actAs($this->userWith(['hrms.view']));
        $this->getJson('/api/hrms/payroll/my-payslips')->assertOk()
            ->assertJsonPath('employee_id', null)
            ->assertJsonCount(0, 'payslips');
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function period(array $overrides = []): array
    {
        return [
            'period_year' => 2026,
            'period_month' => 8,
            'pay_period_start' => '2026-08-01',
            'pay_period_end' => '2026-08-31',
            'pay_date' => '2026-09-05',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function openRun(): array
    {
        return $this->postJson('/api/hrms/payroll/runs', $this->period())->assertCreated()->json('run');
    }

    private function payslipId(int $runId): int
    {
        return (int) $this->getJson("/api/hrms/payroll/runs/{$runId}/payslips")->assertOk()->json('payslips.0.id');
    }

    private function payslipIdFor(int $runId, int $employeeId): int
    {
        $payslips = $this->getJson("/api/hrms/payroll/runs/{$runId}/payslips")->assertOk()->json('payslips');

        return (int) collect($payslips)->firstWhere('employee.id', $employeeId)['id'];
    }

    private function pricedEmployee(): Employee
    {
        $employee = $this->makeEmployee();
        $this->connectTenant('acme');

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();
        $pt = SalaryComponent::query()->where('code', 'professional_tax')->firstOrFail();

        $structure = app(CompensationService::class)->createStructure(
            ['name' => 'Run 6L', 'currency' => 'INR', 'effective_from' => '2026-04-01'],
            [
                ['component_id' => $basic->id, 'value' => 25000, 'sequence' => 10],
                ['component_id' => $hra->id, 'value' => 50, 'sequence' => 20],
                ['component_id' => $pt->id, 'value' => 200, 'sequence' => 30],
            ],
        );

        app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');

        return $employee;
    }

    private function makeEmployee(): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $user = User::create([
            'name' => "Run Owner {$sequence}",
            'email' => "run.owner.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        return Employee::create([
            'employee_code' => 'EMP-RUN-'.$sequence,
            'name' => "Run Employee {$sequence}",
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
    private function userWith(array $permissionSlugs, ?Employee $employee = null): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        if ($employee !== null && $employee->user_id !== null) {
            $user = User::findOrFail($employee->user_id);
        } else {
            $user = User::create([
                'name' => "Run User {$sequence}",
                'email' => "run.user.{$sequence}@flowsync.test",
                'password' => 'password',
            ]);
        }

        $role = Role::create([
            'name' => "Run Role {$sequence}",
            'slug' => "run-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
