<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Activity;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\TaskLink\TaskLink;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P20.2 — the bridge rows over HTTP.
 *
 * Links show on both sides (the task's list and the employee's tasks) and
 * unlinking removes them; relinking is idempotent, never a duplicate claim.
 * The task side answers to the project policy (view to read, edit to file
 * or cut) and the employee's list intersects with the caller's visible
 * tasks — a link never widens what the login may see.
 */
class HrmsTaskLinkApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_links_show_on_both_sides_and_unlink_removes_them(): void
    {
        [$project, $lead] = $this->projectWith('Links Both', 'LB');
        $task = $this->task($project);
        $employee = $this->employee('EMP-LINK-BOTH', 'Link Both');
        $this->actAs($lead);

        $link = $this->postJson("/api/hrms/tasks/{$task->id}/links", [
            'employee_id' => $employee->id, 'kind' => 'goal', 'note' => 'Q3 evidence.',
        ])->assertCreated()->json('task_link');

        $this->assertSame('Goal', $link['kind_label']);

        $taskLinks = $this->getJson("/api/hrms/tasks/{$task->id}/links")->assertOk()->json('task_links');
        $this->assertCount(1, $taskLinks);
        $this->assertSame($employee->id, $taskLinks[0]['employee']['id']);

        $this->actAs($lead);
        $employeeTasks = $this->getJson("/api/hrms/employees/{$employee->id}/tasks")->assertOk()->json('tasks');
        $this->assertCount(1, $employeeTasks);
        $this->assertSame($task->id, $employeeTasks[0]['id']);
        $this->assertSame('goal', $employeeTasks[0]['link']['kind']);

        $this->assertTrue(Activity::query()
            ->where('subject_type', Task::class)
            ->where('subject_id', $task->id)
            ->where('action', 'task.link_created')
            ->exists());

        $this->actAs($lead);
        $this->deleteJson("/api/hrms/tasks/{$task->id}/links/{$link['id']}")->assertOk();

        $this->assertSame([], $this->getJson("/api/hrms/tasks/{$task->id}/links")->assertOk()->json('task_links'));
        $this->assertTrue(Activity::query()->where('action', 'task.link_deleted')->exists());
    }

    public function test_relinking_is_idempotent(): void
    {
        [$project, $lead] = $this->projectWith('Links Idem', 'LI');
        $task = $this->task($project);
        $employee = $this->employee('EMP-LINK-IDEM', 'Link Idem');
        $this->actAs($lead);

        $payload = ['employee_id' => $employee->id, 'kind' => 'onboarding'];

        $this->postJson("/api/hrms/tasks/{$task->id}/links", $payload)->assertCreated();
        $this->postJson("/api/hrms/tasks/{$task->id}/links", $payload)->assertOk();

        $this->assertSame(1, TaskLink::query()->count());
    }

    public function test_writes_need_task_edit_and_strangers_see_nothing(): void
    {
        [$project, $lead] = $this->projectWith('Links Gates', 'LG');
        $task = $this->task($project);
        $employee = $this->employee('EMP-LINK-GATE', 'Link Gate');

        $viewer = $this->userWith(['hrms.view']);
        $this->addProjectMember($project, $viewer, 'viewer');

        $this->actAs($viewer);
        $this->getJson("/api/hrms/tasks/{$task->id}/links")->assertOk();
        $this->postJson("/api/hrms/tasks/{$task->id}/links", [
            'employee_id' => $employee->id, 'kind' => 'goal',
        ])->assertForbidden();

        // A login outside the project fails the task policy, not the HRMS one.
        $this->actAs($this->userWith(['hrms.view', 'hrms.employees.view']));
        $this->getJson("/api/hrms/tasks/{$task->id}/links")->assertForbidden();

        // A foreign link id under a real task is a 404, not a cut.
        $this->actAs($lead);
        $other = $this->task($project);
        $link = TaskLink::create([
            'employee_id' => $employee->id, 'task_id' => $other->id, 'kind' => 'goal',
        ]);
        $this->deleteJson("/api/hrms/tasks/{$task->id}/links/{$link->id}")->assertNotFound();

        // A task id from another tenant resolves to nothing here.
        $this->actAs($this->globexLead(), 'globex');
        $this->getJson("/api/hrms/tasks/{$task->id}/links")->assertNotFound();
    }

    public function test_employee_tasks_respect_visibility_and_filters(): void
    {
        [$visible, $lead] = $this->projectWith('Links Seen', 'LS');
        [$hidden, $hiddenLead] = $this->projectWith('Links Hid', 'LH');
        $seen = $this->task($visible);
        $unseen = $this->task($hidden);
        $employee = $this->employee('EMP-LINK-VIS', 'Link Vis');

        $this->actAs($lead);
        $this->postJson("/api/hrms/tasks/{$seen->id}/links", [
            'employee_id' => $employee->id, 'kind' => 'goal',
        ])->assertCreated();
        $this->actAs($hiddenLead);
        $this->postJson("/api/hrms/tasks/{$unseen->id}/links", [
            'employee_id' => $employee->id, 'kind' => 'leave',
        ])->assertCreated();

        // The caller sits on the seen project only (lead sits on both).
        $reader = $this->userWith(['hrms.view', 'hrms.employees.view']);
        $this->addProjectMember($visible, $reader, 'developer');
        $this->actAs($reader);

        $tasks = $this->getJson("/api/hrms/employees/{$employee->id}/tasks")->assertOk()->json('tasks');
        $this->assertSame([$seen->id], collect($tasks)->pluck('id')->all());

        $this->assertSame([], $this->getJson("/api/hrms/employees/{$employee->id}/tasks?kind=leave")->assertOk()->json('tasks'));
        $this->assertCount(1, $this->getJson("/api/hrms/employees/{$employee->id}/tasks?kind=goal")->assertOk()->json('tasks'));
        $this->assertCount(
            1,
            $this->getJson("/api/hrms/employees/{$employee->id}/tasks?status_id={$seen->status_id}")->assertOk()->json('tasks'),
        );
        $this->getJson('/api/hrms/employees/'.$employee->id.'/tasks?status_id=999999')->assertStatus(422);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array{Project, User} Project with the lead attached.
     */
    private function projectWith(string $name, string $key): array
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $lead = $this->userWith(['hrms.view', 'hrms.employees.view']);
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();

        $workspace = Workspace::create([
            'created_by' => $admin->id, 'name' => "{$name} {$sequence}", 'slug' => 'links-'.$sequence,
        ]);

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $admin->id,
            'lead_user_id' => $admin->id,
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
            'title' => "Link task {$project->last_task_sequence}",
            'status_id' => $project->statuses()->where('is_default', true)->first()->id,
            'position' => 1,
        ]);
    }

    private function employee(string $code, string $name): Employee
    {
        $this->connectTenant('acme');

        return Employee::create([
            'employee_code' => $code, 'name' => $name,
            'status' => EmployeeStatus::Active,
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

    private function globexLead(): User
    {
        $this->connectTenant('globex');

        $user = User::create([
            'name' => 'Globex Lead',
            'email' => 'globex.lead@flowsync.test',
            'password' => 'password',
        ]);

        $role = Role::create(['name' => 'Globex Lead', 'slug' => 'globex-link-lead']);
        $role->permissions()->sync(
            Permission::whereIn('slug', ['hrms.view'])->pluck('id')->all(),
        );
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function actAs(User $user, string $tenant = 'acme'): void
    {
        $this->connectTenant($tenant);
        $central = Tenant::query()->where('slug', $tenant)->firstOrFail();
        $this->actingAs($user)->withSession(['login.tenant_id' => $central->id]);
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
            'name' => "Link User {$sequence}",
            'email' => "link.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Link Role {$sequence}",
            'slug' => "link-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
