<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Member-based isolation: non-admins reach only what they belong to.
 *
 * Pins three closed holes: workflow writes scoped to the URL project
 * (a status from project B is 404 through project A), board/list rows
 * requiring the task-level read (a project-sight role without
 * `tasks.view` gets the same 403 as a per-row show), and performance
 * list endpoints self-scoping for callers without the broad view (the
 * feedback-request precedent — query params alone are spoofable).
 */
class MemberIsolationTest extends TestCase
{
    use IsolatesDatabase;

    public function test_workflow_writes_stay_inside_the_url_project(): void
    {
        [$projectA, $leadA] = $this->projectWith('Isolation A', 'IA');
        [$projectB] = $this->projectWith('Isolation B', 'IB');
        $foreign = $projectB->statuses()->firstOrFail();
        $this->actAs($leadA);

        $this->putJson("/api/projects/{$projectA->id}/statuses/{$foreign->id}", ['name' => 'Hijacked'])
            ->assertNotFound();
        $this->deleteJson("/api/projects/{$projectA->id}/statuses/{$foreign->id}")
            ->assertNotFound();

        $this->assertSame($foreign->name, $foreign->refresh()->name);
    }

    public function test_board_and_list_require_the_task_level_read(): void
    {
        [$project] = $this->projectWith('Isolation Sight', 'IS');
        $task = $this->task($project);

        $sightRole = ProjectRole::create([
            'name' => 'Sight Only',
            'slug' => 'sight-only',
            'permissions' => ['projects.view'],
        ]);

        $watcher = $this->userWith(['hrms.view']);
        $this->addProjectMember($project, $watcher, 'sight-only');
        $this->actAs($watcher);

        // Same 403 the per-row show answers — the pool is not a back door.
        $this->getJson("/api/projects/{$project->id}/tasks?view=board")->assertForbidden();
        $this->getJson("/api/projects/{$project->id}/tasks?view=list")->assertForbidden();
        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}")->assertForbidden();

        // And the row itself stays unreadable one by one.
        $this->assertSame($task->title, $task->refresh()->title);
    }

    public function test_performance_lists_scope_to_self_without_broad_view(): void
    {
        $cycle = $this->cycle();
        $anna = $this->employee('Isolation Anna', true);
        $bob = $this->employee('Isolation Bob', true);

        PerformanceGoal::create([
            'cycle_id' => $cycle->id, 'employee_id' => $anna->id,
            'title' => 'Anna goal.', 'metric_type' => 'manual', 'status' => 'draft',
        ]);
        PerformanceGoal::create([
            'cycle_id' => $cycle->id, 'employee_id' => $bob->id,
            'title' => 'Bob goal.', 'metric_type' => 'manual', 'status' => 'draft',
        ]);

        $this->actAs($anna->user);

        $goals = $this->getJson("/api/hrms/performance/cycles/{$cycle->id}/goals")->assertOk()->json('goals');
        $this->assertSame(['Anna goal.'], array_column($goals, 'title'));

        // A spoofed filter for Bob intersects with the enforced self-scope.
        $spoofed = $this->getJson("/api/hrms/performance/cycles/{$cycle->id}/goals?employee_id={$bob->id}")
            ->assertOk()->json('goals');
        $this->assertSame([], $spoofed);

        $checkIns = $this->getJson("/api/hrms/performance/cycles/{$cycle->id}/check-ins")->assertOk()->json('check_ins');
        $this->assertSame([], $checkIns);

        $rooms = $this->getJson('/api/hrms/performance/one-on-ones')->assertOk()->json('one_on_ones');
        $this->assertSame([], $rooms);
    }

    public function test_assigned_scope_strictly_isolates_records_outside_reporting_line(): void
    {
        $manager = $this->employee('Manager User', true);
        $report = Employee::create([
            'employee_code' => 'EMP-REP-ISO',
            'name' => 'Report ISO',
            'status' => EmployeeStatus::Active,
            'user_id' => $this->userWith(['hrms.view'])->id,
            'manager_id' => $manager->id,
        ]);
        $stranger = $this->employee('Stranger ISO', true);

        $cycle = $this->cycle();

        PerformanceGoal::create([
            'cycle_id' => $cycle->id,
            'employee_id' => $stranger->id,
            'title' => 'Stranger Goal.',
            'metric_type' => 'manual',
            'status' => 'draft',
        ]);
        $reportGoal = PerformanceGoal::create([
            'cycle_id' => $cycle->id,
            'employee_id' => $report->id,
            'title' => 'Report Goal.',
            'metric_type' => 'manual',
            'status' => 'draft',
        ]);

        $managerUser = $this->userWith(['hrms.view', 'hrms.performance.view_assigned']);
        $manager->update(['user_id' => $managerUser->id]);
        $this->actAs($managerUser);

        $goals = $this->getJson("/api/hrms/performance/cycles/{$cycle->id}/goals")->assertOk()->json('goals');
        $this->assertContains('Report Goal.', array_column($goals, 'title'));
        $this->assertNotContains('Stranger Goal.', array_column($goals, 'title'));

        $filtered = $this->getJson("/api/hrms/performance/cycles/{$cycle->id}/goals?employee_id={$stranger->id}")
            ->assertOk()->json('goals');
        $this->assertSame([], $filtered);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array{Project, User} Project with a lead attached.
     */
    private function projectWith(string $name, string $key): array
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $lead = $this->userWith(['hrms.view', 'workspaces.view']);
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();

        $workspace = Workspace::create([
            'created_by' => $admin->id, 'name' => "{$name} {$sequence}", 'slug' => "isolation-{$sequence}",
        ]);
        $workspace->members()->attach($lead->id, ['role' => 'owner', 'added_by' => $admin->id]);

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $lead->id,
            'lead_user_id' => $lead->id,
            'name' => "{$name} {$sequence}",
            'key' => $key.$sequence,
        ]);

        foreach (config('task_statuses.statuses') as $status) {
            $project->statuses()->create([
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => $status['category'],
                'color' => $status['color'] ?? null,
                'position' => $status['position'] ?? 1,
                'is_default' => $status['is_default'] ?? false,
                'is_done' => $status['is_done'] ?? false,
            ]);
        }

        $this->addProjectMember($project, $lead, 'lead');

        return [$project, $lead];
    }

    private function task(Project $project): Task
    {
        $this->connectTenant('acme');
        $project->increment('last_task_sequence');
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();

        return Task::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $admin->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => 'Isolated task',
            'status_id' => $project->statuses()->where('is_default', true)->first()->id,
            'position' => 1,
        ]);
    }

    private function addProjectMember(Project $project, User $user, string $role): void
    {
        $this->connectTenant('acme');
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();

        $project->members()->attach($user->id, [
            'project_role_id' => ProjectRole::where('slug', $role)->firstOrFail()->id,
            'added_by' => $admin->id,
        ]);
    }

    private function employee(string $name, bool $withUser = false): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $user = $withUser ? $this->userWith(['hrms.view']) : null;

        $employee = Employee::create([
            'employee_code' => "EMP-ISO-{$sequence}",
            'name' => "{$name} {$sequence}",
            'status' => EmployeeStatus::Active,
            'user_id' => $user?->id,
        ]);

        if ($user !== null) {
            $employee->setRelation('user', $user);
        }

        return $employee;
    }

    private function cycle(): PerformanceCycle
    {
        $this->connectTenant('acme');

        return PerformanceCycle::firstOrCreate(
            ['slug' => 'iso-2026'],
            ['name' => 'Isolation 2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31'],
        );
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
            'name' => "Isolation User {$sequence}",
            'email' => "isolation.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Isolation Role {$sequence}",
            'slug' => "isolation-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
