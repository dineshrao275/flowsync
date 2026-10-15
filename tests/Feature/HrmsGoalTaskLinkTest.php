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
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P12.6 — tasks evidencing goals.
 *
 * Links attach by id or by key and surface on the goal card; duplicates
 * and sealed cycles refuse; a foreign task id 404s rather than unlinking
 * wrong; and linking answers to the goal's own update ability.
 */
class HrmsGoalTaskLinkTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_task_links_by_id_and_surfaces_on_the_card(): void
    {
        [$goal, $task] = $this->linked(false);
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view', 'hrms.performance.manage']));

        $body = $this->postJson("/api/hrms/performance/goals/{$goal->id}/tasks", [
            'task_id' => $task->id,
        ])->assertCreated()->json('goal');

        $this->assertSame($task->key, $body['tasks'][0]['key']);

        // Twice is a duplicate, not a second row.
        $this->postJson("/api/hrms/performance/goals/{$goal->id}/tasks", [
            'task_id' => $task->id,
        ])->assertStatus(422);
    }

    public function test_a_task_links_by_key(): void
    {
        [$goal, $task] = $this->linked(false);
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view', 'hrms.performance.manage']));

        $this->postJson("/api/hrms/performance/goals/{$goal->id}/tasks", [
            'task_key' => $task->key,
        ])->assertCreated()->assertJsonPath('goal.tasks.0.id', $task->id);
    }

    public function test_unlinking_needs_belonging(): void
    {
        [$goal, $task] = $this->linked(true);
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view', 'hrms.performance.manage']));

        $other = Task::create([
            'workspace_id' => $task->workspace_id,
            'project_id' => $task->project_id,
            'created_by' => $task->created_by,
            'key' => 'EVD-9',
            'sequence' => 9,
            'title' => 'Unrelated.',
            'status_id' => $task->status_id,
            'position' => 9,
        ]);

        $this->deleteJson("/api/hrms/performance/goals/{$goal->id}/tasks/{$other->id}")->assertNotFound();

        $body = $this->deleteJson("/api/hrms/performance/goals/{$goal->id}/tasks/{$task->id}")
            ->assertOk()->json('goal');

        $this->assertSame([], $body['tasks']);
        $this->assertDatabaseMissing('goal_task_links', ['goal_id' => $goal->id, 'task_id' => $task->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_a_sealed_cycle_refuses_links(): void
    {
        [$goal, $task] = $this->linked(false);
        $goal->cycle->update(['stage' => 'completed']);
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view', 'hrms.performance.manage']));

        $this->postJson("/api/hrms/performance/goals/{$goal->id}/tasks", [
            'task_id' => $task->id,
        ])->assertStatus(422);
    }

    public function test_linking_answers_to_the_goal_update_ability(): void
    {
        [$goal, $task] = $this->linked(false);
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view']));

        $this->postJson("/api/hrms/performance/goals/{$goal->id}/tasks", [
            'task_id' => $task->id,
        ])->assertForbidden();
    }

    // ------------------------------------------------------------ helpers

    /**
     * A draft goal plus one task, optionally pre-linked.
     *
     * @return array{PerformanceGoal, Task}
     */
    private function linked(bool $prelink): array
    {
        $this->connectTenant('acme');

        $user = User::create([
            'name' => 'Link User', 'email' => 'link.user@flowsync.test', 'password' => 'password',
        ]);
        $employee = Employee::create([
            'employee_code' => 'EMP-LINK', 'name' => 'Link Employee',
            'status' => EmployeeStatus::Active, 'user_id' => $user->id,
        ]);

        $cycle = PerformanceCycle::create([
            'name' => 'Link Cycle', 'slug' => 'link-cycle',
            'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
        ]);
        $goal = PerformanceGoal::create([
            'cycle_id' => $cycle->id, 'employee_id' => $employee->id,
            'title' => 'Linked goal.', 'metric_type' => 'manual',
            'weight' => '100', 'status' => 'draft',
        ]);

        $workspace = Workspace::create(['created_by' => $user->id, 'name' => 'Link', 'slug' => 'link']);
        $project = Project::create([
            'workspace_id' => $workspace->id, 'created_by' => $user->id,
            'lead_user_id' => $user->id, 'name' => 'Link', 'key' => 'LNK',
        ]);
        $status = TaskStatus::create([
            'project_id' => $project->id, 'name' => 'Todo', 'slug' => 'todo',
            'category' => 'todo', 'position' => 10, 'is_default' => true, 'is_done' => false,
        ]);
        $role = ProjectRole::where('slug', 'developer')->firstOrFail();
        $project->members()->attach($user->id, ['project_role_id' => $role->id, 'added_by' => $user->id]);

        $task = Task::create([
            'workspace_id' => $workspace->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'key' => 'LNK-1', 'sequence' => 1,
            'title' => 'Linked task.', 'status_id' => $status->id,
            'position' => 1, 'assignee_id' => $user->id,
        ]);

        if ($prelink) {
            $goal->taskLinks()->create(['task_id' => $task->id]);
        }

        return [$goal->refresh(), $task->refresh()];
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
            'name' => "Link Api User {$sequence}",
            'email' => "link.api.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Link Api Role {$sequence}",
            'slug' => "link-api-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
