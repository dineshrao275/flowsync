<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\TaskStatus;
use App\Models\TaskStatusHistory;
use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P3.1 / P3.2 — workflow transitions, entry rules and status history. */
class WorkflowTest extends TestCase
{
    use IsolatesDatabase;

    private Project $project;

    private TaskStatus $todo;

    private TaskStatus $doing;

    private TaskStatus $done;

    private function setUpProject(): void
    {
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $ws = Workspace::create(['created_by' => $admin->id, 'name' => 'W', 'slug' => 'w']);
        $ws->members()->attach($admin->id, ['role' => 'owner', 'added_by' => $admin->id]);
        $this->project = Project::create(['workspace_id' => $ws->id, 'created_by' => $admin->id, 'lead_user_id' => $admin->id, 'name' => 'P', 'key' => 'PP']);
        $this->project->members()->attach($admin->id, ['project_role_id' => ProjectRole::where('slug', 'lead')->firstOrFail()->id, 'added_by' => $admin->id]);
        foreach (config('task_statuses.statuses') as $s) {
            TaskStatus::create(['project_id' => $this->project->id, 'name' => $s['name'], 'slug' => $s['slug'], 'category' => $s['category'], 'position' => $s['position'], 'color' => $s['color'], 'is_done' => $s['is_done'], 'is_default' => $s['is_default'] ?? false]);
        }
        $statuses = TaskStatus::where('project_id', $this->project->id)->orderBy('position')->get();
        $this->todo = $statuses->first(fn ($s) => ! $s->is_done && $s->category->value === 'todo');
        $this->doing = $statuses->first(fn ($s) => $s->category->value === 'in_progress');
        $this->done = $statuses->first(fn ($s) => $s->is_done);
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');
    }

    private function task(array $over = []): int
    {
        return $this->postJson("/api/projects/{$this->project->id}/tasks", $over + ['title' => 'T', 'status_id' => $this->todo->id])->assertCreated()->json('task.id');
    }

    private function move(int $id, TaskStatus $to)
    {
        return $this->postJson("/api/projects/{$this->project->id}/tasks/{$id}/move", ['status_id' => $to->id]);
    }

    private function saveWorkflow(array $body)
    {
        return $this->putJson("/api/projects/{$this->project->id}/workflow", $body);
    }

    public function test_by_default_any_status_to_any_status_is_allowed(): void
    {
        $this->setUpProject();
        $id = $this->task();

        $this->move($id, $this->done)->assertOk();
        $this->move($id, $this->todo)->assertOk();
    }

    public function test_an_enforced_workflow_only_allows_listed_transitions(): void
    {
        $this->setUpProject();
        $this->saveWorkflow(['enforce_workflow' => true, 'transitions' => [
            ['from_status_id' => $this->todo->id, 'to_status_id' => $this->doing->id],
            ['from_status_id' => $this->doing->id, 'to_status_id' => $this->done->id],
        ]])->assertOk();
        $id = $this->task();

        $this->move($id, $this->done)->assertUnprocessable()->assertJsonPath('errors.form.0', "This project's workflow does not allow moving a task from {$this->todo->name} to {$this->done->name}.");
        $this->move($id, $this->doing)->assertOk();
        $this->move($id, $this->done)->assertOk();
        // The edit form is held to the same list.
        $this->putJson("/api/projects/{$this->project->id}/tasks/{$id}", ['status_id' => $this->todo->id])->assertUnprocessable();
    }

    public function test_a_from_any_transition_allows_entry_from_everywhere(): void
    {
        $this->setUpProject();
        $this->saveWorkflow(['enforce_workflow' => true, 'transitions' => [['from_status_id' => null, 'to_status_id' => $this->todo->id], ['from_status_id' => $this->todo->id, 'to_status_id' => $this->doing->id]]])->assertOk();
        $id = $this->task();
        $this->move($id, $this->doing)->assertOk();

        $this->move($id, $this->todo)->assertOk();   // doing → todo is allowed by the "from any" row
    }

    public function test_entry_rules_must_hold_and_one_edit_can_satisfy_them(): void
    {
        $this->setUpProject();
        $this->saveWorkflow(['enforce_workflow' => false, 'transitions' => [], 'entry_rules' => [(string) $this->done->id => ['assignee', 'due_date']]])->assertOk();
        $id = $this->task();

        $this->move($id, $this->done)->assertUnprocessable()->assertJsonPath('errors.form.0', "Moving to {$this->done->name} requires an assignee.");

        // Filling the missing field in the same edit that moves it is enough.
        $me = User::where('email', 'admin@flowsync.test')->value('id');
        $this->putJson("/api/projects/{$this->project->id}/tasks/{$id}", ['status_id' => $this->done->id, 'assignee_id' => $me])->assertUnprocessable()
            ->assertJsonPath('errors.form.0', "Moving to {$this->done->name} requires a due date.");
        $this->putJson("/api/projects/{$this->project->id}/tasks/{$id}", ['status_id' => $this->done->id, 'assignee_id' => $me, 'due_date' => now()->addDay()->toDateString()])->assertOk();
    }

    public function test_subtasks_done_rule_blocks_completing_a_parent(): void
    {
        $this->setUpProject();
        $this->saveWorkflow(['enforce_workflow' => false, 'transitions' => [], 'entry_rules' => [(string) $this->done->id => ['subtasks_done']]])->assertOk();
        $parent = $this->task();
        $child = $this->task(['title' => 'child', 'parent_id' => $parent]);

        $this->move($parent, $this->done)->assertUnprocessable();
        $this->move($child, $this->done)->assertOk();
        $this->move($parent, $this->done)->assertOk();
    }

    public function test_the_transitions_endpoint_says_where_a_task_can_go_and_why_not(): void
    {
        $this->setUpProject();
        $this->saveWorkflow(['enforce_workflow' => true, 'transitions' => [['from_status_id' => $this->todo->id, 'to_status_id' => $this->doing->id]], 'entry_rules' => [(string) $this->doing->id => ['assignee']]])->assertOk();
        $id = $this->task();

        $rows = collect($this->getJson("/api/projects/{$this->project->id}/tasks/{$id}/transitions")->assertOk()->json('transitions'))->keyBy('status_id');
        $this->assertTrue($rows[$this->doing->id]['allowed']);
        $this->assertStringContainsString('requires an assignee', $rows[$this->doing->id]['blocked_reason']);
        $this->assertFalse($rows[$this->done->id]['allowed']);
        $this->assertStringContainsString('does not allow', $rows[$this->done->id]['blocked_reason']);
    }

    public function test_enforcing_with_no_transitions_is_refused_and_unknown_statuses_are_rejected(): void
    {
        $this->setUpProject();

        $this->saveWorkflow(['enforce_workflow' => true, 'transitions' => []])->assertUnprocessable()->assertJsonValidationErrors('transitions');
        $this->saveWorkflow(['enforce_workflow' => false, 'transitions' => [['from_status_id' => null, 'to_status_id' => 99999]]])->assertUnprocessable();
        $this->saveWorkflow(['enforce_workflow' => false, 'transitions' => [], 'entry_rules' => [(string) $this->done->id => ['telepathy']]])->assertUnprocessable();
    }

    public function test_every_status_change_is_recorded_in_the_history(): void
    {
        $this->setUpProject();
        $id = $this->task();
        $this->move($id, $this->doing)->assertOk();
        $this->move($id, $this->done)->assertOk();

        $rows = TaskStatusHistory::where('task_id', $id)->orderBy('id')->get();
        $this->assertSame([null, $this->todo->id, $this->doing->id], $rows->pluck('from_status_id')->all());
        $this->assertSame([$this->todo->id, $this->doing->id, $this->done->id], $rows->pluck('to_status_id')->all());
        $this->assertNotNull($rows->last()->user_id);
        // A same-status save writes nothing.
        $this->putJson("/api/projects/{$this->project->id}/tasks/{$id}", ['title' => 'Renamed'])->assertOk();
        $this->assertSame(3, TaskStatusHistory::where('task_id', $id)->count());
    }

    public function test_only_workflow_managers_can_change_it_and_anyone_on_the_project_can_read_it(): void
    {
        $this->setUpProject();
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $this->project->workspace->members()->attach($editor->id, ['role' => 'member', 'added_by' => 1]);
        $this->project->members()->attach($editor->id, ['project_role_id' => ProjectRole::where('slug', 'developer')->firstOrFail()->id, 'added_by' => 1]);
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();

        $this->getJson("/api/projects/{$this->project->id}/workflow")->assertOk();
        $this->saveWorkflow(['enforce_workflow' => false, 'transitions' => []])->assertForbidden();
    }
}
