<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\FeedbackRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase C step 11 — spoofed `employee_id` query params can never widen what a
 * caller may see.
 *
 * The employee-keyed reads (attendance month/today, leave balances, comp-off
 * credits) and the performance lists accept an explicit `employee_id` for
 * deep-linking into another person's record. Every one of them resolves it
 * against `HrmsScope` server-side: an `_own` holder who passes somebody
 * else's id gets a policy 403 (or an empty list) rather than that person's
 * rows; an `_assigned` holder reads own plus direct reports only; the legacy
 * slug and `manage` still read everyone; and a login with no employment
 * record reads nothing — passing another employee's id is not an identity
 * claim.
 */
class HrmsScopeEmployeeIdTest extends TestCase
{
    use IsolatesDatabase;

    public function test_an_own_attendance_viewer_cannot_widen_with_a_spoofed_employee_id(): void
    {
        $this->setAcmeModules(['hrms.attendance']);

        $holder = $this->userWith(['hrms.attendance.view_own']);
        $own = $this->makeEmployee('Attendance Own', ['user_id' => $holder->id]);
        $report = $this->reportFor($own);
        $stranger = $this->makeEmployee('Attendance Stranger');

        $this->actAs($holder);

        $this->getJson('/api/hrms/attendance/month?employee_id='.$own->id)->assertOk();
        $this->getJson('/api/hrms/attendance/month?employee_id='.$report->id)->assertForbidden();
        $this->getJson('/api/hrms/attendance/month?employee_id='.$stranger->id)->assertForbidden();
        $this->getJson('/api/hrms/attendance/today?employee_id='.$report->id)->assertForbidden();
    }

    public function test_attendance_scope_holders_read_the_rows_the_scope_covers(): void
    {
        $this->setAcmeModules(['hrms.attendance']);

        $manager = $this->userWith(['hrms.attendance.view_own', 'hrms.attendance.view_assigned']);
        $managerEmployee = $this->makeEmployee('Attendance Mgr', ['user_id' => $manager->id]);
        $report = $this->reportFor($managerEmployee);
        $stranger = $this->makeEmployee('Attendance Stranger');

        $this->actAs($manager);

        $this->getJson('/api/hrms/attendance/month?employee_id='.$managerEmployee->id)->assertOk();
        $this->getJson('/api/hrms/attendance/month?employee_id='.$report->id)->assertOk();
        $this->getJson('/api/hrms/attendance/month?employee_id='.$stranger->id)->assertForbidden();

        $this->actAs($this->userWith(['hrms.attendance.view']));
        $this->getJson('/api/hrms/attendance/month?employee_id='.$stranger->id)->assertOk();
    }

    public function test_leave_balances_intersect_the_explicit_employee_id(): void
    {
        $this->setAcmeModules(['hrms.leave']);

        $holder = $this->userWith(['hrms.leave.view_own']);
        $own = $this->makeEmployee('Balance Own', ['user_id' => $holder->id]);
        $report = $this->reportFor($own);
        $stranger = $this->makeEmployee('Balance Stranger');

        $this->actAs($holder);

        $this->getJson('/api/hrms/leave/balances?employee_id='.$own->id)->assertOk();
        $this->getJson('/api/hrms/leave/balances?employee_id='.$report->id)->assertForbidden();
        $this->getJson('/api/hrms/leave/balances?employee_id='.$stranger->id)->assertForbidden();

        $assigned = $this->userWith(['hrms.leave.view_own', 'hrms.leave.view_assigned']);
        $assignedEmployee = $this->makeEmployee('Balance Assigned', ['user_id' => $assigned->id]);
        $myReport = $this->reportFor($assignedEmployee);

        $this->actAs($assigned);
        $this->getJson('/api/hrms/leave/balances?employee_id='.$myReport->id)->assertOk();
        $this->getJson('/api/hrms/leave/balances?employee_id='.$stranger->id)->assertForbidden();
    }

    public function test_comp_off_credits_intersect_the_explicit_employee_id(): void
    {
        $this->setAcmeModules(['hrms.comp_off']);

        $holder = $this->userWith(['hrms.comp_off.view_own']);
        $own = $this->makeEmployee('Credit Own', ['user_id' => $holder->id]);
        $report = $this->reportFor($own);
        $stranger = $this->makeEmployee('Credit Stranger');

        $this->actAs($holder);

        $this->getJson('/api/hrms/comp-off/credits?employee_id='.$own->id)->assertOk();
        $this->getJson('/api/hrms/comp-off/credits?employee_id='.$report->id)->assertForbidden();

        $assigned = $this->userWith(['hrms.comp_off.view_own', 'hrms.comp_off.view_assigned']);
        $assignedEmployee = $this->makeEmployee('Credit Assigned', ['user_id' => $assigned->id]);
        $myReport = $this->reportFor($assignedEmployee);

        $this->actAs($assigned);
        $this->getJson('/api/hrms/comp-off/credits?employee_id='.$myReport->id)->assertOk();
        $this->getJson('/api/hrms/comp-off/credits?employee_id='.$stranger->id)->assertForbidden();

        $this->actAs($this->userWith(['hrms.comp_off.view']));
        $this->getJson('/api/hrms/comp-off/credits?employee_id='.$stranger->id)->assertOk();
    }

    public function test_an_assigned_performance_holder_reads_scope_but_not_the_stranger(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.performance']);

        // The manage actor files the rows; the assigned holder reads them.
        $manager = $this->userWith(['hrms.view', 'hrms.performance.manage']);
        $assigned = $this->userWith(['hrms.view', 'hrms.performance.view_own', 'hrms.performance.view_assigned']);
        $assignedEmployee = $this->makeEmployee('Perf Assigned', ['user_id' => $assigned->id]);
        $report = $this->reportFor($assignedEmployee);
        $stranger = $this->makeEmployee('Perf Stranger');

        $this->actAs($manager);

        $cycle = $this->postJson('/api/hrms/performance/cycles', [
            'name' => 'Step 11 Cycle',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
        ])->assertCreated()->json('cycle');

        $ownGoal = $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/goals", [
            'employee_id' => $assignedEmployee->id, 'title' => 'Mine', 'metric_type' => 'manual', 'weight' => '50',
        ])->assertCreated()->json('goal');
        $reportGoal = $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/goals", [
            'employee_id' => $report->id, 'title' => 'Report', 'metric_type' => 'manual', 'weight' => '50',
        ])->assertCreated()->json('goal');
        $strangerGoal = $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/goals", [
            'employee_id' => $stranger->id, 'title' => 'Stranger', 'metric_type' => 'manual', 'weight' => '50',
        ])->assertCreated()->json('goal');

        $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/check-ins", [
            'employee_id' => $report->id, 'body' => 'Report note.',
        ])->assertCreated();
        $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/check-ins", [
            'employee_id' => $stranger->id, 'body' => 'Stranger note.',
        ])->assertCreated();

        $this->actAs($assigned);

        $ids = $this->getJson("/api/hrms/performance/cycles/{$cycle['id']}/goals")->assertOk()->json('goals.*.id');
        $this->assertContains($ownGoal['id'], $ids, 'An assigned holder must list their own goal.');
        $this->assertContains($reportGoal['id'], $ids, 'An assigned holder must list a direct report goal.');
        $this->assertNotContains($strangerGoal['id'], $ids, 'No stranger goal may leak onto the list.');

        $narrowed = $this->getJson("/api/hrms/performance/cycles/{$cycle['id']}/goals?employee_id={$report->id}")
            ->assertOk()->json('goals');
        $this->assertCount(1, $narrowed, 'The explicit employee_id must narrow, not widen.');
        $this->assertSame($reportGoal['id'], $narrowed[0]['id']);

        $spoofed = $this->getJson("/api/hrms/performance/cycles/{$cycle['id']}/goals?employee_id={$stranger->id}")
            ->assertOk()->json('goals');
        $this->assertSame([], $spoofed, 'A stranger employee_id on an own/assigned list must read empty.');

        // List and show cannot disagree: the scope holder opens the report's
        // goal (it was offered on the list) and the stranger's stays a 403.
        $this->getJson("/api/hrms/performance/goals/{$reportGoal['id']}")->assertOk();
        $this->getJson("/api/hrms/performance/goals/{$strangerGoal['id']}")->assertForbidden();

        $myChecks = $this->getJson("/api/hrms/performance/cycles/{$cycle['id']}/check-ins?employee_id={$report->id}")
            ->assertOk()->json('check_ins');
        $this->assertCount(1, $myChecks, 'The report check-in must survive the narrow filter.');
        $checkSpoof = $this->getJson("/api/hrms/performance/cycles/{$cycle['id']}/check-ins?employee_id={$stranger->id}")
            ->assertOk()->json('check_ins');
        $this->assertSame([], $checkSpoof, 'A stranger employee_id on the check-in list must read empty.');
    }

    public function test_one_on_ones_and_feedback_asks_follow_the_same_scope(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.performance']);

        $manager = $this->userWith(['hrms.view', 'hrms.performance.manage']);
        $assigned = $this->userWith(['hrms.view', 'hrms.performance.view_own', 'hrms.performance.view_assigned']);
        $assignedEmployee = $this->makeEmployee('Call Assigned', ['user_id' => $assigned->id]);
        $report = $this->reportFor($assignedEmployee);
        $stranger = $this->makeEmployee('Call Stranger');
        $otherEmployee = $this->makeEmployee('Call Other'); // a mate outside the scope for the stranger 1:1

        $this->actAs($manager);

        $cycle = $this->postJson('/api/hrms/performance/cycles', [
            'name' => 'Step 11 Calls',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
        ])->assertCreated()->json('cycle');

        $ownCall = $this->postJson('/api/hrms/performance/one-on-ones', [
            'employee_id' => $assignedEmployee->id, 'manager_employee_id' => $report->id, 'scheduled_at' => '2026-02-01 10:00:00',
        ])->assertCreated()->json('one_on_one');
        $reportCall = $this->postJson('/api/hrms/performance/one-on-ones', [
            'employee_id' => $report->id, 'manager_employee_id' => $assignedEmployee->id, 'scheduled_at' => '2026-02-02 10:00:00',
        ])->assertCreated()->json('one_on_one');
        $strangerCall = $this->postJson('/api/hrms/performance/one-on-ones', [
            'employee_id' => $stranger->id, 'manager_employee_id' => $otherEmployee->id, 'scheduled_at' => '2026-02-03 10:00:00',
        ])->assertCreated()->json('one_on_one');

        FeedbackRequest::create([
            'cycle_id' => $cycle['id'],
            'from_employee_id' => $assignedEmployee->id,
            'to_employee_id' => $report->id,
            'relation' => 'manager',
            'status' => 'pending',
        ]);
        FeedbackRequest::create([
            'cycle_id' => $cycle['id'],
            'from_employee_id' => $otherEmployee->id,
            'to_employee_id' => $stranger->id,
            'relation' => 'peer',
            'status' => 'pending',
        ]);

        $this->actAs($assigned);

        $calls = $this->getJson('/api/hrms/performance/one-on-ones')->assertOk()->json('one_on_ones.*.id');
        $this->assertContains($ownCall['id'], $calls, 'A 1:1 the caller sits on is theirs.');
        $this->assertContains($reportCall['id'], $calls, 'An assigned holder must read a report 1:1.');
        $this->assertNotContains($strangerCall['id'], $calls, 'A stranger 1:1 must not leak onto the list.');

        $spoofedCall = $this->getJson('/api/hrms/performance/one-on-ones?employee_id='.$stranger->id)
            ->assertOk()->json('one_on_ones');
        $this->assertSame([], $spoofedCall, 'A stranger employee_id on the 1:1 list must read empty.');
        $this->getJson("/api/hrms/performance/one-on-ones/{$strangerCall['id']}")->assertForbidden();

        $asks = $this->getJson("/api/hrms/performance/cycles/{$cycle['id']}/feedback-requests")
            ->assertOk()->json('feedback_requests.*.id');
        $this->assertCount(1, $asks, 'Only the ask where the report sits must survive the scope clamp.');
        $spoofedAsk = $this->getJson("/api/hrms/performance/cycles/{$cycle['id']}/feedback-requests?employee_id={$stranger->id}")
            ->assertOk()->json('feedback_requests');
        $this->assertSame([], $spoofedAsk, 'A stranger employee_id on the feedback list must read empty.');
    }

    public function test_a_login_with_no_employment_record_reads_nothing(): void
    {
        $this->setAcmeModules(['hrms.attendance']);

        $stranger = $this->makeEmployee('No Record Stranger');

        $this->actAs($this->userWith(['hrms.attendance.view_own']));

        $this->getJson('/api/hrms/attendance/month')->assertNotFound();
        $this->getJson('/api/hrms/attendance/month?employee_id='.$stranger->id)->assertForbidden();
    }

    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;

        $user = User::create([
            'name' => "Step 11 User {$sequence}",
            'email' => 'hrms.scope.employee.'.$sequence.'@flowsync.test',
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Step 11 Role {$sequence}",
            'slug' => 'hrms-scope-employee-role-'.$sequence,
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /**
     * @return Employee A direct report with a login (`user_id` set).
     */
    private function reportFor(Employee $managerEmployee): Employee
    {
        $user = User::create([
            'name' => 'Step 11 Report',
            'email' => 'hrms.scope.report.'.uniqid().'@flowsync.test',
            'password' => 'password',
        ]);

        return $this->makeEmployee('Step 11 Report', [
            'user_id' => $user->id,
            'manager_id' => $managerEmployee->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmployee(string $name, array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-EMP-'.$sequence,
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
