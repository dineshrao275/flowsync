<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\CompOff\CompOffCredit;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Hrms\CompOff\CompOffCredits;
use App\Services\Hrms\CompOff\CompOffService;
use App\Services\SubscriptionService;
use Illuminate\Support\Carbon;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P7.3 — comp-off over HTTP.
 *
 * Reads and asks are policy-gated per record (self-service included,
 * deciding belongs to the step's approver); manual grants, settings and
 * runs take `hrms.comp_off.manage` at the route. Whole-number minutes
 * lose nothing in JSON, but the suite casts back anyway — the leave
 * `.0` lesson applies wherever a number crosses the wire.
 */
class HrmsCompOffApiTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.comp_off']);
    }

    public function test_an_employee_reads_their_own_bank_with_totals(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Bank Reader', ['user_id' => $user->id]);
        $this->actAs($user);

        $day = today()->subDays(10)->toDateString();
        CompOffCredit::create([
            'employee_id' => $employee->id,
            'work_date' => $day,
            'source_type' => 'weekend',
            'minutes' => 480,
            'created_at' => now(),
        ]);

        $body = $this->getJson('/api/hrms/comp-off/credits')->assertOk()->json();

        $this->assertSame($employee->id, $body['employee']['id']);
        $this->assertCount(1, $body['credits']);
        $this->assertSame('weekend', $body['credits'][0]['source_type']);
        $this->assertSame(480, $body['credits'][0]['minutes']);
        $this->assertSame(480, $body['balance_minutes']);
        $this->assertSame(0, $body['expired_minutes']);
    }

    public function test_another_persons_bank_needs_the_view_permission(): void
    {
        $reader = $this->userWith(['hrms.view']);
        $this->makeEmployee('Reader', ['user_id' => $reader->id]);
        $other = $this->userWith(['hrms.view']);
        $otherEmployee = $this->makeEmployee('Other', ['user_id' => $other->id]);

        $this->actAs($reader);
        $this->getJson("/api/hrms/comp-off/credits?employee_id={$otherEmployee->id}")->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.comp_off.view']));
        $this->getJson("/api/hrms/comp-off/credits?employee_id={$otherEmployee->id}")->assertOk();
    }

    public function test_manual_grants_need_the_manage_permission(): void
    {
        $viewer = $this->userWith(['hrms.view', 'hrms.comp_off.view']);
        $this->makeEmployee('Viewer', ['user_id' => $viewer->id]);
        $this->actAs($viewer);

        $employee = $this->makeEmployee('Grantee');

        $this->postJson('/api/hrms/comp-off/credits', [
            'employee_id' => $employee->id,
            'work_date' => today()->subDays(3)->toDateString(),
            'minutes' => 480,
        ])->assertForbidden();

        $this->loginAdmin();

        $body = $this->postJson('/api/hrms/comp-off/credits', [
            'employee_id' => $employee->id,
            'work_date' => today()->subDays(3)->toDateString(),
            'minutes' => 480,
            'source' => 'special',
            'note' => 'Foundation day duty.',
        ])->assertCreated()->json();

        $this->assertSame('special', $body['credit']['source_type']);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'comp_off.credited')->exists());
    }

    public function test_an_employee_files_lists_and_withdraws(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->credits()->creditManual($report, today()->subDays(10)->toDateString(), 960);
        $this->actAs($report->user);

        $monday = today()->modify('next monday')->toDateString();
        $tuesday = today()->modify('next monday')->addDay()->toDateString();

        $body = $this->postJson('/api/hrms/comp-off/requests', [
            'from_date' => $monday,
            'to_date' => $tuesday,
            'reason' => 'Two days back.',
        ])->assertCreated()->json();

        $this->assertSame('submitted', $body['request']['status']);
        $this->assertSame(960, $body['request']['total_minutes']);
        $this->assertCount(2, $body['request']['days']);

        $list = $this->getJson('/api/hrms/comp-off/requests')->assertOk()->json('requests');

        $this->assertCount(1, $list);

        // A stranger with the approve permission but no chain seat 403s.
        $this->actAs($this->userWith(['hrms.view', 'hrms.comp_off.approve']));
        $this->postJson("/api/hrms/comp-off/requests/{$body['request']['id']}/approve")->assertForbidden();

        $this->actAs($manager->user);
        $this->postJson("/api/hrms/comp-off/requests/{$body['request']['id']}/approve")
            ->assertOk()->assertJsonPath('request.status', 'approved');

        $this->actAs($report->user);
        $this->deleteJson("/api/hrms/comp-off/requests/{$body['request']['id']}")
            ->assertOk()->assertJsonPath('request.status', 'cancelled');
    }

    public function test_rejection_needs_a_reason(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->credits()->creditManual($report, today()->subDays(10)->toDateString(), 480);
        $this->actAs($report->user);

        $monday = today()->modify('next monday')->toDateString();

        $id = $this->postJson('/api/hrms/comp-off/requests', [
            'from_date' => $monday,
            'to_date' => $monday,
            'reason' => 'One day.',
        ])->assertCreated()->json('request.id');

        $this->actAs($manager->user);
        $this->postJson("/api/hrms/comp-off/requests/{$id}/reject")
            ->assertUnprocessable()->assertJsonValidationErrors('decision_note');

        $this->postJson("/api/hrms/comp-off/requests/{$id}/reject", ['note' => 'Blackout.'])
            ->assertOk()->assertJsonPath('request.status', 'rejected');
    }

    public function test_settings_read_and_merge_by_key(): void
    {
        $viewer = $this->userWith(['hrms.view', 'hrms.comp_off.view']);
        $this->makeEmployee('Viewer', ['user_id' => $viewer->id]);
        $this->actAs($viewer);

        $this->getJson('/api/hrms/comp-off/settings')->assertForbidden();
        $this->putJson('/api/hrms/comp-off/settings', ['validity_months' => 6])->assertForbidden();

        $this->loginAdmin();

        $body = $this->getJson('/api/hrms/comp-off/settings')->assertOk()->json();

        $this->assertTrue($body['comp_off']['from_weekends']);

        $updated = $this->putJson('/api/hrms/comp-off/settings', ['validity_months' => 6])
            ->assertOk()->json();

        // A section edit merges: tuning validity must not blank the
        // weekend flag sitting beside it.
        $this->assertSame(6, $updated['comp_off']['validity_months']);
        $this->assertTrue($updated['comp_off']['from_weekends']);
    }

    public function test_the_accrue_endpoint_runs_the_same_loop(): void
    {
        $employee = $this->makeEmployee('Endpoint Earner');
        $this->rosterFor($employee, [5 => 1, 6 => 1]);
        [$from, $to] = $this->lastMonth();

        $this->loginAdmin();

        $body = $this->postJson('/api/hrms/comp-off/accrue', [
            'employee_id' => $employee->id,
            'from' => $from,
            'to' => $to,
        ])->assertCreated()->json();

        $this->assertSame(1, $body['employees']);
        $this->assertSame($body['credited'], CompOffCredit::query()->count());
        $this->assertGreaterThan(0, $body['credited']);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array{Employee, Employee} Manager and report, each with `->user`.
     */
    private function reportingLine(): array
    {
        $managerUser = $this->makeUser();
        $manager = $this->makeEmployee('Comp Manager', ['user_id' => $managerUser->id]);
        $manager->user = $managerUser;

        $reportUser = $this->makeUser();
        $report = $this->makeEmployee('Comp Report', ['user_id' => $reportUser->id, 'manager_id' => $manager->id]);
        $report->user = $reportUser;

        return [$manager, $report];
    }

    private function service(): CompOffService
    {
        return app(CompOffService::class);
    }

    private function credits(): CompOffCredits
    {
        return app(CompOffCredits::class);
    }

    /**
     * @return array{string, string} First and last day of the previous month.
     */
    private function lastMonth(): array
    {
        $start = today()->startOfMonth()->subMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    private function loginAdmin(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');
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
            'name' => "Comp Api User {$sequence}",
            'email' => "comp.api.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Comp Api Role {$sequence}",
            'slug' => "comp-api-role-{$sequence}",
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
            'name' => "Comp Login {$sequence}",
            'email' => "comp.login.{$sequence}@flowsync.test",
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
            'employee_code' => 'EMP-CMP-'.$sequence,
            'name' => $name,
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<int, int>  $weeklyOffs  Zero-based Monday-first flags.
     */
    private function rosterFor(Employee $employee, array $weeklyOffs = []): void
    {
        static $sequence = 0;

        $sequence++;

        $offs = array_fill(0, 7, 0);

        foreach ($weeklyOffs as $index => $flag) {
            $offs[$index] = $flag;
        }

        $shift = AttendanceShift::create([
            'name' => "Comp Api Shift {$sequence}",
            'code' => "comp-api-shift-{$sequence}",
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'position' => $sequence * 10,
        ]);

        AttendanceRoster::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => Carbon::today()->subDays(90)->toDateString(),
            'weekly_offs' => $offs,
        ]);
    }

    private function setAcmeModules(array $modules): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }
}
