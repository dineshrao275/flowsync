<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P17.2 — the telescope endpoints.
 *
 * The team reads direct reports only, with per-report presence, leave,
 * overdue work, waiting approvals and balances — and no salary figure
 * anywhere in the payload, asserted by serialization rather than by
 * absence of intent. Summaries headline one record for HR; preferences
 * round-trip with unknown keys dropped.
 */
class HrmsMyTeamTest extends TestCase
{
    use IsolatesDatabase;

    public function test_the_team_shows_direct_reports_and_no_salary(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->actAs($manager->user);

        $body = $this->getJson('/api/my/team')->assertOk()->json();

        $this->assertCount(1, $body['reports']);

        $card = $body['reports'][0];
        $this->assertSame($report->id, $card['id']);
        $this->assertArrayHasKey('today', $card);
        $this->assertArrayHasKey('on_leave_today', $card);
        $this->assertArrayHasKey('overdue_tasks', $card);
        $this->assertArrayHasKey('pending_approvals', $card);
        $this->assertArrayHasKey('leave_balances', $card);

        $serialized = json_encode($body);
        $this->assertStringNotContainsStringIgnoringCase('salary', $serialized);
        $this->assertStringNotContainsStringIgnoringCase('ctc', $serialized);
        $this->assertStringNotContainsString('net_pay', $serialized);
    }

    public function test_a_record_less_login_manages_nobody(): void
    {
        $this->connectTenant('acme');

        $user = User::create([
            'name' => 'No Reports',
            'email' => 'no.reports@flowsync.test',
            'password' => 'password',
        ]);
        $this->actAs($user);

        $this->getJson('/api/my/team')->assertOk()->assertJsonPath('reports', []);
    }

    public function test_a_summary_headlines_one_record_without_figures(): void
    {
        [$manager, $report] = $this->reportingLine();
        $manager->user->roles()->sync([$this->roleWith(['hrms.view', 'hrms.employees.view'])->id]);
        $this->actAs($manager->user->fresh(['roles.permissions']));

        $body = $this->getJson("/api/hrms/employees/{$report->id}/summary")->assertOk()->json('summary');

        $this->assertSame($report->id, $body['id']);
        $this->assertArrayHasKey('tenure_years', $body);
        $this->assertArrayHasKey('attendance', $body);
        $this->assertArrayHasKey('leave_balances', $body);
        $this->assertArrayHasKey('assets', $body);
        $this->assertArrayHasKey('documents', $body);
        $this->assertArrayHasKey('performance', $body);
        $this->assertArrayHasKey('payroll', $body);

        $serialized = json_encode($body);
        $this->assertStringNotContainsStringIgnoringCase('salary', $serialized);
        $this->assertStringNotContainsStringIgnoringCase('ctc', $serialized);
        $this->assertStringNotContainsString('net_pay', $serialized);
        $this->assertStringNotContainsString('gross_pay', $serialized);
    }

    public function test_preferences_round_trip_and_drop_unknown_keys(): void
    {
        $this->connectTenant('acme');

        $user = User::create([
            'name' => 'Pref User',
            'email' => 'pref.user@flowsync.test',
            'password' => 'password',
        ]);
        $this->actAs($user);

        $defaults = $this->getJson('/api/my/hr/preferences')->assertOk()->json('preferences');
        $this->assertTrue($defaults['inbox_badge']);

        $saved = $this->putJson('/api/my/hr/preferences', [
            'inbox_badge' => false,
            'weekly_summary' => false,
            'not_a_setting' => true,
        ])->assertOk()->json('preferences');

        $this->assertFalse($saved['inbox_badge']);
        $this->assertFalse($saved['weekly_summary']);
        $this->assertTrue($saved['leave_reminders']);
        $this->assertArrayNotHasKey('not_a_setting', $saved);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array{Employee, Employee} Manager and report, each with `->user`.
     */
    private function reportingLine(): array
    {
        $this->connectTenant('acme');

        $managerUser = User::create([
            'name' => 'Team Manager', 'email' => 'team.manager@flowsync.test', 'password' => 'password',
        ]);
        $manager = Employee::create([
            'employee_code' => 'EMP-TEAM-MGR', 'name' => 'Team Manager',
            'status' => EmployeeStatus::Active, 'user_id' => $managerUser->id,
        ]);

        $reportUser = User::create([
            'name' => 'Team Report', 'email' => 'team.report@flowsync.test', 'password' => 'password',
        ]);
        $report = Employee::create([
            'employee_code' => 'EMP-TEAM-REP', 'name' => 'Team Report',
            'status' => EmployeeStatus::Active, 'user_id' => $reportUser->id, 'manager_id' => $manager->id,
        ]);

        return [$manager->refresh(), $report->refresh()];
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function roleWith(array $permissionSlugs): Role
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $role = Role::create([
            'name' => "Team Role {$sequence}",
            'slug' => "team-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        return $role;
    }
}
