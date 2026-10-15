<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Hrms\Attendance\WorkLogDerivation;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.3 — the attendance settings and remote punch over HTTP.
 *
 * The service rules are covered in `HrmsAttendanceServiceTest`. What is
 * worth protecting *here* is the part the service cannot see: the punch
 * endpoint takes no permission (it resolves the record from the login, so
 * there is nothing to authorize against), the module 403 comes from
 * `hrms.attendance.remote` and not from a permission, settings take the
 * settings permission, and a login with no employment record gets a 404
 * rather than punching into the void.
 */
class HrmsAttendanceApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_punch_needs_the_remote_module_not_a_permission(): void
    {
        // Base attendance module only: the endpoint exists, the plan does
        // not include remote punching, so this is a module 403 — the
        // client’s upgrade path, not a permission failure.
        $this->setAcmeModules(['hrms.core', 'hrms.attendance']);
        $user = $this->userWith(['hrms.view']);
        $this->makeEmployee('Puncher', ['user_id' => $user->id]);
        $this->actAs($user);

        $this->postJson('/api/hrms/attendance/punch', ['direction' => 'in'])->assertForbidden();

        $this->setAcmeModules(['hrms.core', 'hrms.attendance', 'hrms.attendance.remote']);

        $this->postJson('/api/hrms/attendance/punch', ['direction' => 'in'])->assertCreated();
    }

    public function test_a_login_with_no_employment_record_cannot_punch(): void
    {
        $this->actAs($this->userWith(['hrms.view']));

        // No employee row: a 404 naming the missing record, not a 500 on a
        // null dereference somewhere inside the pairing code.
        $this->postJson('/api/hrms/attendance/punch', ['direction' => 'in'])->assertNotFound();
    }

    public function test_a_full_day_over_http_comes_back_present(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Full Day', ['user_id' => $user->id]);
        $this->rosterFor($employee, ['break_minutes' => 60]);
        $this->actAs($user);

        $this->postJson('/api/hrms/attendance/punch', ['direction' => 'in', 'punch_at' => '2026-10-06 09:00:00'])
            ->assertCreated()->assertJsonPath('punch.direction', 'in');

        $body = $this->postJson('/api/hrms/attendance/punch', ['direction' => 'out', 'punch_at' => '2026-10-06 18:00:00'])
            ->assertCreated()->json();

        $this->assertSame('Clocked out.', $body['message']);
        $this->assertSame('present', $body['day']['status']);
        $this->assertSame(480, $body['day']['worked_minutes']);
    }

    public function test_a_bad_direction_is_a_422_not_a_500(): void
    {
        $user = $this->userWith(['hrms.view']);
        $this->makeEmployee('Puncher', ['user_id' => $user->id]);
        $this->actAs($user);

        // Unvalidated, this reaches the pairing cast and takes the endpoint
        // down; validated, it is a field error like any other.
        $this->postJson('/api/hrms/attendance/punch', ['direction' => 'sideways'])
            ->assertUnprocessable()->assertJsonValidationErrors('direction');
    }

    public function test_settings_need_the_settings_permission_and_merge_by_section(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.attendance.view']));

        $this->putJson('/api/hrms/attendance/settings', ['attendance' => ['rounding_minutes' => 5]])
            ->assertForbidden();

        $this->login('admin@flowsync.test');

        $body = $this->putJson('/api/hrms/attendance/settings', ['attendance' => ['rounding_minutes' => 5]])
            ->assertOk()->json();

        $this->assertSame(5, $body['settings']['attendance']['rounding_minutes']);

        // A section edit merges: tuning the rounding step must not blank the
        // overtime threshold sitting beside it in the same section.
        $settings = HrmsSetting::current();

        $this->assertSame(5, $settings->setting('attendance.rounding_minutes'));
        $this->assertSame(480, $settings->setting('attendance.ot_after_minutes'));

        $this->putJson('/api/hrms/attendance/settings', ['attendance' => ['rounding_minutes' => 'soon']])
            ->assertUnprocessable()->assertJsonValidationErrors('attendance.rounding_minutes');

        // GET endpoint returns settings
        $getRes = $this->getJson('/api/hrms/attendance/settings')->assertOk();
        $this->assertSame(5, $getRes->json('settings.attendance.rounding_minutes'));

        // C8: auto_derive_from_work_logs opt-in toggle
        $this->assertFalse((bool) $settings->setting('attendance.auto_derive_from_work_logs', false));
        $this->putJson('/api/hrms/attendance/settings', [
            'attendance' => ['auto_derive_from_work_logs' => true],
        ])->assertOk();

        $settings = HrmsSetting::current();
        $this->assertTrue((bool) $settings->setting('attendance.auto_derive_from_work_logs'));
        $this->assertTrue(app(WorkLogDerivation::class)->isEnabled());
    }

    // ------------------------------------------------------------ helpers

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
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
            'name' => "Attendance API User {$sequence}",
            'email' => "attendance.api.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Attendance API Role {$sequence}",
            'slug' => "attendance-api-role-{$sequence}",
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
            'employee_code' => 'EMP-ACA-'.$sequence,
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
            'name' => "API Shift {$sequence}",
            'code' => "api-shift-{$sequence}",
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'position' => $sequence * 10,
            ...$shiftOverrides,
        ]);

        AttendanceRoster::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-10-01',
        ]);
    }

    private function setAcmeModules(array $modules): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }
}
