<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OnboardingCase;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\User;
use App\Services\Hrms\OnboardingService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P15.2 — the inbox over HTTP.
 *
 * Self-scoped like notifications: the queue, its count and its reads all
 * answer per login, junk keys are declined rather than stored, and
 * clearing everything reports what it cleared.
 */
class HrmsInboxApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_the_queue_paginates_with_its_count(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->onboardingTask($report, 5);
        $this->onboardingTask($report, 6);
        $this->actAs($report->user);

        $body = $this->getJson('/api/hrms/inbox?per_page=1')->assertOk()->json();

        $this->assertCount(1, $body['items']);
        $this->assertSame(2, $body['pagination']['total']);
        $this->assertSame(2, $body['pagination']['last_page']);
        $this->assertSame(2, $body['unread_count']);
        $this->assertArrayHasKey('meta', $body['items'][0]);
        $this->assertArrayNotHasKey('href', $body['items'][0]);
    }

    public function test_reads_drop_the_count_and_decline_junk(): void
    {
        [$manager, $report] = $this->reportingLine();
        $task = $this->onboardingTask($report, 5);
        $this->actAs($report->user);

        $key = "onboarding_task:{$task->id}";

        $this->postJson('/api/hrms/inbox/read', ['keys' => [$key, 'approval:999999']])
            ->assertOk()
            ->assertJsonPath('marked', 1)
            ->assertJsonPath('unread_count', 0);

        $this->assertDatabaseMissing('inbox_reads', [
            'user_id' => $report->user->id,
            'item_key' => 'approval:999999',
        ]);
    }

    public function test_read_all_clears_and_reports(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->onboardingTask($report, 5);
        $this->onboardingTask($report, 6);
        $this->actAs($manager->user);

        // The manager sees the report's items too — clearing theirs
        // changes nothing for the report.
        $this->postJson('/api/hrms/inbox/read-all', [])
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->actAs($report->user);
        $this->getJson('/api/hrms/inbox')->assertOk()->assertJsonPath('unread_count', 2);
    }

    // ------------------------------------------------------------ helpers

    private function onboardingTask(Employee $owner, int $dueInDays): OnboardingCaseTask
    {
        $this->connectTenant('acme');

        // One open case per employee: the service refuses parallel runs,
        // so tasks accumulate on the same case like the real flow.
        $case = OnboardingCase::query()->where('employee_id', $owner->id)->first();

        if ($case === null) {
            $template = app(OnboardingService::class)->createTemplate('Inbox Api Template', []);
            $case = app(OnboardingService::class)->createCase($owner, $template);
        }

        return OnboardingCaseTask::create([
            'case_id' => $case->id,
            'title' => 'Upload an ID.',
            'category' => 'document',
            'owner_scope' => 'employee',
            'owner_employee_id' => $owner->id,
            'due_date' => today()->addDays($dueInDays)->toDateString(),
            'status' => 'pending',
        ]);
    }

    /**
     * @return array{Employee, Employee} Manager and report, each with `->user`.
     */
    private function reportingLine(): array
    {
        $this->connectTenant('acme');

        $managerUser = User::create([
            'name' => 'Inbox Api Manager', 'email' => 'inbox.api.manager@flowsync.test', 'password' => 'password',
        ]);
        $manager = Employee::create([
            'employee_code' => 'EMP-INBA-MGR', 'name' => 'Inbox Api Manager',
            'status' => EmployeeStatus::Active, 'user_id' => $managerUser->id,
        ]);
        $manager->user = $managerUser;

        $reportUser = User::create([
            'name' => 'Inbox Api Report', 'email' => 'inbox.api.report@flowsync.test', 'password' => 'password',
        ]);
        $report = Employee::create([
            'employee_code' => 'EMP-INBA-REP', 'name' => 'Inbox Api Report',
            'status' => EmployeeStatus::Active, 'user_id' => $reportUser->id, 'manager_id' => $manager->id,
        ]);
        $report->user = $reportUser;

        return [$manager, $report];
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }
}
