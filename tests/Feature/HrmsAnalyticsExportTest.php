<?php

namespace Tests\Feature;

use App\Enums\Hrms\DataAccessAction;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P18.5 — the dashboard tabs travel as CSV.
 *
 * Each export answers to its own domain permission (the same slug as the
 * tab route — a chart the caller cannot see has a CSV they cannot take);
 * unknown domains 422; and every download writes an `accessed(..., Export)`
 * row naming the exported fields, so a bulk pull outward is never silent.
 */
class HrmsAnalyticsExportTest extends TestCase
{
    use IsolatesDatabase;

    public function test_each_export_answers_to_its_domain_permission(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.analytics.view']));

        $this->getJson('/api/hrms/analytics/export?domain=attendance')->assertForbidden();
        $this->getJson('/api/hrms/analytics/export?domain=lifecycle')->assertOk();
        $this->getJson('/api/hrms/analytics/export?domain=payroll')->assertForbidden();
    }

    public function test_an_export_streams_csv_and_logs_the_pull(): void
    {
        $reader = $this->userWith(['hrms.view', 'hrms.attendance.view']);
        $this->actAs($reader);

        $response = $this->get('/api/hrms/analytics/export?domain=attendance')->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $body = $this->streamBody($response);
        $this->assertStringContainsString('section,item,value,detail', $body);
        $this->assertStringContainsString('present_days', $body);

        $row = HrmsDataAccessLog::query()
            ->where('model', (new AttendanceDay)->getMorphClass())
            ->where('actor_user_id', $reader->id)
            ->firstOrFail();

        $this->assertSame(DataAccessAction::Export, $row->action);
        $this->assertContains('present_days', $row->fields);
    }

    public function test_unknown_domains_422(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.analytics.view']));

        $this->getJson('/api/hrms/analytics/export?domain=nope')->assertStatus(422);
        $this->getJson('/api/hrms/analytics/export')->assertStatus(422);
    }

    public function test_payroll_exports_need_the_run_permission_and_log_pay(): void
    {
        $reader = $this->userWith(['hrms.view', 'hrms.payroll.run']);
        $this->actAs($reader);

        $body = $this->streamBody($this->get('/api/hrms/analytics/export?domain=payroll')->assertOk());

        $this->assertStringContainsString('total_gross', $body);

        $this->assertTrue(HrmsDataAccessLog::query()
            ->where('model', (new Payslip)->getMorphClass())
            ->where('actor_user_id', $reader->id)
            ->where('action', DataAccessAction::Export)
            ->exists());
    }

    // ------------------------------------------------------------ helpers

    private function streamBody(TestResponse $response): string
    {
        // TestResponse buffers the callback and returns it — wrapping it in
        // a second output buffer would swallow the return into the void.
        return (string) $response->streamedContent();
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
        $this->connectTenant('acme');

        $user = User::create([
            'name' => "Analytics Export User {$sequence}",
            'email' => "analytics.export.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Analytics Export Role {$sequence}",
            'slug' => "analytics-export-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
