<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Support\Carbon;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P6.4c — leave asks over HTTP.
 *
 * Filing and reading one's own asks is self-service; the queue and the
 * verdicts belong to the chain. Updates edit notes only (re-dating is
 * cancel-and-refile, enforced by name), and whole-number totals lose their
 * fraction in JSON, so assertions cast back before comparing strictly.
 */
class HrmsLeaveRequestApiTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.leave']);
    }

    public function test_an_employee_files_and_lists_their_own_asks(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Filer', ['user_id' => $user->id]);
        $this->actAs($user);

        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        LeaveAdjustment::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => 2026,
            'kind' => 'opening',
            'quantity' => 12,
            'created_at' => now(),
        ]);

        $day = $this->daysAgo(9);

        $body = $this->postJson('/api/hrms/leave/requests', [
            'leave_type_id' => $type->id,
            'from_date' => $day,
            'to_date' => Carbon::parse($day)->addDay()->toDateString(),
            'reason' => 'Two days off.',
        ])->assertCreated()->json();

        $this->assertSame('submitted', $body['request']['status']);
        $this->assertSame(2.0, (float) $body['request']['total_days']);
        $this->assertCount(2, $body['request']['days']);

        $list = $this->getJson('/api/hrms/leave/requests')->assertOk()->json('requests');

        $this->assertCount(1, $list);
        $this->assertSame($body['request']['id'], $list[0]['id']);
    }

    public function test_the_queue_scopes_to_self_unless_viewing(): void
    {
        $reader = $this->userWith(['hrms.view']);
        $this->makeEmployee('Reader', ['user_id' => $reader->id]);
        $other = $this->userWith(['hrms.view']);
        $otherEmployee = $this->makeEmployee('Other', ['user_id' => $other->id]);
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $this->ledger($otherEmployee, $type, 12);

        $this->actAs($other);
        $otherAsk = $this->postJson('/api/hrms/leave/requests', [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(9),
            'to_date' => $this->daysAgo(8),
            'reason' => 'Mine.',
        ])->assertCreated()->json('request.id');

        $this->actAs($reader);
        $this->assertSame([], $this->getJson('/api/hrms/leave/requests')->assertOk()->json('requests'));
        $this->getJson("/api/hrms/leave/requests/{$otherAsk}")->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.leave.view']));
        $mine = $this->getJson('/api/hrms/leave/requests')->assertOk()->json('requests');
        $this->assertSame([$otherAsk], array_column($mine, 'id'));
    }

    public function test_filing_validates_shape_and_balance(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Validator', ['user_id' => $user->id]);
        $this->actAs($user);

        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();

        $this->postJson('/api/hrms/leave/requests', [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(9),
            'to_date' => $this->daysAgo(8),
            'from_half' => 'sideways',
            'reason' => 'Bad half.',
        ])->assertUnprocessable()->assertJsonValidationErrors('from_half');

        // No balance banked: the shortfall names the number.
        $this->postJson('/api/hrms/leave/requests', [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(9),
            'to_date' => $this->daysAgo(8),
            'reason' => 'Unfunded.',
        ])->assertUnprocessable()->assertJsonValidationErrors('form');
    }

    public function test_updates_edit_notes_while_dates_are_rejected_by_name(): void
    {
        [$manager, $report] = $this->reportingLine();
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $this->ledger($report, $type, 12);
        $this->actAs($report->user);

        $id = $this->postJson('/api/hrms/leave/requests', [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(9),
            'to_date' => $this->daysAgo(8),
            'reason' => 'Original reason.',
        ])->assertCreated()->json('request.id');

        $this->putJson("/api/hrms/leave/requests/{$id}", ['reason' => 'Better reason.'])
            ->assertOk()->assertJsonPath('request.reason', 'Better reason.');

        $this->putJson("/api/hrms/leave/requests/{$id}", [
            'reason' => 'Trying to move it.',
            'from_date' => $this->daysAgo(20),
        ])->assertUnprocessable()->assertJsonValidationErrors('from_date');
    }

    public function test_the_chain_decides_over_http_with_step_403s(): void
    {
        [$manager, $report] = $this->reportingLine();
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $this->ledger($report, $type, 12);
        $this->actAs($report->user);
        $day = $this->daysAgo(6);

        $id = $this->postJson('/api/hrms/leave/requests', [
            'leave_type_id' => $type->id,
            'from_date' => $day,
            'to_date' => $day,
            'reason' => 'One day.',
        ])->assertCreated()->json('request.id');

        // A permission holder who is not on the chain gets a 403, not a say.
        $this->actAs($this->userWith(['hrms.view', 'hrms.leave.approve']));
        $this->postJson("/api/hrms/leave/requests/{$id}/approve")->assertForbidden();

        // Manager, manager-again for the fallback seat, then HR.
        $this->actAs($manager->user);
        $this->postJson("/api/hrms/leave/requests/{$id}/approve", ['note' => 'Fine.'])
            ->assertOk()->assertJsonPath('request.status', 'submitted');
        $this->postJson("/api/hrms/leave/requests/{$id}/approve", ['note' => 'Still fine.'])->assertOk();

        $this->actAs($this->hrUser());
        $body = $this->postJson("/api/hrms/leave/requests/{$id}/approve", ['note' => 'HR clear.'])
            ->assertOk()->json();

        $this->assertSame('approved', $body['request']['status']);
        $this->assertSame('Leave request approved.', $body['message']);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'leave.approved')->exists());
    }

    public function test_availability_prices_without_judging(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Previewer', ['user_id' => $user->id]);
        $this->actAs($user);

        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $this->ledger($employee, $type, 5);

        // Funded: totals, availability, no shortfall.
        $body = $this->getJson('/api/hrms/leave/requests/availability?'.http_build_query([
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(9),
            'to_date' => $this->daysAgo(8),
        ]))->assertOk()->json();

        $this->assertSame(2.0, (float) $body['total_days']);
        $this->assertSame(5.0, (float) $body['available_days']);
        $this->assertSame(0.0, (float) $body['shortfall_days']);

        // Unfunded: still 200 — a preview that 422s on an empty balance
        // could never warn about it.
        $poor = $this->makeEmployee('Broke Previewer');
        $poorUser = $this->makeUser();
        $poor->update(['user_id' => $poorUser->id]);
        $this->actAs($poorUser);

        $short = $this->getJson('/api/hrms/leave/requests/availability?'.http_build_query([
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(9),
            'to_date' => $this->daysAgo(8),
        ]))->assertOk()->json();

        $this->assertSame(2.0, (float) $short['total_days']);
        $this->assertSame(0.0, (float) $short['available_days']);
        $this->assertSame(2.0, (float) $short['shortfall_days']);
    }

    public function test_the_team_calendar_shows_reports_and_self(): void
    {
        [$manager, $report] = $this->reportingLine();
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $this->ledger($report, $type, 12);
        $this->ledger($manager, $type, 12);
        $day = $this->daysAgo(6);

        $this->actAs($report->user);
        $this->postJson('/api/hrms/leave/requests', [
            'leave_type_id' => $type->id,
            'from_date' => $day,
            'to_date' => $day,
            'reason' => 'Mine.',
        ])->assertCreated();

        // A pending ask is not cover yet: the calendar shows approved only.
        $this->actAs($manager->user);
        $empty = $this->getJson('/api/hrms/leave/requests/calendar')->assertOk()->json();
        $this->assertSame([], $empty['days']);

        $id = LeaveRequest::query()
            ->where('employee_id', $report->id)
            ->value('id');

        $this->approveFully($id, $manager->user);

        $full = $this->getJson('/api/hrms/leave/requests/calendar')->assertOk()->json();

        $this->assertSame([$report->name], array_column($full['days'][$day], 'employee_name'));
    }

    public function test_rejection_and_cancellation_flows(): void
    {
        [$manager, $report] = $this->reportingLine();
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $this->ledger($report, $type, 12);
        $this->actAs($report->user);

        $rejected = $this->postJson('/api/hrms/leave/requests', [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(12),
            'to_date' => $this->daysAgo(11),
            'reason' => 'Will be refused.',
        ])->assertCreated()->json('request.id');

        $this->actAs($manager->user);
        $this->postJson("/api/hrms/leave/requests/{$rejected}/reject")
            ->assertUnprocessable()->assertJsonValidationErrors('decision_note');

        $this->postJson("/api/hrms/leave/requests/{$rejected}/reject", ['note' => 'Blackout.'])
            ->assertOk()->assertJsonPath('request.status', 'rejected');

        $this->actAs($report->user);
        $withdrawn = $this->postJson('/api/hrms/leave/requests', [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(9),
            'to_date' => $this->daysAgo(8),
            'reason' => 'Changed my mind.',
        ])->assertCreated()->json('request.id');

        $this->deleteJson("/api/hrms/leave/requests/{$withdrawn}")
            ->assertOk()->assertJsonPath('request.status', 'cancelled');

        $this->postJson("/api/hrms/leave/requests/{$withdrawn}/cancel", ['reason' => 'Again.'])
            ->assertUnprocessable()->assertJsonValidationErrors('form');
    }

    // ------------------------------------------------------------ helpers

    /**
     * A manager with a login and one report with a login.
     *
     * @return array{Employee, Employee} Each carrying `->user`.
     */
    private function reportingLine(): array
    {
        $managerUser = $this->makeUser();
        $manager = $this->makeEmployee('Chain Manager', ['user_id' => $managerUser->id]);
        $manager->user = $managerUser;

        $reportUser = $this->makeUser();
        $report = $this->makeEmployee('Chain Report', ['user_id' => $reportUser->id, 'manager_id' => $manager->id]);
        $report->user = $reportUser;

        return [$manager, $report];
    }

    private function daysAgo(int $days): string
    {
        return Carbon::today()->subDays($days)->toDateString();
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    private function hrUser(): User
    {
        $role = Role::query()->where('slug', 'hr_manager')->firstOrFail();
        $user = $this->makeUser();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    /**
     * Walk the routed chain over HTTP: manager, manager-again for the
     * fallback seat, then HR. Leaves the session on the manager.
     */
    private function approveFully(int $requestId, User $manager): void
    {
        $this->actAs($manager);
        $this->postJson("/api/hrms/leave/requests/{$requestId}/approve")->assertOk();
        $this->postJson("/api/hrms/leave/requests/{$requestId}/approve")->assertOk();

        $this->actAs($this->hrUser());
        $this->postJson("/api/hrms/leave/requests/{$requestId}/approve")->assertOk();

        $this->actAs($manager);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;

        $user = User::create([
            'name' => "Ask User {$sequence}",
            'email' => "ask.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Ask Role {$sequence}",
            'slug' => "ask-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    private function makeUser(): User
    {
        static $sequence = 0;

        $sequence++;

        return User::create([
            'name' => "Ask Login {$sequence}",
            'email' => "ask.login.{$sequence}@flowsync.test",
            'password' => 'password',
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
            'employee_code' => 'EMP-LVQ-'.$sequence,
            'name' => $name,
            'status' => EmployeeStatus::Active,
            ...$overrides,
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

    private function setAcmeModules(array $modules): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }
}
