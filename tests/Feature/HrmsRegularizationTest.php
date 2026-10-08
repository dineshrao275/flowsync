<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Attendance\AttendancePunch;
use App\Models\Hrms\Attendance\AttendanceRegularizationRequest;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\SubscriptionService;
use Illuminate\Support\Carbon;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.4 — correction asks through the shared approval engine.
 *
 * What is worth protecting here is the part neither the engine nor the day
 * math can see: the window (how far back, never the future), the same-date
 * rule for corrected times, the manager step resolving from the reporting
 * line, and the fact that approval inserts superseding punches rather than
 * editing the day. Deciding belongs to the step's approver — a manager with
 * no attendance permission decides their reports' asks, and a stranger gets
 * a 403 even with one.
 */
class HrmsRegularizationTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.attendance', 'hrms.attendance.remote']);
    }

    public function test_a_request_opens_a_manager_step_and_nudges_the_manager(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->actAs($report->user);
        $date = $this->daysAgo(2);

        $body = $this->postJson('/api/hrms/attendance/regularizations', [
            'work_date' => $date,
            'requested_first_in_at' => "{$date} 09:05:00",
            'reason' => 'Forgot to clock in at the gate.',
        ])->assertCreated()->json();

        $this->assertSame('pending', $body['request']['status']);

        $row = AttendanceRegularizationRequest::findOrFail($body['request']['id']);

        $this->assertEquals($manager->id, $row->approval->steps()->firstWhere('step_order', 1)->approver_employee_id);
        $this->assertEquals($manager->user->id, $row->approval->steps()->firstWhere('step_order', 1)->approver_user_id);

        $this->assertTrue(UserNotification::query()
            ->where('user_id', $manager->user->id)
            ->where('type', 'hrms.attendance.regularization.requested')
            ->exists());

        $this->assertTrue(HrmsAuditLog::query()->where('action', 'attendance.regularization.requested')->exists());
    }

    public function test_an_ask_with_no_manager_applies_itself_and_stays_audited(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Solo', ['user_id' => $user->id]);
        $this->rosterFor($employee);
        $this->actAs($user);
        $date = $this->daysAgo(2);

        $body = $this->postJson('/api/hrms/attendance/regularizations', [
            'work_date' => $date,
            'requested_first_in_at' => "{$date} 09:00:00",
            'requested_punch_at' => "{$date} 18:00:00",
            'reason' => 'The kiosk was down all morning.',
        ])->assertCreated()->json();

        // Nobody to review means the engine auto-approves; the day must show
        // the correction in the same request, not sit approved-but-unchanged.
        $this->assertSame('approved', $body['request']['status']);

        $day = AttendanceDay::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->firstOrFail();

        $this->assertTrue($day->is_regularized);
        $this->assertSame(480, $day->worked_minutes);
        $this->assertSame(2, AttendancePunch::query()->where('employee_id', $employee->id)->count());
    }

    public function test_the_window_rejects_old_and_future_dates(): void
    {
        $user = $this->userWith(['hrms.view']);
        $this->makeEmployee('Punctual', ['user_id' => $user->id]);
        $this->actAs($user);

        $this->postJson('/api/hrms/attendance/regularizations', [
            'work_date' => $this->daysAgo(30),
            'requested_first_in_at' => $this->daysAgo(30).' 09:00:00',
            'reason' => 'Too late to remember honestly.',
        ])->assertUnprocessable()->assertJsonValidationErrors('work_date');

        $this->postJson('/api/hrms/attendance/regularizations', [
            'work_date' => today()->addDay()->toDateString(),
            'requested_first_in_at' => today()->addDay()->toDateString().' 09:00:00',
            'reason' => 'Premonition.',
        ])->assertUnprocessable()->assertJsonValidationErrors('work_date');
    }

    public function test_a_corrected_time_must_fall_on_the_date_being_corrected(): void
    {
        $user = $this->userWith(['hrms.view']);
        $this->makeEmployee('Wanderer', ['user_id' => $user->id]);
        $this->actAs($user);

        $this->postJson('/api/hrms/attendance/regularizations', [
            'work_date' => $this->daysAgo(2),
            'requested_first_in_at' => $this->daysAgo(3).' 09:00:00',
            'reason' => 'Monday’s punch for a Tuesday row.',
        ])->assertUnprocessable()->assertJsonValidationErrors('requested_first_in_at');
    }

    public function test_an_ask_needs_at_least_one_corrected_time(): void
    {
        $user = $this->userWith(['hrms.view']);
        $this->makeEmployee('Vague', ['user_id' => $user->id]);
        $this->actAs($user);

        $this->postJson('/api/hrms/attendance/regularizations', [
            'work_date' => $this->daysAgo(2),
            'reason' => 'Something was wrong, unspecified.',
        ])->assertUnprocessable()->assertJsonValidationErrors('form');
    }

    public function test_a_second_pending_ask_for_the_same_date_is_rejected(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->actAs($report->user);
        $date = $this->daysAgo(2);

        $payload = [
            'work_date' => $date,
            'requested_first_in_at' => "{$date} 09:00:00",
            'reason' => 'First ask.',
        ];

        $this->postJson('/api/hrms/attendance/regularizations', $payload)->assertCreated();

        $this->postJson('/api/hrms/attendance/regularizations', [
            ...$payload,
            'reason' => 'Second ask for the same morning.',
        ])->assertUnprocessable()->assertJsonValidationErrors('form');
    }

    public function test_manager_approval_inserts_superseding_punches_and_restamps_the_day(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->rosterFor($report);
        $this->actAs($report->user);
        $date = $this->daysAgo(2);

        $id = $this->postJson('/api/hrms/attendance/regularizations', [
            'work_date' => $date,
            'requested_first_in_at' => "{$date} 09:00:00",
            'requested_punch_at' => "{$date} 18:00:00",
            'reason' => 'Missed both punches.',
        ])->assertCreated()->json('request.id');

        $this->actAs($manager->user);

        $body = $this->postJson("/api/hrms/attendance/regularizations/{$id}/approve", [
            'note' => 'Confirmed with the gate register.',
        ])->assertOk()->json();

        $this->assertSame('approved', $body['request']['status']);

        $punches = AttendancePunch::query()->where('employee_id', $report->id)->orderBy('punch_at')->get();

        // Two superseding rows, both marked regularized — the original void
        // (there was none) is untouched because there is nothing to rewrite.
        $this->assertSame(['in', 'out'], $punches->map(fn (AttendancePunch $punch): string => $punch->direction->value)->all());
        $this->assertTrue($punches->every(fn (AttendancePunch $punch): bool => $punch->source->value === 'regularized'));

        $day = AttendanceDay::query()
            ->where('employee_id', $report->id)
            ->whereDate('work_date', $date)
            ->firstOrFail();

        $this->assertTrue($day->is_regularized);
        $this->assertSame($manager->user->id, $day->regularized_by_user_id);
        $this->assertSame(480, $day->worked_minutes);

        $this->assertTrue(HrmsAuditLog::query()->where('action', 'attendance.regularization.approved')->exists());
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $report->user->id)
            ->where('type', 'hrms.attendance.regularization.decided')
            ->exists());
    }

    public function test_a_stranger_cannot_decide_someone_elses_ask(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->actAs($report->user);
        $date = $this->daysAgo(2);

        $id = $this->postJson('/api/hrms/attendance/regularizations', [
            'work_date' => $date,
            'requested_first_in_at' => "{$date} 09:00:00",
            'reason' => 'Forgot to clock in.',
        ])->assertCreated()->json('request.id');

        // A permission holder who is not the step's approver: the policy
        // answers from the chain, not from the permission.
        $this->actAs($this->userWith(['hrms.view', 'hrms.attendance.regularize']));

        $this->postJson("/api/hrms/attendance/regularizations/{$id}/approve")->assertForbidden();
        $this->postJson("/api/hrms/attendance/regularizations/{$id}/reject", ['note' => 'No.'])->assertForbidden();
    }

    public function test_rejection_needs_a_reason_and_leaves_the_day_alone(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->rosterFor($report);
        $this->actAs($report->user);
        $date = $this->daysAgo(2);

        $id = $this->postJson('/api/hrms/attendance/regularizations', [
            'work_date' => $date,
            'requested_first_in_at' => "{$date} 09:00:00",
            'reason' => 'Forgot to clock in.',
        ])->assertCreated()->json('request.id');

        $this->actAs($manager->user);

        $this->postJson("/api/hrms/attendance/regularizations/{$id}/reject")
            ->assertUnprocessable()->assertJsonValidationErrors('decision_note');

        $this->postJson("/api/hrms/attendance/regularizations/{$id}/reject", [
            'note' => 'The gate register shows no entry that morning.',
        ])->assertOk()->assertJsonPath('request.status', 'rejected');

        $day = AttendanceDay::query()
            ->where('employee_id', $report->id)
            ->whereDate('work_date', $date)
            ->firstOrFail();

        $this->assertFalse($day->is_regularized);
        $this->assertSame(0, AttendancePunch::query()->where('employee_id', $report->id)->count());
    }

    public function test_the_queue_scopes_to_self_unless_reviewing(): void
    {
        [$manager, $report] = $this->reportingLine();
        $other = $this->userWith(['hrms.view']);
        $otherEmployee = $this->makeEmployee('Other', ['user_id' => $other->id]);

        $this->actAs($report->user);
        $reportAsk = $this->postJson('/api/hrms/attendance/regularizations', [
            'work_date' => $this->daysAgo(2),
            'requested_first_in_at' => $this->daysAgo(2).' 09:00:00',
            'reason' => 'Mine.',
        ])->assertCreated()->json('request.id');

        $this->actAs($other);
        $otherAsk = $this->postJson('/api/hrms/attendance/regularizations', [
            'work_date' => $this->daysAgo(3),
            'requested_first_in_at' => $this->daysAgo(3).' 09:00:00',
            'reason' => 'Also mine.',
        ])->assertCreated()->json('request.id');

        // Each employee sees only their own ask, and cannot open the other's.
        $mine = $this->getJson('/api/hrms/attendance/regularizations')->assertOk()->json('requests');
        $this->assertSame([$otherAsk], array_column($mine, 'id'));
        $this->getJson("/api/hrms/attendance/regularizations/{$reportAsk}")->assertForbidden();

        // A reviewer sees both.
        $this->actAs($this->userWith(['hrms.view', 'hrms.attendance.regularize']));
        $all = $this->getJson('/api/hrms/attendance/regularizations')->assertOk()->json('requests');
        $this->assertEqualsCanonicalizing([$reportAsk, $otherAsk], array_column($all, 'id'));
    }

    // ------------------------------------------------------------ helpers

    /**
     * A manager with a login and one report with a login.
     *
     * @return array{Employee, Employee}
     */
    private function reportingLine(): array
    {
        $managerUser = $this->userWith(['hrms.view']);
        $manager = $this->makeEmployee('Mana Ger', ['user_id' => $managerUser->id]);
        $manager->user = $managerUser;

        $reportUser = $this->userWith(['hrms.view']);
        $report = $this->makeEmployee('Repo Rt', ['user_id' => $reportUser->id, 'manager_id' => $manager->id]);
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

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;

        $user = User::create([
            'name' => "Regularization User {$sequence}",
            'email' => "regularization.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Regularization Role {$sequence}",
            'slug' => "regularization-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmployee(string $name, array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-REG-'.$sequence,
            'name' => $name,
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }

    private function rosterFor(Employee $employee, array $shiftOverrides = []): void
    {
        static $sequence = 0;

        $sequence++;

        $shift = AttendanceShift::create([
            'name' => "Reg Shift {$sequence}",
            'code' => "reg-shift-{$sequence}",
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'position' => $sequence * 10,
            'break_minutes' => 60,
            ...$shiftOverrides,
        ]);

        AttendanceRoster::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => Carbon::today()->subDays(30)->toDateString(),
        ]);
    }

    private function setAcmeModules(array $modules): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }
}
