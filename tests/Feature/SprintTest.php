<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Sprint;
use App\Models\SprintTaskEvent;
use App\Models\SubscriptionPlan;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskStatusHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P4.2 / P4.5 — sprints, backlog, burndown, velocity, flow. */
class SprintTest extends TestCase
{
    use IsolatesDatabase;

    private Project $project;

    private TaskStatus $todo;

    private TaskStatus $doing;

    private TaskStatus $done;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $ws = Workspace::create(['created_by' => $admin->id, 'name' => 'W', 'slug' => 'w']);
        $ws->members()->attach($admin->id, ['role' => 'owner', 'added_by' => $admin->id]);
        $this->project = Project::create(['workspace_id' => $ws->id, 'created_by' => $admin->id, 'lead_user_id' => $admin->id, 'name' => 'P', 'key' => 'PP']);
        $this->project->members()->attach($admin->id, ['project_role_id' => ProjectRole::where('slug', 'lead')->firstOrFail()->id, 'added_by' => $admin->id]);
        foreach (config('task_statuses.statuses') as $s) {
            TaskStatus::create(['project_id' => $this->project->id, 'name' => $s['name'], 'slug' => $s['slug'], 'category' => $s['category'], 'position' => $s['position'], 'color' => $s['color'], 'is_done' => $s['is_done'], 'is_default' => $s['is_default'] ?? false]);
        }
        $statuses = TaskStatus::where('project_id', $this->project->id)->orderBy('position')->get();
        $this->todo = $statuses->first(fn ($s) => $s->category->value === 'todo');
        $this->doing = $statuses->first(fn ($s) => $s->category->value === 'in_progress');
        $this->done = $statuses->first(fn ($s) => $s->is_done);
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');
    }

    private function task(string $title, ?float $points = null): int
    {
        return $this->postJson("/api/projects/{$this->project->id}/tasks", ['title' => $title, 'story_points' => $points, 'status_id' => $this->todo->id])->assertCreated()->json('task.id');
    }

    private function sprint(string $name = 'Sprint 1', array $over = []): int
    {
        return $this->postJson("/api/projects/{$this->project->id}/sprints", $over + ['name' => $name])->assertCreated()->json('sprint.id');
    }

    private function url(string $path = ''): string
    {
        return "/api/projects/{$this->project->id}/sprints{$path}";
    }

    public function test_the_backlog_is_unplanned_work_and_planning_moves_tasks_into_a_sprint(): void
    {
        $a = $this->task('A', 3);
        $b = $this->task('B', 5);
        $sprint = $this->sprint();

        $this->assertCount(2, $this->getJson($this->url())->assertOk()->json('backlog'));
        $this->postJson($this->url("/{$sprint}/tasks"), ['task_ids' => [$a]])->assertOk()->assertJsonPath('added', 1);

        $list = $this->getJson($this->url())->json();
        $this->assertSame([$b], array_column($list['backlog'], 'id'));
        $this->assertSame(1, $list['sprints'][0]['tasks_count']);
        $this->assertEquals(3, $list['sprints'][0]['points']);
        $this->assertSame($sprint, Task::find($a)->sprint_id);

        $this->deleteJson($this->url("/{$sprint}/tasks/{$a}"))->assertOk();
        $this->assertNull(Task::find($a)->sprint_id);
    }

    public function test_only_one_sprint_is_active_and_starting_records_the_commitment(): void
    {
        $a = $this->task('A', 3);
        $b = $this->task('B', 2);
        $s1 = $this->sprint('S1');
        $s2 = $this->sprint('S2');
        $this->postJson($this->url("/{$s1}/tasks"), ['task_ids' => [$a, $b]])->assertOk();

        $this->postJson($this->url("/{$s1}/start"))->assertOk()->assertJsonPath('sprint.status', 'active')->assertJsonPath('sprint.committed_points', 5);
        $this->postJson($this->url("/{$s2}/start"))->assertUnprocessable();
        $this->postJson($this->url("/{$s1}/start"))->assertUnprocessable();   // not planned any more
        $this->deleteJson($this->url("/{$s1}"))->assertUnprocessable();       // only planned sprints go
    }

    public function test_completing_carries_unfinished_work_to_the_backlog_or_the_next_sprint(): void
    {
        $finished = $this->task('Done one', 3);
        $left = $this->task('Left over', 5);
        $s1 = $this->sprint('S1');
        $s2 = $this->sprint('S2');
        $this->postJson($this->url("/{$s1}/tasks"), ['task_ids' => [$finished, $left]])->assertOk();
        $this->postJson($this->url("/{$s1}/start"))->assertOk();
        $this->postJson("/api/projects/{$this->project->id}/tasks/{$finished}/move", ['status_id' => $this->done->id])->assertOk();

        $this->postJson($this->url("/{$s1}/complete"), ['leftover' => 'sprint'])->assertUnprocessable()->assertJsonValidationErrors('target_sprint_id');
        $this->postJson($this->url("/{$s1}/complete"), ['leftover' => 'sprint', 'target_sprint_id' => $s2])->assertOk()
            ->assertJsonPath('sprint.status', 'completed')->assertJsonPath('sprint.completed_points', 3)->assertJsonPath('sprint.committed_points', 8);

        $this->assertSame($s2, Task::find($left)->sprint_id);          // carried over
        $this->assertSame($s1, Task::find($finished)->sprint_id);       // the finished one stays as a record
        $this->postJson($this->url("/{$s1}/tasks"), ['task_ids' => [$left]])->assertUnprocessable();   // completed = closed
    }

    public function test_leftovers_can_go_back_to_the_backlog(): void
    {
        $left = $this->task('Left', 2);
        $s1 = $this->sprint();
        $this->postJson($this->url("/{$s1}/tasks"), ['task_ids' => [$left]]);
        $this->postJson($this->url("/{$s1}/start"));

        $this->postJson($this->url("/{$s1}/complete"), ['leftover' => 'backlog'])->assertOk();

        $this->assertNull(Task::find($left)->sprint_id);
    }

    public function test_subtasks_and_foreign_tasks_cannot_be_planned(): void
    {
        $parent = $this->task('Parent');
        $child = $this->postJson("/api/projects/{$this->project->id}/tasks", ['title' => 'child', 'parent_id' => $parent])->json('task.id');
        $s1 = $this->sprint();

        $this->postJson($this->url("/{$s1}/tasks"), ['task_ids' => [$child]])->assertUnprocessable();
        $this->postJson($this->url("/{$s1}/tasks"), ['task_ids' => [999999]])->assertUnprocessable();
    }

    public function test_the_board_can_be_filtered_to_the_active_sprint(): void
    {
        $in = $this->task('In sprint');
        $this->task('Backlog only');
        $s1 = $this->sprint();
        $this->postJson($this->url("/{$s1}/tasks"), ['task_ids' => [$in]]);
        $this->postJson($this->url("/{$s1}/start"));

        $list = $this->getJson("/api/projects/{$this->project->id}/tasks?view=list&sprint=active")->assertOk()->json('tasks');
        $this->assertSame([$in], array_column($list, 'id'));
        $none = $this->getJson("/api/projects/{$this->project->id}/tasks?view=list&sprint=none")->json('tasks');
        $this->assertCount(1, $none);
    }

    public function test_burndown_follows_scope_and_completions_day_by_day(): void
    {
        $a = $this->task('A', 3);
        $b = $this->task('B', 5);
        $s1 = $this->sprint('S1', ['start_date' => '2026-06-01', 'end_date' => '2026-06-05']);
        $sprint = Sprint::findOrFail($s1);
        // Plan, start and progress on fixed dates: scope events + status history written by hand.
        SprintTaskEvent::insert([
            ['sprint_id' => $s1, 'task_id' => $a, 'type' => 'added', 'points' => 3, 'at' => '2026-05-31 10:00:00'],
            ['sprint_id' => $s1, 'task_id' => $b, 'type' => 'added', 'points' => 5, 'at' => '2026-05-31 10:00:00'],
        ]);
        $sprint->update(['status' => 'completed', 'started_at' => '2026-06-01 09:00:00', 'completed_at' => '2026-06-05 17:00:00']);
        TaskStatusHistory::insert([
            ['task_id' => $a, 'from_status_id' => $this->todo->id, 'to_status_id' => $this->done->id, 'user_id' => 1, 'changed_at' => '2026-06-02 12:00:00'],
            ['task_id' => $b, 'from_status_id' => $this->todo->id, 'to_status_id' => $this->done->id, 'user_id' => 1, 'changed_at' => '2026-06-04 12:00:00'],
        ]);

        $days = collect($this->getJson("/api/projects/{$this->project->id}/agile-reports?sprint={$s1}")->assertOk()->json('burndown.days'))->keyBy('date');

        $this->assertEquals(8, $days['2026-06-01']['remaining']);
        $this->assertEquals(5, $days['2026-06-02']['remaining']);   // A (3) finished
        $this->assertEquals(5, $days['2026-06-03']['remaining']);
        $this->assertEquals(0, $days['2026-06-04']['remaining']);   // B (5) finished
        $this->assertEquals(8, $days['2026-06-01']['ideal']);
        $this->assertEquals(0, $days['2026-06-05']['ideal']);
    }

    public function test_velocity_flow_and_throughput_come_from_recorded_data(): void
    {
        $s1 = Sprint::create(['project_id' => $this->project->id, 'name' => 'S1', 'status' => 'completed', 'committed_points' => 10, 'completed_points' => 8, 'completed_at' => now()->subWeeks(3)]);
        Sprint::create(['project_id' => $this->project->id, 'name' => 'S2', 'status' => 'completed', 'committed_points' => 12, 'completed_points' => 12, 'completed_at' => now()->subWeek()]);

        $t = $this->task('Flows');
        Task::whereKey($t)->update(['created_at' => Carbon::now()->subDays(10)]);
        $this->postJson("/api/projects/{$this->project->id}/tasks/{$t}/move", ['status_id' => $this->doing->id])->assertOk();
        TaskStatusHistory::where('task_id', $t)->where('to_status_id', $this->doing->id)->update(['changed_at' => Carbon::now()->subDays(4)]);
        $this->postJson("/api/projects/{$this->project->id}/tasks/{$t}/move", ['status_id' => $this->done->id])->assertOk();

        $r = $this->getJson("/api/projects/{$this->project->id}/agile-reports")->assertOk()->json();

        $this->assertEquals([['sprint' => 'S1', 'committed' => 10, 'completed' => 8], ['sprint' => 'S2', 'committed' => 12, 'completed' => 12]], $r['velocity']);
        $this->assertSame(1, $r['flow']['completed']);
        $this->assertEqualsWithDelta(10.0, $r['flow']['lead_time']['avg'], 0.2);
        $this->assertEqualsWithDelta(4.0, $r['flow']['cycle_time']['avg'], 0.2);
        $this->assertSame(1, array_sum(array_column($r['throughput'], 'completed')));
        $this->assertCount(8, $r['throughput']);
        unset($s1);
    }

    public function test_planning_needs_settings_rights_but_reading_does_not_and_the_module_gate_applies(): void
    {
        $dev = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $this->project->workspace->members()->attach($dev->id, ['role' => 'member', 'added_by' => 1]);
        $this->project->members()->attach($dev->id, ['project_role_id' => ProjectRole::where('slug', 'developer')->firstOrFail()->id, 'added_by' => 1]);
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();

        $this->getJson($this->url())->assertOk();
        $this->postJson($this->url(), ['name' => 'Nope'])->assertForbidden();

        $this->postJson('/api/auth/logout');
        $plan = SubscriptionPlan::where('slug', 'starter')->firstOrFail();
        app(SubscriptionService::class)->assign(Tenant::where('slug', 'acme')->firstOrFail(), $plan);
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->getJson($this->url())->assertForbidden()->assertHeader('X-Module-Denied', 'sprints');
    }
}
