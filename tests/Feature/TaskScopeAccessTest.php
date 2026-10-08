<?php

namespace Tests\Feature;

use App\Models\Hrms\Employee\Employee;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Database\Factories\Hrms\EmployeeFactory;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase C of the member-access plan, step 9: TaskPolicy and the board/list
 * query enforce the `tasks.*_own/_assigned/_all` project-role grants on the
 * ROWS, not just as a membership gate. Legacy `tasks.*` still reads "all"
 * (PermissionScope::legacyScope), so the default roles behave exactly as
 * before the variants existed.
 */
class TaskScopeAccessTest extends TestCase
{
    use IsolatesDatabase;

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->firstOrFail();
    }

    private function tenantMember(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => 'password']);
        $user->roles()->attach(Role::where('slug', 'viewer')->firstOrFail());

        return $user;
    }

    private function makeWorkspace(): Workspace
    {
        $workspace = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => 'Scope Workspace',
            'slug' => 'scope-workspace',
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        return $workspace;
    }

    private function makeProject(Workspace $workspace): Project
    {
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $this->admin()->id,
            'lead_user_id' => $this->admin()->id,
            'name' => 'Scope Project',
            'key' => 'SCOPE',
        ]);

        $position = 0;
        foreach (config('task_statuses.statuses') as $status) {
            TaskStatus::create([
                'project_id' => $project->id,
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => $status['category'],
                'position' => ++$position,
                'color' => $status['color'] ?? null,
                'is_default' => $status['is_default'] ?? false,
                'is_done' => $status['is_done'] ?? false,
            ]);
        }

        $lead = ProjectRole::where('slug', 'lead')->firstOrFail();
        $project->members()->attach($this->admin()->id, ['project_role_id' => $lead->id, 'added_by' => $this->admin()->id]);

        return $project;
    }

    private function makeProjectRole(string $slug, array $permissions): ProjectRole
    {
        return ProjectRole::create([
            'name' => str($slug)->headline(),
            'slug' => $slug,
            'is_system' => false,
            'permissions' => $permissions,
        ]);
    }

    private function addMember(Project $project, User $user, ProjectRole $role): void
    {
        $project->members()->attach($user->id, ['project_role_id' => $role->id, 'added_by' => $this->admin()->id]);
    }

    private function makeTask(Project $project, User $assignee, User $reporter, string $title): Task
    {
        $defaultStatus = $project->statuses()->where('is_default', true)->firstOrFail();
        $project->increment('last_task_sequence');

        return Task::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $reporter->id,
            'reporter_id' => $reporter->id,
            'assignee_id' => $assignee->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => $title,
            'status_id' => $defaultStatus->id,
            'position' => 1,
        ]);
    }

    private function boardKeys(int $projectId, string $email): array
    {
        $this->loginAs($email);

        $response = $this->getJson("/api/projects/{$projectId}/tasks?view=board")->assertOk();

        return collect($response->json('board.statuses'))
            ->flatMap(fn ($status) => collect($status['tasks'])->pluck('key'))
            ->values()
            ->all();
    }

    public function test_a_view_own_role_reads_only_its_own_tasks(): void
    {
        $alice = $this->tenantMember('alice@flowsync.test');
        $bob = $this->tenantMember('bob@flowsync.test');

        $project = $this->makeProject($this->makeWorkspace());
        $own = $this->makeProjectRole('own-reader', ['tasks.view_own', 'tasks.edit_own']);
        $this->addMember($project, $alice, $own);
        $this->addMember($project, $bob, $this->makeProjectRole('all-reader', ['tasks.view_all']));

        $mine = $this->makeTask($project, $alice, $alice, 'Mine');
        $theirs = $this->makeTask($project, $bob, $bob, 'Theirs');

        // Board (and its open/done totals) narrow to the caller's rows.
        $this->loginAs('alice@flowsync.test');
        $board = $this->getJson("/api/projects/{$project->id}/tasks?view=board")->assertOk();
        $this->assertSame([$mine->key], $this->boardKeys($project->id, 'alice@flowsync.test'));
        $this->assertSame(1, $board->json('board.totals.open'));

        // Per-row show follows the same rule.
        $this->getJson("/api/projects/{$project->id}/tasks/{$mine->id}")->assertOk();
        $this->getJson("/api/projects/{$project->id}/tasks/{$theirs->id}")->assertForbidden();

        // Edit too: own task yes, colleagues' no.
        $this->putJson("/api/projects/{$project->id}/tasks/{$mine->id}", ['title' => 'Updated mine'])->assertOk();
        $this->putJson("/api/projects/{$project->id}/tasks/{$theirs->id}", ['title' => 'Sneak edit'])->assertForbidden();
    }

    public function test_a_view_assigned_role_reads_own_and_direct_report_tasks(): void
    {
        $alice = $this->tenantMember('alice@flowsync.test');
        $charlie = $this->tenantMember('charlie@flowsync.test');
        $bob = $this->tenantMember('bob@flowsync.test');

        // Alice manages Charlie through the employee reporting line.
        $managerEmployee = EmployeeFactory::new()->create(['user_id' => $alice->id]);
        EmployeeFactory::new()->create(['user_id' => $charlie->id, 'manager_id' => $managerEmployee->id]);

        $project = $this->makeProject($this->makeWorkspace());
        $assigned = $this->makeProjectRole('team-reader', ['tasks.view_assigned', 'tasks.edit_assigned']);
        $this->addMember($project, $alice, $assigned);
        $this->addMember($project, $charlie, $this->makeProjectRole('own-reader', ['tasks.view_own']));
        $this->addMember($project, $bob, $this->makeProjectRole('all-reader', ['tasks.view_all']));

        $mine = $this->makeTask($project, $alice, $alice, 'Mine');
        $reportsTask = $this->makeTask($project, $charlie, $charlie, 'Report');
        $theirs = $this->makeTask($project, $bob, $bob, 'Theirs');

        $this->assertSame([$mine->key, $reportsTask->key], $this->boardKeys($project->id, 'alice@flowsync.test'));

        $this->getJson("/api/projects/{$project->id}/tasks/{$mine->id}")->assertOk();
        $this->getJson("/api/projects/{$project->id}/tasks/{$reportsTask->id}")->assertOk();
        $this->getJson("/api/projects/{$project->id}/tasks/{$theirs->id}")->assertForbidden();

        $this->putJson("/api/projects/{$project->id}/tasks/{$reportsTask->id}", ['title' => 'Coach'])->assertOk();
        $this->putJson("/api/projects/{$project->id}/tasks/{$theirs->id}", ['title' => 'Sneak edit'])->assertForbidden();
    }

    public function test_an_assigned_login_without_an_employee_record_degrades_to_own_only(): void
    {
        $alice = $this->tenantMember('alice@flowsync.test');
        $charlie = $this->tenantMember('charlie@flowsync.test');

        // Charlie has an employee row but no manager, and Alice has none at all.
        EmployeeFactory::new()->create(['user_id' => $charlie->id]);

        $project = $this->makeProject($this->makeWorkspace());
        $assigned = $this->makeProjectRole('team-reader', ['tasks.view_assigned']);
        $this->addMember($project, $alice, $assigned);
        $this->addMember($project, $charlie, $this->makeProjectRole('own-reader', ['tasks.view_own']));

        $mine = $this->makeTask($project, $alice, $alice, 'Mine');
        $orphan = $this->makeTask($project, $charlie, $charlie, 'Orphan');

        // No error, no rows leaking: `_assigned` covers just Alice herself.
        $this->assertSame([$mine->key], $this->boardKeys($project->id, 'alice@flowsync.test'));
        $this->getJson("/api/projects/{$project->id}/tasks/{$orphan->id}")->assertForbidden();
    }

    public function test_legacy_and_all_grants_keep_reading_every_task(): void
    {
        $alice = $this->tenantMember('alice@flowsync.test');
        $bob = $this->tenantMember('bob@flowsync.test');

        $project = $this->makeProject($this->makeWorkspace());
        // `tasks.view` (legacy, means _all) is exactly what a developer holds.
        $this->addMember($project, $alice, ProjectRole::where('slug', 'developer')->firstOrFail());
        $this->addMember($project, $bob, $this->makeProjectRole('all-reader', ['tasks.view_all']));

        $a = $this->makeTask($project, $alice, $alice, 'A');
        $b = $this->makeTask($project, $bob, $bob, 'B');

        $this->assertSame([$a->key, $b->key], $this->boardKeys($project->id, 'alice@flowsync.test'));
        $this->getJson("/api/projects/{$project->id}/tasks/{$b->id}")->assertOk();
    }

    public function test_a_member_without_any_task_read_grant_still_gets_a_403_on_the_board(): void
    {
        $alice = $this->tenantMember('alice@flowsync.test');

        $project = $this->makeProject($this->makeWorkspace());
        $this->addMember($project, $alice, $this->makeProjectRole('project-only', ['projects.view']));

        $this->loginAs('alice@flowsync.test');
        $this->getJson("/api/projects/{$project->id}/tasks?view=board")->assertForbidden();
    }

    public function test_a_tenant_admin_still_reads_every_task(): void
    {
        $bob = $this->tenantMember('bob@flowsync.test');

        $project = $this->makeProject($this->makeWorkspace());
        $this->addMember($project, $bob, $this->makeProjectRole('all-reader', ['tasks.view_all']));
        $b = $this->makeTask($project, $bob, $bob, 'B');

        // The admin bypasses row scoping entirely (workspaces.manage).
        $this->loginAs('admin@flowsync.test');
        $this->getJson("/api/projects/{$project->id}/tasks/{$b->id}")->assertOk();
        $this->assertSame([$b->key], $this->boardKeys($project->id, 'admin@flowsync.test'));
    }
}
