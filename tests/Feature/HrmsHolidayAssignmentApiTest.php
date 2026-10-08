<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Holiday\EmployeeHolidayCalendar;
use App\Models\Hrms\Holiday\Holiday;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P8.4b — assignments, optional answers, and the resolved view.
 *
 * Assigning is HR administration end to end; declaring is self-service
 * with a managed queue beside it; the resolved grid answers per employee
 * with self-service — anyone reads their own year, anyone else needs the
 * view permission.
 */
class HrmsHolidayAssignmentApiTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.holidays']);
    }

    public function test_assigning_needs_manage_and_refuses_overlap(): void
    {
        $viewer = $this->userWith(['hrms.view', 'hrms.holidays.view']);
        $this->makeEmployee('Viewer', ['user_id' => $viewer->id]);
        $this->actAs($viewer);

        $employee = $this->makeEmployee('Assignee');
        $calendar = HolidayCalendar::query()->where('slug', 'national-in')->firstOrFail();

        $this->postJson('/api/hrms/holidays/assignments', [
            'employee_id' => $employee->id,
            'calendar_id' => $calendar->id,
            'effective_from' => '2026-01-01',
        ])->assertForbidden();

        $this->loginAdmin();

        $body = $this->postJson('/api/hrms/holidays/assignments', [
            'employee_id' => $employee->id,
            'calendar_id' => $calendar->id,
            'effective_from' => '2026-01-01',
        ])->assertCreated()->json();

        $this->assertSame($employee->id, $body['assignment']['employee']['id']);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'holiday.assigned')->exists());

        $this->postJson('/api/hrms/holidays/assignments', [
            'employee_id' => $employee->id,
            'calendar_id' => $calendar->id,
            'effective_from' => '2026-06-01',
            'effective_to' => '2026-12-31',
        ])->assertUnprocessable()->assertJsonValidationErrors('form');

        $this->getJson('/api/hrms/holidays/assignments')->assertOk()->assertJsonCount(1, 'assignments');

        $this->deleteJson("/api/hrms/holidays/assignments/{$body['assignment']['id']}")->assertOk();
        $this->assertSame(0, EmployeeHolidayCalendar::query()->count());
    }

    public function test_the_resolved_view_serves_self_and_gates_others(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Self Reader', ['user_id' => $user->id]);
        $other = $this->makeEmployee('Other Reader');
        $this->actAs($user);

        $body = $this->getJson('/api/hrms/holidays/calendar?year=2026')->assertOk()->json();

        $this->assertSame($employee->id, $body['employee']['id']);
        $this->assertSame(2026, $body['year']);
        $this->assertArrayHasKey('2026-12-25', $body['days']);
        $this->assertSame('public', $body['days']['2026-12-25']['type']);

        $this->getJson("/api/hrms/holidays/calendar?year=2026&employee_id={$other->id}")->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.holidays.view']));
        $this->getJson("/api/hrms/holidays/calendar?year=2026&employee_id={$other->id}")->assertOk();
    }

    public function test_declaring_is_self_service_with_a_managed_queue(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Declarer', ['user_id' => $user->id]);
        $calendar = HolidayCalendar::query()->where('slug', 'national-us')->firstOrFail();
        $holiday = Holiday::create([
            'calendar_id' => $calendar->id,
            'name' => 'Fete Day',
            'date' => '2026-11-15',
            'type' => 'optional',
            'is_recurring' => false,
        ]);
        $this->actAs($user);

        $body = $this->postJson('/api/hrms/holidays/optional', [
            'holiday_id' => $holiday->id,
            'status' => 'taken',
            'taken_date' => '2026-11-15',
        ])->assertCreated()->json();

        $this->assertSame('taken', $body['optional']['status']);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'holiday.optional_declared')->exists());

        // Declaring again replaces the answer instead of doubling it.
        $this->postJson('/api/hrms/holidays/optional', [
            'holiday_id' => $holiday->id,
            'status' => 'skipped',
        ])->assertCreated();

        $mine = $this->getJson('/api/hrms/holidays/optional')->assertOk()->json('optionals');

        $this->assertCount(1, $mine);
        $this->assertSame('skipped', $mine[0]['status']);

        $this->actAs($this->userWith(['hrms.view', 'hrms.holidays.manage']));
        $all = $this->getJson('/api/hrms/holidays/optional')->assertOk()->json('optionals');

        $this->assertCount(1, $all);
    }

    public function test_a_stranger_cannot_declare_for_someone_else(): void
    {
        $user = $this->userWith(['hrms.view']);
        $this->makeEmployee('Stranger', ['user_id' => $user->id]);
        $other = $this->makeEmployee('Other');
        $calendar = HolidayCalendar::query()->where('slug', 'national-us')->firstOrFail();
        $holiday = Holiday::create([
            'calendar_id' => $calendar->id,
            'name' => 'Fete Day',
            'date' => '2026-11-15',
            'type' => 'optional',
            'is_recurring' => false,
        ]);
        $this->actAs($user);

        $this->postJson('/api/hrms/holidays/optional', [
            'employee_id' => $other->id,
            'holiday_id' => $holiday->id,
            'status' => 'taken',
        ])->assertForbidden();
    }

    // ------------------------------------------------------------ helpers

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
            'name' => "Holiday Assign User {$sequence}",
            'email' => "holiday.assign.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Holiday Assign Role {$sequence}",
            'slug' => "holiday-assign-role-{$sequence}",
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
            'employee_code' => 'EMP-HOLA-'.$sequence,
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
