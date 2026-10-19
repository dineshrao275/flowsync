<?php

namespace Tests\Feature;

use App\Models\HrAutomationRun;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Hrms\Employee\EmployeeService;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F5.1 / F5.3 — HR events create tasks in a project through the event bus. */
class HrAutomationTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private int $project;

    private User $hireUser;

    private User $managerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.onboarding', 'automation', 'time_tracking']);
        $this->loginAdmin();

        $workspace = $this->postJson('/api/workspaces', ['name' => 'HR WS'])->assertCreated()->json('workspace.id');
        $this->project = $this->postJson("/api/workspaces/{$workspace}/projects", ['name' => 'Onboarding', 'key' => 'ONB'])->assertCreated()->json('project.id');

        $this->hireUser = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $this->managerUser = User::where('email', 'viewer@flowsync.test')->firstOrFail();
        foreach ([$this->hireUser, $this->managerUser] as $u) {
            Workspace::first()->members()->syncWithoutDetaching([$u->id => ['role' => 'member', 'added_by' => $u->id]]);
            Project::find($this->project)->members()->attach($u->id, ['project_role_id' => ProjectRole::where('slug', 'developer')->value('id'), 'added_by' => $u->id]);
        }
    }

    private function rule(array $over = []): array
    {
        return $this->postJson('/api/hrms/automation-rules', $over + [
            'name' => 'New hire pack',
            'trigger' => 'employee.created',
            'project_id' => $this->project,
            'tasks' => [
                ['title' => 'Set up laptop for {employee}', 'assignee' => 'employee', 'due_in_days' => 3],
                ['title' => 'Welcome call with {employee}', 'assignee' => 'manager', 'description' => 'Say hi'],
                ['title' => 'Unowned checklist'],
            ],
        ])->assertCreated()->json('rule');
    }

    private function hire(): void
    {
        $manager = $this->makeEmployee('Boss', ['user_id' => $this->managerUser->id]);
        $new = $this->makeEmployee('Nia Newhire', ['user_id' => $this->hireUser->id, 'manager_id' => $manager->id]);

        // The same audited moment EmployeeService::create() records, with the reporting line already in place.
        app(HrmsAuditLogger::class)->log($new, 'employee.created', null, ['name' => $new->name], $this->actorUser());
    }

    private function actorUser(): User
    {
        return User::where('email', 'admin@flowsync.test')->firstOrFail();
    }

    public function test_creating_an_employee_creates_the_rules_tasks_once(): void
    {
        $this->rule();
        $this->hire();

        $tasks = Task::where('project_id', $this->project)->orderBy('id')->get();
        $this->assertSame(['Set up laptop for Nia Newhire', 'Welcome call with Nia Newhire', 'Unowned checklist'], $tasks->pluck('title')->all());
        $this->assertSame($this->hireUser->id, $tasks[0]->assignee_id);
        $this->assertSame($this->managerUser->id, $tasks[1]->assignee_id);
        $this->assertNull($tasks[2]->assignee_id);
        $this->assertSame(now()->addDays(3)->toDateString(), $tasks[0]->due_date->toDateString());
        $this->assertSame('success', HrAutomationRun::firstOrFail()->status);
    }

    public function test_an_inactive_rule_and_other_triggers_do_nothing(): void
    {
        $this->rule(['is_active' => false]);
        $this->rule(['trigger' => 'leave.approved', 'name' => 'Leave pack']);
        $this->hire();

        $this->assertSame(0, Task::where('project_id', $this->project)->count());
    }

    public function test_an_assignee_outside_the_project_is_left_unassigned_rather_than_failing(): void
    {
        $this->rule();
        Project::find($this->project)->members()->detach($this->hireUser->id);
        $this->hire();

        $first = Task::where('project_id', $this->project)->orderBy('id')->firstOrFail();
        $this->assertNull($first->assignee_id);
        $this->assertSame(3, Task::where('project_id', $this->project)->count());
    }

    public function test_rules_need_a_real_trigger_and_project_settings_rights(): void
    {
        $this->postJson('/api/hrms/automation-rules', ['name' => 'x', 'trigger' => 'employee.exploded', 'project_id' => $this->project, 'tasks' => [['title' => 't']]])
            ->assertUnprocessable()->assertJsonValidationErrors('trigger');

        $this->actAs($this->userWith(['hrms.view', 'hrms.onboarding.manage']));
        $this->postJson('/api/hrms/automation-rules', ['name' => 'x', 'trigger' => 'employee.created', 'project_id' => $this->project, 'tasks' => [['title' => 't']]])
            ->assertForbidden();

        $this->actAs($this->userWith(['hrms.view']));
        $this->getJson('/api/hrms/automation-rules')->assertForbidden();
    }

    public function test_the_bridge_also_publishes_hr_events_to_webhooks_without_personal_data(): void
    {
        config(['webhooks.resolver' => fn (string $host) => ['93.184.216.34']]);
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->setAcmeModules(['hrms.core', 'hrms.onboarding', 'webhooks']);
        $this->postJson('/api/webhooks', ['url' => 'https://hooks.example.com/hr', 'events' => ['employee.*']])->assertCreated();

        $this->makeEmployee('Boss');
        app(EmployeeService::class)->create(['name' => 'Secret Person', 'personal_email' => 'secret@example.com'], $this->actorUser());

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['type'] === 'employee.created' && ! str_contains($request->body(), 'secret@example.com') && ! str_contains($request->body(), 'Secret Person');
        });
    }
}
