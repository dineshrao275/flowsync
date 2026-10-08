<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Support\Carbon;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.6a — reading attendance back out: the month grid, today's widget, export.
 *
 * All three answer "whose curve" through the same policy (self-service
 * included, strangers 403), and all three are pure reads — the week-off
 * merge must never materialize a row, or a GET would do the rollup's job.
 */
class HrmsAttendanceRecordsTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.attendance', 'hrms.attendance.remote']);
    }

    public function test_the_month_grid_carries_statuses_and_a_summary(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Grid Reader', ['user_id' => $user->id]);
        $this->rosterFor($employee);
        $this->actAs($user);
        $day = $this->daysAgo(2);

        $this->postJson('/api/hrms/attendance/punch', ['direction' => 'in', 'punch_at' => "{$day} 09:00:00"])->assertCreated();
        $this->postJson('/api/hrms/attendance/punch', ['direction' => 'out', 'punch_at' => "{$day} 18:00:00"])->assertCreated();

        $month = Carbon::parse($day);

        $body = $this->getJson("/api/hrms/attendance/month?year={$month->year}&month={$month->month}")
            ->assertOk()->json();

        $this->assertSame($employee->id, $body['employee']['id']);

        $cells = collect($body['days'])->keyBy('date');
        $this->assertSame('present', $cells[$day]['status']);
        $this->assertSame(480, $cells[$day]['worked_minutes']);
        $this->assertGreaterThanOrEqual(1, $body['summary']['present']);
    }

    public function test_another_persons_curve_needs_the_view_permission(): void
    {
        $reader = $this->userWith(['hrms.view']);
        $this->makeEmployee('Reader', ['user_id' => $reader->id]);
        $other = $this->userWith(['hrms.view']);
        $otherEmployee = $this->makeEmployee('Other', ['user_id' => $other->id]);

        $this->actAs($reader);
        $this->getJson("/api/hrms/attendance/month?employee_id={$otherEmployee->id}")->assertForbidden();
        $this->getJson("/api/hrms/attendance/today?employee_id={$otherEmployee->id}")->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.attendance.view']));
        $this->getJson("/api/hrms/attendance/month?employee_id={$otherEmployee->id}")->assertOk();
        $this->getJson("/api/hrms/attendance/today?employee_id={$otherEmployee->id}")->assertOk();
    }

    public function test_a_week_off_reads_without_writing_a_row(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Weekend', ['user_id' => $user->id]);
        $day = $this->daysAgo(2);
        $this->rosterFor($employee, [$this->isoWeekday($day) => 1]);
        $this->actAs($user);

        $before = AttendanceDay::query()->count();

        $month = Carbon::parse($day);

        $body = $this->getJson("/api/hrms/attendance/month?year={$month->year}&month={$month->month}")
            ->assertOk()->json();

        $cells = collect($body['days'])->keyBy('date');
        $this->assertSame('week_off', $cells[$day]['status']);
        $this->assertFalse($cells[$day]['has_record']);

        // The merge is a read: a GET that materialized rows would do the
        // rollup's job and double its ledger of who created what.
        $this->assertSame($before, AttendanceDay::query()->count());
    }

    public function test_today_lists_todays_punches_in_order(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Today', ['user_id' => $user->id]);
        $this->rosterFor($employee);
        $this->actAs($user);

        $today = today()->toDateString();

        $this->postJson('/api/hrms/attendance/punch', ['direction' => 'in', 'punch_at' => "{$today} 09:00:00"])->assertCreated();
        $this->postJson('/api/hrms/attendance/punch', ['direction' => 'out', 'punch_at' => "{$today} 13:00:00"])->assertCreated();

        $body = $this->getJson('/api/hrms/attendance/today')->assertOk()->json();

        $this->assertSame($today, $body['date']);
        $this->assertSame(['in', 'out'], array_column($body['punches'], 'direction'));
        $this->assertNotNull($body['day']);
    }

    public function test_export_downloads_csv_and_writes_an_access_row(): void
    {
        $user = $this->userWith(['hrms.view', 'workspaces.manage']);
        $employee = $this->makeEmployee('Exporter', ['user_id' => $user->id]);
        $this->rosterFor($employee);
        $this->actAs($user);
        $day = $this->daysAgo(2);

        $this->postJson('/api/hrms/attendance/punch', ['direction' => 'in', 'punch_at' => "{$day} 09:00:00"])->assertCreated();
        $this->postJson('/api/hrms/attendance/punch', ['direction' => 'out', 'punch_at' => "{$day} 18:00:00"])->assertCreated();

        $from = Carbon::parse($day)->subDays(3)->toDateString();
        $to = Carbon::parse($day)->addDays(3)->toDateString();

        $response = $this->get("/api/hrms/attendance/export?employee_id={$employee->id}&from={$from}&to={$to}");

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $lines = array_filter(explode("\n", trim($response->streamedContent() ?? '')));
        $this->assertSame('date,status,first_in,last_out,worked_minutes,break_minutes,late_by_minutes,early_by_minutes,overtime_minutes,regularized', $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString("{$day},present", $lines[1]);

        $this->assertTrue(HrmsDataAccessLog::query()
            ->where('model', 'App\Models\Hrms\Attendance\AttendanceDay')
            ->where('record_id', $employee->id)
            ->where('action', 'export')
            ->exists());
    }

    public function test_export_refuses_more_than_a_quarter(): void
    {
        $user = $this->userWith(['hrms.view', 'workspaces.manage']);
        $employee = $this->makeEmployee('Bulk', ['user_id' => $user->id]);
        $this->actAs($user);

        $this->getJson("/api/hrms/attendance/export?employee_id={$employee->id}&from=2026-01-01&to=2026-12-31")
            ->assertUnprocessable()->assertJsonValidationErrors('to');

        $this->assertFalse(HrmsDataAccessLog::query()->where('action', 'export')->exists());
    }

    // ------------------------------------------------------------ helpers

    private function daysAgo(int $days): string
    {
        return Carbon::today()->subDays($days)->toDateString();
    }

    /**
     * Zero-based Monday-first index of a date's weekday, matching the
     * `weekly_offs` array layout.
     */
    private function isoWeekday(string $day): int
    {
        return Carbon::parse($day)->dayOfWeekIso - 1;
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
            'name' => "Records User {$sequence}",
            'email' => "records.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Records Role {$sequence}",
            'slug' => "records-role-{$sequence}",
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
            'employee_code' => 'EMP-REC-'.$sequence,
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
            'name' => "Records Shift {$sequence}",
            'code' => "records-shift-{$sequence}",
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'position' => $sequence * 10,
            'break_minutes' => 60,
        ]);

        AttendanceRoster::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => Carbon::today()->subDays(30)->toDateString(),
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
