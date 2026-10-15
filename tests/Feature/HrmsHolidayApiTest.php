<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
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
 * P8.4a — calendars and holidays over HTTP, plus the seed-year run.
 *
 * Reads take `hrms.holidays.view`, writes take `manage`; nested holiday
 * creation authorizes against the parent calendar. Promoting a default
 * demotes the predecessor, deletion refuses while referenced, and the
 * seed-year run is idempotent with a single bulk audit row.
 */
class HrmsHolidayApiTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.holidays']);
    }

    public function test_reads_need_the_view_permission(): void
    {
        $this->actAs($this->userWith(['hrms.view']));

        $this->getJson('/api/hrms/holidays/calendars')->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.holidays.view']));

        $body = $this->getJson('/api/hrms/holidays/calendars')->assertOk()->json();

        $this->assertGreaterThanOrEqual(2, count($body['calendars']));
    }

    public function test_calendar_crud_with_default_promotion(): void
    {
        $this->loginAdmin();

        $id = $this->postJson('/api/hrms/holidays/calendars', ['name' => 'Office'])
            ->assertCreated()->json('calendar.id');

        $this->assertSame('office', HolidayCalendar::findOrFail($id)->slug);

        // Promoting demotes the previous default in-transaction: exactly
        // one default stands afterward.
        $this->putJson("/api/hrms/holidays/calendars/{$id}", ['name' => 'Office', 'is_default' => true])
            ->assertOk()->assertJsonPath('calendar.is_default', true);

        $this->assertSame(1, HolidayCalendar::query()->default()->count());
        $this->assertSame($id, HolidayCalendar::query()->default()->value('id'));
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'holiday.calendar_created')->exists());
    }

    public function test_a_calendar_in_use_refuses_deletion(): void
    {
        $this->loginAdmin();

        $standard = HolidayCalendar::query()->where('slug', 'national-us')->firstOrFail();

        // Seeded holidays point at it.
        $this->deleteJson("/api/hrms/holidays/calendars/{$standard->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('form');

        $empty = $this->postJson('/api/hrms/holidays/calendars', ['name' => 'Empty'])
            ->assertCreated()->json('calendar.id');

        $this->deleteJson("/api/hrms/holidays/calendars/{$empty}")->assertOk();
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'holiday.calendar_deleted')->exists());
    }

    public function test_nested_holidays_validate_and_refuse_while_answered(): void
    {
        $this->loginAdmin();

        $calendar = HolidayCalendar::query()->where('slug', 'national-us')->firstOrFail();

        $this->postJson("/api/hrms/holidays/calendars/{$calendar->id}/holidays", [
            'name' => 'Founders Day',
            'date' => 'not-a-date',
        ])->assertUnprocessable()->assertJsonValidationErrors('date');

        $id = $this->postJson("/api/hrms/holidays/calendars/{$calendar->id}/holidays", [
            'name' => 'Founders Day',
            'date' => '2026-11-15',
            'type' => 'restricted',
        ])->assertCreated()->json('holiday.id');

        $this->putJson("/api/hrms/holidays/{$id}", ['name' => 'Founders Day (observed)'])
            ->assertOk()->assertJsonPath('holiday.name', 'Founders Day (observed)');

        $this->getJson("/api/hrms/holidays/calendars/{$calendar->id}/holidays")
            ->assertOk()->assertJsonCount(6, 'holidays');

        $this->deleteJson("/api/hrms/holidays/{$id}")->assertOk();
    }

    public function test_seed_year_is_idempotent_manage_only_and_audited(): void
    {
        $viewer = $this->userWith(['hrms.view', 'hrms.holidays.view']);
        $this->makeEmployee('Seed Viewer', ['user_id' => $viewer->id]);
        $this->actAs($viewer);

        $this->postJson('/api/hrms/holidays/seed-year', ['year' => 2027])->assertForbidden();

        $this->loginAdmin();

        $first = $this->postJson('/api/hrms/holidays/seed-year', ['year' => 2027])
            ->assertCreated()->json();

        $this->assertSame(0, $first['calendars']);
        $this->assertSame(10, $first['holidays']);

        $second = $this->postJson('/api/hrms/holidays/seed-year', ['year' => 2027])
            ->assertCreated()->json();

        $this->assertSame(0, $second['calendars']);
        $this->assertSame(0, $second['holidays']);

        $this->assertSame(2, HrmsAuditLog::query()->where('action', 'holiday.year_seeded')->count());

        $this->postJson('/api/hrms/holidays/seed-year', ['year' => 'soon'])
            ->assertUnprocessable()->assertJsonValidationErrors('year');
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
            'name' => "Holiday Api User {$sequence}",
            'email' => "holiday.api.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Holiday Api Role {$sequence}",
            'slug' => "holiday-api-role-{$sequence}",
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
            'employee_code' => 'EMP-HOL-'.$sequence,
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
