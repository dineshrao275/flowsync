<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Hrms\Org\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrms\Leave\LeaveRequestDecisions;
use App\Services\Hrms\Leave\LeaveRequestService;
use Illuminate\Support\Carbon;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P6.3 — manager, department head, then HR.
 *
 * The chain shape is the contract: a skipped step stays visible as skipped
 * (a missing person), while self-approval is designed out (a head falls
 * back to its manager; a holder of the HR role loses the HR step). The full
 * walk — three humans, three approvals, one posting at the end — proves the
 * engine advances through routed steps rather than stalling on the second.
 */
class HrmsLeaveApprovalRoutingTest extends TestCase
{
    use IsolatesDatabase;

    public function test_the_full_chain_runs_manager_head_then_hr(): void
    {
        $stage = $this->stage();
        $day = $this->daysAgo(6);

        $request = $this->requests()->request($stage['report'], [
            'leave_type_id' => $stage['type']->id,
            'from_date' => $day,
            'to_date' => $day,
            'reason' => 'One day.',
        ], $stage['report']->user);

        $steps = $request->approval->steps()->orderBy('step_order')->get();

        $this->assertCount(3, $steps);
        $this->assertSame('manager', $steps[0]->approver_type->value);
        $this->assertEquals($stage['manager']->user->id, $steps[0]->approver_user_id);
        $this->assertSame('department_head', $steps[1]->approver_type->value);
        $this->assertEquals($stage['head']->id, $steps[1]->approver_employee_id);
        $this->assertSame('role', $steps[2]->approver_type->value);
        $this->assertEquals($stage['hrRole']->id, $steps[2]->approver_role_id);

        $this->decisions()->approve($request->refresh(), $stage['manager']->user, 'Fine.');
        $this->assertSame(2, $request->approval->refresh()->current_step);

        $this->decisions()->approve($request->refresh(), $stage['head']->user, 'Noted.');
        $this->assertSame(3, $request->approval->refresh()->current_step);

        $decided = $this->decisions()->approve($request->refresh(), $stage['hr'], 'HR clear.');

        $this->assertSame('approved', $decided->status->value);
        $this->assertSame('approved', $decided->approval->refresh()->status->value);
        $this->assertSame(-1.0, (float) LeaveAdjustment::query()
            ->where('kind', 'availed')
            ->firstOrFail()
            ->quantity);
    }

    public function test_no_department_falls_back_to_the_manager(): void
    {
        $managerUser = $this->makeUser();
        $manager = $this->makeEmployee('Fallback Manager', ['user_id' => $managerUser->id]);
        $reportUser = $this->makeUser();
        $report = $this->makeEmployee('Fallback Report', ['user_id' => $reportUser->id, 'manager_id' => $manager->id]);
        $type = $this->makeType();
        $this->ledger($report, $type, 12);

        $request = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(6),
            'to_date' => $this->daysAgo(6),
            'reason' => 'One day.',
        ]);

        $steps = $request->approval->steps()->orderBy('step_order')->get();

        $this->assertCount(3, $steps);
        $this->assertSame('department_head', $steps[1]->approver_type->value);
        $this->assertEquals($manager->id, $steps[1]->approver_employee_id);
    }

    public function test_no_manager_skips_the_first_step_and_continues(): void
    {
        $headUser = $this->makeUser();
        $head = $this->makeEmployee('Lone Head', ['user_id' => $headUser->id]);
        $dept = $this->makeDepartment($head);
        $reportUser = $this->makeUser();
        $report = $this->makeEmployee('Lone Report', ['user_id' => $reportUser->id, 'department_id' => $dept->id]);
        $type = $this->makeType();
        $this->ledger($report, $type, 12);

        $request = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(6),
            'to_date' => $this->daysAgo(6),
            'reason' => 'One day.',
        ]);

        $steps = $request->approval->steps()->orderBy('step_order')->get();

        // A step nobody can act on is skipped, never left pending — the flow
        // continues at the head instead of deadlocking on a missing manager.
        $this->assertSame('skipped', $steps[0]->status->value);
        $this->assertSame('pending', $steps[1]->status->value);
        $this->assertSame(2, $request->approval->refresh()->current_step);
    }

    public function test_a_head_requesting_falls_back_to_its_manager(): void
    {
        $stage = $this->stage();
        $type = $this->makeType();
        $this->ledger($stage['head'], $type, 12);

        $request = $this->requests()->request($stage['head'], [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(6),
            'to_date' => $this->daysAgo(6),
            'reason' => 'The head needs a day too.',
        ], $stage['head']->user);

        $steps = $request->approval->steps()->orderBy('step_order')->get();

        // Nobody approves their own leave: the department step names the
        // head's manager, not the head.
        $this->assertSame('department_head', $steps[1]->approver_type->value);
        $this->assertEquals($stage['manager']->id, $steps[1]->approver_employee_id);
    }

    public function test_a_holder_of_the_hr_role_loses_the_hr_step(): void
    {
        $hrRole = Role::query()->where('slug', 'hr_manager')->firstOrFail();
        $managerUser = $this->makeUser();
        $manager = $this->makeEmployee('HR Manager', ['user_id' => $managerUser->id]);
        $reportUser = $this->makeUser();
        $reportUser->roles()->sync([$hrRole->id]);
        $report = $this->makeEmployee('HR Report', ['user_id' => $reportUser->id, 'manager_id' => $manager->id]);
        $type = $this->makeType();
        $this->ledger($report, $type, 12);

        $request = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(6),
            'to_date' => $this->daysAgo(6),
            'reason' => 'One day.',
        ]);

        // The engine cannot exclude one holder from a role step, so the step
        // is omitted rather than self-approved: manager, then the fallback.
        $steps = $request->approval->steps()->orderBy('step_order')->get();

        $this->assertCount(2, $steps);
        $this->assertSame('manager', $steps[0]->approver_type->value);
        $this->assertSame('department_head', $steps[1]->approver_type->value);

        // And the manager alone resolves it — twice, once per step, since
        // the fallback names them for the department seat too.
        $decided = $this->decisions()->approve($request->refresh(), $managerUser);
        $decided = $this->decisions()->approve($decided->refresh(), $managerUser);

        $this->assertSame('approved', $decided->status->value);
    }

    public function test_a_missing_hr_role_skips_visibly(): void
    {
        Role::query()->where('slug', 'hr_manager')->delete();

        $managerUser = $this->makeUser();
        $manager = $this->makeEmployee('No-HR Manager', ['user_id' => $managerUser->id]);
        $reportUser = $this->makeUser();
        $report = $this->makeEmployee('No-HR Report', ['user_id' => $reportUser->id, 'manager_id' => $manager->id]);
        $type = $this->makeType();
        $this->ledger($report, $type, 12);

        $request = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(6),
            'to_date' => $this->daysAgo(6),
            'reason' => 'One day.',
        ]);

        $steps = $request->approval->steps()->orderBy('step_order')->get();

        $this->assertCount(3, $steps);
        $this->assertSame('skipped', $steps[2]->status->value);

        // Manager, then manager-again for the fallback seat; the skipped HR
        // step resolves the chain without a third human.
        $this->decisions()->approve($request->refresh(), $managerUser);
        $decided = $this->decisions()->approve($request->refresh(), $managerUser);

        $this->assertSame('approved', $decided->status->value);
    }

    public function test_a_head_with_no_login_skips_the_department_step(): void
    {
        $managerUser = $this->makeUser();
        $manager = $this->makeEmployee('Loginless Manager', ['user_id' => $managerUser->id]);
        $head = $this->makeEmployee('Loginless Head');
        $dept = $this->makeDepartment($head);
        $reportUser = $this->makeUser();
        $report = $this->makeEmployee('Loginless Report', [
            'user_id' => $reportUser->id,
            'manager_id' => $manager->id,
            'department_id' => $dept->id,
        ]);
        $type = $this->makeType();
        $this->ledger($report, $type, 12);

        $request = $this->requests()->request($report, [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(6),
            'to_date' => $this->daysAgo(6),
            'reason' => 'One day.',
        ]);

        $steps = $request->approval->steps()->orderBy('step_order')->get();

        // A head with no login cannot act: the step skips, the manager and
        // HR carry the chain.
        $this->assertSame('skipped', $steps[1]->status->value);

        $this->decisions()->approve($request->refresh(), $managerUser);
        $this->assertSame(3, $request->approval->refresh()->current_step);
    }

    // ------------------------------------------------------------ helpers

    /**
     * Manager, head-led department, report in it, and an HR-holder.
     *
     * @return array{manager: Employee, head: Employee, report: Employee, hr: User, hrRole: Role, type: LeaveType}
     */
    private function stage(): array
    {
        $managerUser = $this->makeUser();
        $manager = $this->makeEmployee('Stage Manager', ['user_id' => $managerUser->id]);
        $manager->user = $managerUser;

        $headUser = $this->makeUser();
        $head = $this->makeEmployee('Stage Head', ['user_id' => $headUser->id]);

        $dept = $this->makeDepartment($head);

        // The head sits in its own department and reports upward like anyone
        // else — both facts the fallback rules read. Assigned through the
        // query so the `->user` convenience below never becomes a column.
        Employee::query()->whereKey($head->id)->update(['department_id' => $dept->id, 'manager_id' => $manager->id]);
        $head = $head->refresh();
        $head->user = $headUser;

        $reportUser = $this->makeUser();
        $report = $this->makeEmployee('Stage Report', [
            'user_id' => $reportUser->id,
            'manager_id' => $manager->id,
            'department_id' => $dept->id,
        ]);
        $report->user = $reportUser;

        $hrRole = Role::query()->where('slug', 'hr_manager')->firstOrFail();
        $hrUser = $this->makeUser();
        $hrUser->roles()->sync([$hrRole->id]);

        $type = $this->makeType();
        $this->ledger($report, $type, 12);

        return [
            'manager' => $manager,
            'head' => $head,
            'report' => $report,
            'hr' => $hrUser,
            'hrRole' => $hrRole,
            'type' => $type,
        ];
    }

    private function requests(): LeaveRequestService
    {
        return app(LeaveRequestService::class);
    }

    private function decisions(): LeaveRequestDecisions
    {
        return app(LeaveRequestDecisions::class);
    }

    private function daysAgo(int $days): string
    {
        return Carbon::today()->subDays($days)->toDateString();
    }

    private function makeUser(): User
    {
        static $sequence = 0;

        $sequence++;

        return User::create([
            'name' => "Routing User {$sequence}",
            'email' => "routing.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);
    }

    private function makeEmployee(string $name, array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-LVR-'.$sequence,
            'name' => $name,
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeType(array $overrides = []): LeaveType
    {
        static $sequence = 0;

        $sequence++;

        return LeaveType::create([
            'name' => "Routing Type {$sequence}",
            'slug' => "routing-type-{$sequence}",
            ...$overrides,
        ]);
    }

    private function makeDepartment(Employee $head): Department
    {
        static $sequence = 0;

        $sequence++;

        return Department::create([
            'name' => "Routing Department {$sequence}",
            'code' => "routing-dept-{$sequence}",
            'slug' => "routing-dept-{$sequence}",
            'head_employee_id' => $head->id,
        ]);
    }

    private function ledger(Employee $employee, LeaveType $type, float $quantity): void
    {
        LeaveAdjustment::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => 2026,
            'kind' => 'opening',
            'quantity' => $quantity,
            'created_at' => now(),
        ]);
    }
}
