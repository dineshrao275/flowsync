<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseCategory;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Payroll\PayrollService;
use App\Services\Hrms\Shared\ApprovalService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P11.3a — claims and categories over HTTP.
 *
 * Listing and reading are self-or-view, filing self-or-manage, deciding
 * the approve permission alone; the full chain (file → submit → two-leg
 * decision → payroll) runs end to end with a reduced approval, and the
 * catalogue guards refuse starters and in-use rows.
 */
class HrmsExpenseApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_viewer_reads_but_writes_nothing(): void
    {
        $employee = $this->makeEmployee();
        $this->actAs($this->userWith(['hrms.view', 'hrms.expenses.view']));

        $this->getJson('/api/hrms/expenses/categories')->assertOk();
        $this->getJson('/api/hrms/expenses/claims')->assertOk();
        $this->getJson('/api/hrms/expenses/claims/999999')->assertNotFound();

        $this->postJson('/api/hrms/expenses/categories', ['name' => 'Nope'])->assertForbidden();
        $this->postJson('/api/hrms/expenses/claims', [
            'employee_id' => $employee->id,
            'claim_date' => '2026-08-10',
            'period_year' => 2026,
            'period_month' => 8,
            'purpose' => 'Nope.',
            'items' => [['description' => 'Nope.', 'amount' => '5']],
        ])->assertForbidden();
    }

    public function test_a_claim_walks_file_to_paid_with_a_cut(): void
    {
        [$manager, $employee, $finance] = $this->crew();
        $this->price($employee);
        $this->actAs($employee->user);

        $claim = $this->postJson('/api/hrms/expenses/claims', [
            'employee_id' => $employee->id,
            'claim_date' => '2026-08-10',
            'period_year' => 2026,
            'period_month' => 8,
            'purpose' => 'Client onsite.',
            'items' => [
                ['description' => 'Train.', 'amount' => '120'],
                ['description' => 'Hotel.', 'amount' => '300'],
            ],
        ])->assertCreated()->json('claim');

        $this->assertSame('420.00', $claim['total_amount']);
        $this->assertMatchesRegularExpression('/^EXP-2026-\d{6}$/', $claim['claim_number']);

        // A draft's lines rewrite wholesale.
        $this->putJson("/api/hrms/expenses/claims/{$claim['id']}/items", [
            'items' => [['description' => 'Train.', 'amount' => '120']],
        ])->assertOk()->assertJsonPath('claim.total_amount', '120.00');

        $this->postJson("/api/hrms/expenses/claims/{$claim['id']}/submit", [])->assertOk();

        // Locked past draft.
        $this->putJson("/api/hrms/expenses/claims/{$claim['id']}/items", [
            'items' => [['description' => 'Train.', 'amount' => '120']],
        ])->assertStatus(422);

        // The manager clears leg one through the chain.
        $this->chainApprove($claim['id'], $manager->user, $finance);

        // Finance clears leg two with a cut and its reason, over HTTP.
        $this->actAs($finance);
        $decided = $this->postJson("/api/hrms/expenses/claims/{$claim['id']}/decide", [
            'verdict' => 'approve',
            'approved_amount' => '100.00',
            'reason' => 'First class over policy.',
        ])->assertOk()->json('claim');

        $this->assertSame('approved', $decided['status']);
        $this->assertSame('100.00', $decided['approved_amount']);

        // The August run pays it as an earning line.
        $run = $this->openRun();
        app(PayrollService::class)->calculate($run);

        $paid = $this->getJson("/api/hrms/expenses/claims/{$claim['id']}")->assertOk()->json('claim');
        $this->assertSame('paid', $paid['status']);
        $this->assertSame('100.00', $paid['reimbursed_amount']);

        $payslip = $run->refresh()->payslips()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertCount(1, $payslip->adjustments()->where('reference_type', 'expense_claim')->get());
    }

    public function test_an_owner_decides_nothing_and_a_verdict_is_named(): void
    {
        [$manager, $employee, $finance] = $this->crew();
        $this->actAs($employee->user);

        $claim = $this->postJson('/api/hrms/expenses/claims', [
            'employee_id' => $employee->id,
            'claim_date' => '2026-08-10',
            'period_year' => 2026,
            'period_month' => 8,
            'purpose' => 'Own decision.',
            'items' => [['description' => 'Lunch.', 'amount' => '40']],
        ])->assertCreated()->json('claim');

        $this->postJson("/api/hrms/expenses/claims/{$claim['id']}/submit", [])->assertOk();

        // The owner holds the approve permission and still cannot decide.
        $this->postJson("/api/hrms/expenses/claims/{$claim['id']}/decide", [
            'verdict' => 'approve',
        ])->assertStatus(422);

        // And a verdict is required, not guessed.
        $this->actAs($finance);
        $this->postJson("/api/hrms/expenses/claims/{$claim['id']}/decide", [])->assertStatus(422);
    }

    public function test_a_plain_employee_lists_only_their_own(): void
    {
        [$manager, $employee] = $this->reportingLine();
        $other = $this->makeEmployee(withUser: true);
        $this->actAs($this->userWith(['hrms.view'], $employee));

        $this->postJson('/api/hrms/expenses/claims', [
            'employee_id' => $employee->id,
            'claim_date' => '2026-08-10',
            'period_year' => 2026,
            'period_month' => 8,
            'purpose' => 'Mine.',
            'items' => [['description' => 'Pen.', 'amount' => '5']],
        ])->assertCreated();

        $mine = $this->getJson('/api/hrms/expenses/claims')->assertOk()->json('claims');
        $this->assertCount(1, $mine);

        $this->actAs($this->userWith(['hrms.view'], $other));
        $this->assertCount(0, $this->getJson('/api/hrms/expenses/claims')->assertOk()->json('claims'));
    }

    public function test_the_catalogue_guards_starters_and_used_rows(): void
    {
        [$manager, $employee] = $this->reportingLine();
        $this->actAs($this->userWith(['hrms.view', 'hrms.expenses.view', 'hrms.expenses.manage']));

        $travel = ExpenseCategory::query()->where('slug', 'travel')->firstOrFail();
        $this->deleteJson("/api/hrms/expenses/categories/{$travel->id}")->assertStatus(422);

        $custom = $this->postJson('/api/hrms/expenses/categories', ['name' => 'Per diem'])
            ->assertCreated()->json('category');

        $this->actAs($this->userWith(['hrms.view'], $employee));
        $this->postJson('/api/hrms/expenses/claims', [
            'employee_id' => $employee->id,
            'claim_date' => '2026-08-10',
            'period_year' => 2026,
            'period_month' => 8,
            'purpose' => 'Per diem run.',
            'items' => [['category_id' => $custom['id'], 'description' => 'Daily.', 'amount' => '60']],
        ])->assertCreated();

        $this->actAs($this->userWith(['hrms.view', 'hrms.expenses.view', 'hrms.expenses.manage']));
        $this->deleteJson("/api/hrms/expenses/categories/{$custom['id']}")->assertStatus(422);
    }

    // ------------------------------------------------------------ helpers

    /**
     * Claimant, manager (approve permission for the HTTP decide), and a
     * finance user holding the chain's finance role.
     *
     * @return array{Employee, Employee, User}
     */
    private function crew(): array
    {
        [$manager, $employee] = $this->reportingLine();

        $financeRole = Role::create(['name' => 'Expense Finance', 'slug' => 'expense-finance']);
        $financeRole->permissions()->sync(
            Permission::whereIn('slug', ['hrms.view', 'hrms.expenses.view', 'hrms.expenses.approve'])->pluck('id')->all(),
        );

        $finance = User::create([
            'name' => 'Expense Finance',
            'email' => 'expense.finance.http@flowsync.test',
            'password' => 'password',
        ]);
        $finance->roles()->sync([$financeRole->id]);

        $deciderRole = Role::create(['name' => 'Expense Decider', 'slug' => 'expense-decider']);
        $deciderRole->permissions()->sync(
            Permission::whereIn('slug', ['hrms.view', 'hrms.expenses.approve'])->pluck('id')->all(),
        );
        $manager->user->roles()->syncWithoutDetaching([$deciderRole->id]);

        $ownerRole = Role::create(['name' => 'Expense Owner Decide', 'slug' => 'expense-owner-decide']);
        $ownerRole->permissions()->sync(
            Permission::whereIn('slug', ['hrms.view', 'hrms.expenses.approve'])->pluck('id')->all(),
        );
        $employee->user->roles()->syncWithoutDetaching([$ownerRole->id]);

        // Fresh instances: hasPermission reads the loaded roles relation,
        // so acting through a stale copy would fail gates the user holds.
        $manager->user = $manager->user->fresh(['roles.permissions']);
        $employee->user = $employee->user->fresh(['roles.permissions']);

        return [$manager, $employee, $finance->fresh(['roles.permissions'])];
    }

    /**
     * @return array{Employee, Employee} Manager and report, each with `->user`.
     */
    private function reportingLine(): array
    {
        $this->connectTenant('acme');

        $managerUser = User::create([
            'name' => 'Expense Http Manager',
            'email' => 'expense.http.manager@flowsync.test',
            'password' => 'password',
        ]);
        $manager = Employee::create([
            'employee_code' => 'EMP-EXPH-MGR',
            'name' => 'Expense Http Manager',
            'status' => EmployeeStatus::Active,
            'user_id' => $managerUser->id,
        ]);
        $manager->user = $managerUser;

        $reportUser = User::create([
            'name' => 'Expense Http Report',
            'email' => 'expense.http.report@flowsync.test',
            'password' => 'password',
        ]);
        $report = Employee::create([
            'employee_code' => 'EMP-EXPH-REP',
            'name' => 'Expense Http Report',
            'status' => EmployeeStatus::Active,
            'user_id' => $reportUser->id,
            'manager_id' => $manager->id,
        ]);
        $report->user = $reportUser;

        return [$manager, $report];
    }

    /**
     * Advance the chain fully: the manager clears leg one, then finance —
     * attached to whichever role the finance leg named, so seeding never
     * matters — clears leg two.
     */
    private function chainApprove(int $claimId, User $managerUser, User $finance): void
    {
        $this->connectTenant('acme');

        $claim = ExpenseClaim::findOrFail($claimId);

        app(ApprovalService::class)->approve($claim->approval->fresh(), $managerUser);

        $roleId = $claim->approval->fresh()->currentStepRecord()?->approver_role_id;

        if ($roleId !== null) {
            $finance->roles()->syncWithoutDetaching([(int) $roleId]);
            $finance = $finance->fresh(['roles.permissions']);
        }

        app(ApprovalService::class)->approve($claim->approval->fresh(), $finance);
    }

    private function price(Employee $employee): void
    {
        $this->connectTenant('acme');

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();

        $structure = app(CompensationService::class)->createStructure(
            ['name' => 'Expense Http 6L', 'currency' => 'INR', 'effective_from' => '2026-04-01'],
            [
                ['component_id' => $basic->id, 'value' => 25000, 'sequence' => 10],
                ['component_id' => $hra->id, 'value' => 50, 'sequence' => 20],
            ],
        );

        app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');
    }

    private function openRun(): PayrollRun
    {
        $this->connectTenant('acme');

        return app(PayrollService::class)->openRun([
            'period_year' => 2026,
            'period_month' => 8,
            'pay_period_start' => '2026-08-01',
            'pay_period_end' => '2026-08-31',
            'pay_date' => '2026-09-05',
        ]);
    }

    private function makeEmployee(bool $withUser = false): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $userId = $withUser ? User::create([
            'name' => "Expense Http Owner {$sequence}",
            'email' => "expense.http.owner.{$sequence}@flowsync.test",
            'password' => 'password',
        ])->id : null;

        return Employee::create([
            'employee_code' => 'EMP-EXPH-'.$sequence,
            'name' => "Expense Http Employee {$sequence}",
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
                'name' => "Expense Http User {$sequence}",
                'email' => "expense.http.user.{$sequence}@flowsync.test",
                'password' => 'password',
            ]);
        }

        $role = Role::create([
            'name' => "Expense Http Role {$sequence}",
            'slug' => "expense-http-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
