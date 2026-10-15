<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Comment;
use App\Models\Label;
use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\SubscriptionPlan;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Workspace;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P3.4 / P3.5 — automation rules: validation, execution, guards, time triggers. */
class AutomationTest extends TestCase
{
    use IsolatesDatabase;

    private Project $project;

    private User $admin;

    private User $editor;

    private function setUpProject(): void
    {
        $this->admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $this->editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $ws = Workspace::create(['created_by' => $this->admin->id, 'name' => 'W', 'slug' => 'w']);
        $ws->members()->attach($this->admin->id, ['role' => 'owner', 'added_by' => $this->admin->id]);
        $ws->members()->attach($this->editor->id, ['role' => 'member', 'added_by' => $this->admin->id]);
        $this->project = Project::create(['workspace_id' => $ws->id, 'created_by' => $this->admin->id, 'lead_user_id' => $this->admin->id, 'name' => 'P', 'key' => 'PP']);
        $this->project->members()->attach($this->admin->id, ['project_role_id' => ProjectRole::where('slug', 'lead')->firstOrFail()->id, 'added_by' => $this->admin->id]);
        $this->project->members()->attach($this->editor->id, ['project_role_id' => ProjectRole::where('slug', 'developer')->firstOrFail()->id, 'added_by' => $this->admin->id]);
        foreach (config('task_statuses.statuses') as $s) {
            TaskStatus::create(['project_id' => $this->project->id, 'name' => $s['name'], 'slug' => $s['slug'], 'category' => $s['category'], 'position' => $s['position'], 'color' => $s['color'], 'is_done' => $s['is_done'], 'is_default' => $s['is_default'] ?? false]);
        }
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->connectTenant('acme'); // direct model reads after an HTTP login need the tenant connection back
    }

    private function rule(array $over = []): array
    {
        return $this->postJson("/api/projects/{$this->project->id}/automations", $over + [
            'name' => 'Escalate', 'trigger' => 'task.created', 'conditions' => [], 'actions' => [['type' => 'set_priority', 'priority' => 'high']],
        ])->assertCreated()->json('rule');
    }

    private function createTask(array $over = []): int
    {
        return $this->postJson("/api/projects/{$this->project->id}/tasks", $over + ['title' => 'A task'])->assertCreated()->json('task.id');
    }

    public function test_a_rule_runs_its_actions_when_its_event_and_conditions_match(): void
    {
        $this->setUpProject();
        $this->rule(['conditions' => [['field' => 'title', 'op' => 'contains', 'value' => 'urgent']], 'actions' => [
            ['type' => 'set_priority', 'priority' => 'highest'],
            ['type' => 'add_comment', 'text' => 'Escalated {{key}}: {{title}}'],
            ['type' => 'notify', 'to' => ['lead'], 'message' => '{{key}} needs attention'],
        ]]);

        $hit = $this->createTask(['title' => 'URGENT outage']);
        $miss = $this->createTask(['title' => 'Routine chore']);

        $this->assertSame('highest', Task::with('priority')->find($hit)->priority->slug);
        $this->assertNotSame('highest', Task::with('priority')->find($miss)->priority?->slug);
        $this->assertStringContainsString('Escalated PP-1: URGENT outage', Comment::where('task_id', $hit)->value('comment'));
        $this->assertSame(0, Comment::where('task_id', $miss)->count());
        $this->assertSame('success', AutomationRun::where('task_id', $hit)->value('status'));
        $this->assertSame('skipped', AutomationRun::where('task_id', $miss)->value('status'));
        $this->assertSame(1, AutomationRule::first()->run_count);   // a skipped run is logged but not counted
        $this->assertTrue(UserNotification::where('type', 'automation.notice')->where('user_id', $this->admin->id)->exists());
    }

    public function test_completion_rules_see_a_task_completed_by_a_move(): void
    {
        $this->setUpProject();
        $done = TaskStatus::where('project_id', $this->project->id)->where('is_done', true)->firstOrFail();
        $this->rule(['trigger' => 'task.completed', 'actions' => [['type' => 'add_comment', 'text' => 'Nice work.']]]);
        $id = $this->createTask();

        $this->postJson("/api/projects/{$this->project->id}/tasks/{$id}/move", ['status_id' => $done->id])->assertOk();

        $this->assertSame(['Nice work.'], Comment::where('task_id', $id)->pluck('comment')->all());
    }

    public function test_a_rule_that_triggers_itself_runs_once_and_chains_stop_at_depth_three(): void
    {
        $this->setUpProject();
        // "When a task is updated, set priority" — the rule's own update must not re-run it.
        $this->rule(['trigger' => 'task.updated', 'actions' => [['type' => 'set_priority', 'priority' => 'low']]]);
        $id = $this->createTask();

        $this->putJson("/api/projects/{$this->project->id}/tasks/{$id}", ['title' => 'Renamed'])->assertOk();

        $this->assertSame(1, AutomationRun::count());

        // Two rules feeding each other: A (updated → low) and B (updated → high) ping-pong, but not forever.
        $this->rule(['name' => 'B', 'trigger' => 'task.updated', 'actions' => [['type' => 'set_priority', 'priority' => 'high']]]);
        $this->putJson("/api/projects/{$this->project->id}/tasks/{$id}", ['title' => 'Renamed again'])->assertOk();

        $this->assertLessThan(10, AutomationRun::count());
    }

    public function test_a_failing_action_marks_the_run_failed_and_stops_the_rule(): void
    {
        $this->setUpProject();
        $done = TaskStatus::where('project_id', $this->project->id)->where('is_done', true)->firstOrFail();
        $blocker = Task::create(['workspace_id' => $this->project->workspace_id, 'project_id' => $this->project->id, 'created_by' => $this->admin->id, 'reporter_user_id' => $this->admin->id, 'status_id' => TaskStatus::where('project_id', $this->project->id)->where('is_done', false)->value('id'), 'key' => 'PP-90', 'sequence' => 90, 'title' => 'Blocker', 'position' => 1]);
        $this->rule(['actions' => [['type' => 'move_to_status', 'status' => $done->slug], ['type' => 'add_comment', 'text' => 'never reached']]]);

        $id = $this->createTask();
        TaskDependency::create(['task_id' => $id, 'depends_on_task_id' => $blocker->id, 'type' => 'blocks']);
        // The first run already happened at creation (before the dependency existed) and moved the task; replay on a new task with a blocker in place:
        $this->rule(['name' => 'On update', 'trigger' => 'task.updated', 'actions' => [['type' => 'move_to_status', 'status' => $done->slug], ['type' => 'add_comment', 'text' => 'never reached']]]);
        Task::whereKey($id)->update(['status_id' => TaskStatus::where('project_id', $this->project->id)->where('is_done', false)->value('id'), 'completed_at' => null]);
        Comment::query()->delete();

        $this->putJson("/api/projects/{$this->project->id}/tasks/{$id}", ['title' => 'trigger update'])->assertOk();

        $failed = AutomationRun::where('status', 'failed')->latest('id')->first();
        $this->assertNotNull($failed);
        $this->assertStringContainsString('stopped', $failed->summary);
        $this->assertSame(0, Comment::count());   // the second action did not run
    }

    public function test_rules_are_validated_against_the_catalog_and_the_project(): void
    {
        $this->setUpProject();
        $url = "/api/projects/{$this->project->id}/automations";
        $ok = ['name' => 'R', 'trigger' => 'task.created', 'conditions' => [], 'actions' => [['type' => 'add_comment', 'text' => 'hi']]];

        $this->postJson($url, ['trigger' => 'nope'] + $ok)->assertUnprocessable()->assertJsonValidationErrors('trigger');
        $this->postJson($url, ['actions' => []] + $ok)->assertUnprocessable()->assertJsonValidationErrors('actions');
        $this->postJson($url, ['conditions' => [['field' => 'status', 'op' => 'contains', 'value' => 'x']]] + $ok)->assertUnprocessable();
        $this->postJson($url, ['actions' => [['type' => 'set_priority', 'priority' => 'urgent-ish']]] + $ok)->assertUnprocessable();
        $this->postJson($url, ['actions' => [['type' => 'move_to_status', 'status' => 'no-such-status']]] + $ok)->assertUnprocessable();
        $foreignLabel = Label::create(['workspace_id' => Workspace::create(['created_by' => $this->admin->id, 'name' => 'Other', 'slug' => 'other'])->id, 'name' => 'x', 'color' => '#000']);
        $this->postJson($url, ['actions' => [['type' => 'add_label', 'label' => $foreignLabel->id]]] + $ok)->assertUnprocessable();
        $this->postJson($url, ['actions' => [['type' => 'set_assignee', 'user' => 99999]]] + $ok)->assertUnprocessable();
        $this->postJson($url, $ok)->assertCreated();
    }

    public function test_only_project_managers_may_manage_rules(): void
    {
        $this->setUpProject();
        $rule = $this->rule();
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();

        // A developer member can read the project but not manage its automation.
        $this->getJson("/api/projects/{$this->project->id}/automations")->assertForbidden();
        $this->putJson("/api/projects/{$this->project->id}/automations/{$rule['id']}", ['name' => 'x', 'trigger' => 'task.created', 'conditions' => [], 'actions' => [['type' => 'add_comment', 'text' => 'x']]])->assertForbidden();
    }

    public function test_a_rule_from_another_project_is_a_404_not_a_leak(): void
    {
        $this->setUpProject();
        $other = Project::create(['workspace_id' => $this->project->workspace_id, 'created_by' => $this->admin->id, 'lead_user_id' => $this->admin->id, 'name' => 'Q', 'key' => 'QQ']);
        $other->members()->attach($this->admin->id, ['project_role_id' => ProjectRole::where('slug', 'lead')->firstOrFail()->id, 'added_by' => $this->admin->id]);
        $rule = $this->rule();

        $this->deleteJson("/api/projects/{$other->id}/automations/{$rule['id']}")->assertNotFound();
        $this->getJson("/api/projects/{$other->id}/automations/{$rule['id']}/runs")->assertNotFound();
    }

    public function test_time_triggers_fire_once_per_window_and_only_where_a_rule_listens(): void
    {
        $this->setUpProject();
        $this->rule(['trigger' => 'task.overdue', 'actions' => [['type' => 'set_priority', 'priority' => 'highest']]]);
        $late = $this->createTask(['title' => 'Late', 'due_date' => now()->subDays(3)->toDateString()]);
        $fine = $this->createTask(['title' => 'Fine', 'due_date' => now()->addDays(5)->toDateString()]);

        Artisan::call('automation:time-triggers', ['--all' => true]);
        Artisan::call('automation:time-triggers', ['--all' => true]);   // a second sweep must not refire

        $this->assertSame('highest', Task::with('priority')->find($late)->priority->slug);
        $this->assertNotSame('highest', Task::with('priority')->find($fine)->priority?->slug);
        $this->assertSame(1, AutomationRun::where('task_id', $late)->count());
    }

    public function test_the_module_gate_applies(): void
    {
        $this->setUpProject();
        $plan = SubscriptionPlan::where('slug', 'starter')->firstOrFail();
        app(SubscriptionService::class)->assign(Tenant::where('slug', 'acme')->firstOrFail(), $plan);

        $this->getJson("/api/projects/{$this->project->id}/automations")->assertForbidden()->assertHeader('X-Module-Denied', 'automation');
    }
}
