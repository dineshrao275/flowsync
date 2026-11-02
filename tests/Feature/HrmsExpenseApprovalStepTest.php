<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * FU-1 — an expense claim's approval chain is completed over HTTP.
 *
 * Before these routes only tests could move an approval step, so every claim from an employee with a
 * manager stayed `submitted` forever. Authorization is the engine's own answer (ApprovalService::canAct):
 * the chain's approver, their delegate, or the audited override — never a silent admin bypass, never
 * the claim's owner.
 */
class HrmsExpenseApprovalStepTest extends TestCase
{
    use IsolatesDatabase;

    public function test_the_chain_is_cleared_leg_by_leg_over_http_and_the_claim_is_then_decided(): void
    {
        [$manager, $employee, $finance, $claimId] = $this->submittedClaim();

        $this->actAs($manager->user);
        $this->postJson("/api/hrms/expenses/claims/{$claimId}/approve-step", [])->assertOk()
            ->assertJsonPath('claim.status', 'submitted');

        // Still open: the finance leg is pending, so the money decision is refused.
        $this->actAs($finance);
        $this->postJson("/api/hrms/expenses/claims/{$claimId}/decide", ['verdict' => 'approve'])->assertStatus(422);

        $this->postJson("/api/hrms/expenses/claims/{$claimId}/approve-step", [])->assertOk();
        $this->postJson("/api/hrms/expenses/claims/{$claimId}/decide", ['verdict' => 'approve'])
            ->assertOk()->assertJsonPath('claim.status', 'approved');

        $this->assertDatabaseHas('hrms_audit_logs', ['action' => 'approval.approved'], $this->tenantConnection());
    }

    public function test_clearing_a_step_nudges_whoever_must_act_next(): void
    {
        [$manager, , $finance, $claimId] = $this->submittedClaim();

        $this->actAs($manager->user);
        $this->postJson("/api/hrms/expenses/claims/{$claimId}/approve-step", [])->assertOk();

        // The finance leg is now current; submitting only nudged the manager's leg.
        $this->assertTrue(
            UserNotification::query()->where('user_id', $finance->id)->where('type', 'hrms.expense.submitted')->exists(),
            'The next approver is told the claim is waiting on them.',
        );
    }

    public function test_someone_who_is_not_the_approver_is_refused(): void
    {
        [, , , $claimId] = $this->submittedClaim();

        $this->actAs($this->userWith(['hrms.view', 'hrms.expenses.view']));

        $this->postJson("/api/hrms/expenses/claims/{$claimId}/approve-step", [])->assertForbidden();
        $this->postJson("/api/hrms/expenses/claims/{$claimId}/reject-step", ['note' => 'Not mine to reject.'])->assertForbidden();
    }

    public function test_the_claim_owner_can_never_move_their_own_step(): void
    {
        [, $employee, , $claimId] = $this->submittedClaim();

        $this->actAs($employee->user);

        $this->postJson("/api/hrms/expenses/claims/{$claimId}/approve-step", [])->assertForbidden();
    }

    public function test_a_rejection_needs_a_reason_and_closes_the_claim(): void
    {
        [$manager, , , $claimId] = $this->submittedClaim();
        $this->actAs($manager->user);

        $this->postJson("/api/hrms/expenses/claims/{$claimId}/reject-step", [])->assertStatus(422)
            ->assertJsonValidationErrors('note');
        $this->assertSame('submitted', ExpenseClaim::findOrFail($claimId)->status->value);

        $this->postJson("/api/hrms/expenses/claims/{$claimId}/reject-step", ['note' => 'Over the policy limit.'])
            ->assertOk()->assertJsonPath('claim.status', 'rejected');

        $this->assertSame('rejected', ExpenseClaim::findOrFail($claimId)->approval->status->value);
        $this->assertDatabaseHas('hrms_audit_logs', ['action' => 'approval.rejected'], $this->tenantConnection());
    }

    public function test_acting_for_the_assigned_approver_needs_the_override_and_a_reason(): void
    {
        [, , , $claimId] = $this->submittedClaim();

        $role = Role::create(['name' => 'Expense Override', 'slug' => 'expense-override']);
        $role->permissions()->sync(Permission::whereIn('slug', ['hrms.view', 'hrms.approvals.override'])->pluck('id')->all());
        $admin = User::create(['name' => 'Override Admin', 'email' => 'override.admin@flowsync.test', 'password' => 'password']);
        $admin->roles()->sync([$role->id]);
        $this->actAs($admin->fresh(['roles.permissions']));

        $this->postJson("/api/hrms/expenses/claims/{$claimId}/approve-step", [])->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->postJson("/api/hrms/expenses/claims/{$claimId}/approve-step", ['note' => 'Manager is on leave.'])->assertOk();

        $this->assertDatabaseHas('hrms_audit_logs', ['action' => 'approval.override_approved'], $this->tenantConnection());
    }

    public function test_a_claim_that_is_no_longer_submitted_has_no_step_to_move(): void
    {
        [$manager, , , $claimId] = $this->submittedClaim();
        ExpenseClaim::findOrFail($claimId)->update(['status' => 'approved']);

        $this->actAs($manager->user);

        $this->postJson("/api/hrms/expenses/claims/{$claimId}/approve-step", [])->assertForbidden();
    }

    public function test_the_list_and_detail_say_who_can_clear_a_step(): void
    {
        [$manager, $employee, , $claimId] = $this->submittedClaim();

        $this->actAs($manager->user);
        $listed = collect($this->getJson('/api/hrms/expenses/claims')->assertOk()->json('claims'))->firstWhere('id', $claimId);
        $this->assertTrue($listed['can_act_step']);
        $this->getJson("/api/hrms/expenses/claims/{$claimId}")->assertOk()->assertJsonPath('claim.can_act_step', true);

        // The claimant sees their own claim but is never the one to clear its step.
        $this->actAs($employee->user);
        $listed = collect($this->getJson('/api/hrms/expenses/claims')->assertOk()->json('claims'))->firstWhere('id', $claimId);
        $this->assertFalse($listed['can_act_step']);
        $this->getJson("/api/hrms/expenses/claims/{$claimId}")->assertOk()->assertJsonPath('claim.can_act_step', false);
    }

    // ------------------------------------------------------------ helpers

    private function tenantConnection(): string
    {
        return (new HrmsAuditLog)->getConnectionName() ?? config('database.default');
    }

    /**
     * @return array{Employee, Employee, User, int} manager, claimant, finance approver, submitted claim id
     */
    private function submittedClaim(): array
    {
        $this->connectTenant('acme');

        $managerUser = User::create(['name' => 'Step Manager', 'email' => 'step.manager@flowsync.test', 'password' => 'password']);
        $manager = Employee::create([
            'employee_code' => 'EMP-STEP-MGR', 'name' => 'Step Manager',
            'status' => EmployeeStatus::Active, 'user_id' => $managerUser->id,
        ]);
        $manager->user = $managerUser;

        $reportUser = User::create(['name' => 'Step Report', 'email' => 'step.report@flowsync.test', 'password' => 'password']);
        $report = Employee::create([
            'employee_code' => 'EMP-STEP-REP', 'name' => 'Step Report',
            'status' => EmployeeStatus::Active, 'user_id' => $reportUser->id, 'manager_id' => $manager->id,
        ]);
        $report->user = $reportUser;

        $financeRole = Role::create(['name' => 'Step Finance', 'slug' => 'step-finance']);
        $financeRole->permissions()->sync(
            Permission::whereIn('slug', ['hrms.view', 'hrms.expenses.view', 'hrms.expenses.approve'])->pluck('id')->all(),
        );
        $finance = User::create(['name' => 'Step Finance', 'email' => 'step.finance@flowsync.test', 'password' => 'password']);
        $finance->roles()->sync([$financeRole->id]);

        $ownerRole = Role::create(['name' => 'Step Owner', 'slug' => 'step-owner']);
        $ownerRole->permissions()->sync(Permission::whereIn('slug', ['hrms.view', 'hrms.expenses.approve'])->pluck('id')->all());
        $reportUser->roles()->sync([$ownerRole->id]);

        // A real manager is an employee, and the Employee role carries `hrms.view` (the HRMS door).
        $viewRole = Role::create(['name' => 'Step Viewer', 'slug' => 'step-viewer']);
        $viewRole->permissions()->sync(Permission::whereIn('slug', ['hrms.view', 'hrms.expenses.view_assigned'])->pluck('id')->all());
        $managerUser->roles()->sync([$viewRole->id]);

        $report->user = $reportUser->fresh(['roles.permissions']);
        $manager->user = $managerUser->fresh(['roles.permissions']);

        $this->actAs($report->user);
        $claim = $this->postJson('/api/hrms/expenses/claims', [
            'employee_id' => $report->id, 'claim_date' => '2026-08-10', 'period_year' => 2026, 'period_month' => 8,
            'purpose' => 'Step test.', 'items' => [['description' => 'Train.', 'amount' => '120']],
        ])->assertCreated()->json('claim');
        $this->postJson("/api/hrms/expenses/claims/{$claim['id']}/submit", [])->assertOk();

        // The finance leg resolves to whichever role the chain named, so attach finance to it.
        $roleId = ExpenseClaim::findOrFail($claim['id'])->approval->steps()->orderBy('step_order')->get()
            ->pluck('approver_role_id')->filter()->first();
        if ($roleId !== null) {
            $finance->roles()->syncWithoutDetaching([(int) $roleId]);
        }

        return [$manager, $report, $finance->fresh(['roles.permissions']), (int) $claim['id']];
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
        $this->connectTenant('acme');
        $role = Role::create(['name' => 'Step Plain', 'slug' => 'step-plain']);
        $role->permissions()->sync(Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all());
        $user = User::create(['name' => 'Step Plain', 'email' => 'step.plain@flowsync.test', 'password' => 'password']);
        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
