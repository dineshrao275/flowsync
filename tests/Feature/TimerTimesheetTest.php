<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F3.3 — start/stop timer, billable flag and weekly timesheets with submit / approve / reject. */
class TimerTimesheetTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private int $project;

    /** @var list<int> */
    private array $tasks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['time_tracking', 'timesheets']);
        $this->loginAdmin();

        $workspace = $this->postJson('/api/workspaces', ['name' => 'TS WS'])->assertCreated()->json('workspace.id');
        $this->project = $this->postJson("/api/workspaces/{$workspace}/projects", ['name' => 'TS Project', 'key' => 'TS'])->assertCreated()->json('project.id');
        foreach (['First', 'Second'] as $title) {
            $this->tasks[] = $this->postJson("/api/projects/{$this->project}/tasks", ['title' => $title])->assertCreated()->json('task.id');
        }

        // The editor joins as a developer so they can log time.
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        Workspace::first()->members()->syncWithoutDetaching([$editor->id => ['role' => 'member', 'added_by' => $editor->id]]);
        Project::find($this->project)->members()->attach($editor->id, ['project_role_id' => ProjectRole::where('slug', 'developer')->value('id'), 'added_by' => $editor->id]);
    }

    private function as(string $email): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');
    }

    private function log(int $task, float $hours = 2, array $extra = []): array
    {
        $start = Carbon::now()->startOfWeek(Carbon::MONDAY)->addHours(9);

        return $this->postJson("/api/projects/{$this->project}/tasks/{$task}/work-logs", $extra + ['description' => 'Work',
            'started_at' => $start->toIso8601String(),
            'ended_at' => $start->copy()->addMinutes((int) ($hours * 60))->toIso8601String(),
        ])->assertCreated()->json('work_log');
    }

    public function test_starting_a_second_timer_stops_the_first(): void
    {
        $this->postJson("/api/projects/{$this->project}/tasks/{$this->tasks[0]}/timer/start")->assertCreated()->assertJsonPath('timer.task_id', $this->tasks[0]);
        $second = $this->postJson("/api/projects/{$this->project}/tasks/{$this->tasks[1]}/timer/start")->assertCreated();

        $second->assertJsonPath('timer.task_id', $this->tasks[1]);
        $this->assertNotNull($second->json('stopped.ended_at'));
        $this->getJson('/api/time/timer')->assertOk()->assertJsonPath('timer.task_id', $this->tasks[1]);

        $this->postJson('/api/time/timer/stop')->assertOk()->assertJsonPath('timer', null);
        $this->getJson('/api/time/timer')->assertOk()->assertJsonPath('timer', null);
        $this->postJson('/api/time/timer/stop')->assertOk();
    }

    public function test_billable_defaults_to_true_and_can_be_switched_off(): void
    {
        $this->assertTrue($this->log($this->tasks[0], 1)['billable']);
        $this->assertFalse($this->log($this->tasks[1], 1, ['billable' => false])['billable']);

        $week = $this->getJson('/api/timesheets/week')->assertOk()->json('timesheet');
        $this->assertSame(120, $week['total_minutes']);
        $this->assertSame(60, $week['billable_minutes']);
    }

    public function test_submitting_locks_the_week_and_an_approver_decides(): void
    {
        $this->as('editor@flowsync.test');
        $this->log($this->tasks[0], 3);

        $this->postJson('/api/timesheets/submit')->assertOk()->assertJsonPath('timesheet.status', 'submitted')->assertJsonPath('timesheet.locked', true);
        // Frozen: neither a new log in that week nor a repeat submit.
        $start = Carbon::now()->startOfWeek(Carbon::MONDAY)->addHours(14);
        $this->postJson("/api/projects/{$this->project}/tasks/{$this->tasks[1]}/work-logs", ['description' => 'Work', 'started_at' => $start->toIso8601String(), 'ended_at' => $start->copy()->addHour()->toIso8601String()])
            ->assertUnprocessable();
        $this->postJson('/api/timesheets/submit')->assertUnprocessable();
        // A submitter cannot decide their own sheet.
        $id = $this->getJson('/api/timesheets/week')->json('timesheet.week_start');
        $this->getJson('/api/timesheets/pending')->assertOk()->assertJsonCount(0, 'timesheets');

        $this->as('admin@flowsync.test');
        $pending = $this->getJson('/api/timesheets/pending')->assertOk()->assertJsonCount(1, 'timesheets')->json('timesheets.0');
        $this->assertSame(180, $pending['total_minutes']);

        $this->postJson("/api/timesheets/{$pending['id']}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/api/timesheets/{$pending['id']}/reject", ['note' => 'Wrong task'])->assertOk()->assertJsonPath('timesheet.status', 'rejected');

        // Rejection unlocks it: the owner can fix and resubmit, then it is approved and frozen again.
        $this->as('editor@flowsync.test');
        $this->getJson('/api/timesheets/week')->assertJsonPath('timesheet.status', 'rejected')->assertJsonPath('timesheet.locked', false);
        $this->log($this->tasks[1], 1);
        $this->postJson('/api/timesheets/submit')->assertOk();

        $this->as('admin@flowsync.test');
        $this->postJson("/api/timesheets/{$pending['id']}/approve")->assertOk()->assertJsonPath('timesheet.status', 'approved');
        $this->postJson("/api/timesheets/{$pending['id']}/approve")->assertUnprocessable();
        $this->assertNotEmpty($id);
    }

    public function test_owners_cannot_approve_themselves_and_outsiders_cannot_decide(): void
    {
        $this->log($this->tasks[0], 1); // the admin's own week
        $sheet = $this->postJson('/api/timesheets/submit')->assertOk();
        $own = Timesheet::first();

        $this->postJson("/api/timesheets/{$own->id}/approve")->assertForbidden();

        $this->as('editor@flowsync.test');
        $this->postJson("/api/timesheets/{$own->id}/approve")->assertForbidden();
        $this->getJson("/api/timesheets/{$own->id}")->assertForbidden();
        $this->assertNotNull($sheet);
    }

    public function test_an_empty_week_and_a_running_timer_block_submission(): void
    {
        $this->postJson('/api/timesheets/submit')->assertUnprocessable();

        $this->postJson("/api/projects/{$this->project}/tasks/{$this->tasks[0]}/timer/start")->assertCreated();
        $this->postJson('/api/timesheets/submit')->assertUnprocessable();
    }

    public function test_timesheets_are_gated_by_their_module_but_the_timer_is_not(): void
    {
        $this->setAcmeModules(['time_tracking']);

        $this->getJson('/api/timesheets/week')->assertForbidden();
        $this->getJson('/api/time/timer')->assertOk();
    }
}
