<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OffboardingCaseTask;
use App\Models\Hrms\Lifecycle\OnboardingCase;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Hrms\OffboardingService;
use App\Services\Hrms\OnboardingService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P20.3 — checklist items become project tasks.
 *
 * A `task`-category item converts once (project task assigned to the
 * owner's login when that login sits on the project, link recording the
 * source); anything else 422s. Completion flows one way and is pulled:
 * syncing over an open task is a no-op, syncing over a done task closes
 * the item through the normal path — and a reopened task never reopens
 * the item, because sync moves toward done only and never writes back.
 */
class HrmsCaseTaskConversionTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_task_item_converts_to_a_project_task_and_links(): void
    {
        [$case, $item, $employee] = $this->onboardingItem(['category' => 'task']);
        [$project, $hr] = $this->projectWith($employee->user, ['hrms.view', 'hrms.onboarding.manage']);
        $this->actAs($hr);

        $response = $this->postJson(
            "/api/hrms/onboarding/cases/{$case->id}/tasks/{$item->id}/convert",
            ['project_id' => $project->id],
        )->assertCreated()->json();

        $this->assertSame($item->title, $response['project_task']['title']);
        $this->assertSame('onboarding', $response['task_link']['kind']);
        $this->assertSame('onboarding_task', $response['task_link']['source_type']);
        $this->assertSame($item->id, $response['task_link']['source_id']);

        $task = Task::findOrFail($response['project_task']['id']);
        $this->assertSame($employee->user_id, $task->assignee_id);
    }

    public function test_conversion_refuses_non_task_items_and_double_conversion(): void
    {
        [$case, $item] = $this->onboardingItem(['category' => 'document']);
        [$project, $hr] = $this->projectWith(null, ['hrms.view', 'hrms.onboarding.manage']);
        $this->actAs($hr);

        $this->postJson(
            "/api/hrms/onboarding/cases/{$case->id}/tasks/{$item->id}/convert",
            ['project_id' => $project->id],
        )->assertStatus(422);

        [$case2, $item2] = $this->onboardingItem(['category' => 'task']);
        $this->postJson(
            "/api/hrms/onboarding/cases/{$case2->id}/tasks/{$item2->id}/convert",
            ['project_id' => $project->id],
        )->assertCreated();
        $this->postJson(
            "/api/hrms/onboarding/cases/{$case2->id}/tasks/{$item2->id}/convert",
            ['project_id' => $project->id],
        )->assertStatus(422);
    }

    public function test_sync_closes_the_item_when_the_task_completes_and_never_writes_back(): void
    {
        [$case, $item, $employee] = $this->onboardingItem(['category' => 'task']);
        [$project, $hr] = $this->projectWith($employee->user, ['hrms.view', 'hrms.onboarding.manage']);
        $this->actAs($hr);

        $taskId = $this->postJson(
            "/api/hrms/onboarding/cases/{$case->id}/tasks/{$item->id}/convert",
            ['project_id' => $project->id],
        )->assertCreated()->json('project_task.id');

        $syncUrl = "/api/hrms/onboarding/cases/{$case->id}/tasks/{$item->id}/sync";

        $this->postJson($syncUrl, [])->assertOk()->assertJsonPath('task.status', 'pending');
        $this->assertSame('Nothing to sync.', $this->postJson($syncUrl, [])->json('message'));

        $done = $project->statuses()->where('is_done', true)->firstOrFail();
        $this->putJson("/api/projects/{$project->id}/tasks/{$taskId}", ['status_id' => $done->id])->assertOk();

        $this->postJson($syncUrl, [])->assertOk()->assertJsonPath('task.status', 'done');

        // Reopening the task does not reopen the item, and the sync never
        // edits the task's own fields.
        $open = $project->statuses()->where('is_done', false)->firstOrFail();
        $this->putJson("/api/projects/{$project->id}/tasks/{$taskId}", [
            'status_id' => $open->id, 'title' => 'Edited by hand',
        ])->assertOk();

        $this->postJson($syncUrl, [])->assertOk()->assertJsonPath('task.status', 'done');
        $this->assertSame('Edited by hand', Task::find($taskId)->title);
    }

    public function test_an_offboarding_item_converts_and_syncs(): void
    {
        $employee = $this->employee('EMP-CONV-OFF', 'Convert Off', true);
        $this->connectTenant('acme');

        $case = app(OffboardingService::class)->initiate($employee, now()->addMonth()->toDateString(), 'resigned');
        $item = OffboardingCaseTask::create([
            'case_id' => $case->id,
            'title' => 'Collect the laptop',
            'category' => 'task',
            'owner_scope' => 'employee',
            'status' => 'pending',
            'owner_employee_id' => $employee->id,
        ]);

        [$project, $hr] = $this->projectWith($employee->user, ['hrms.view', 'hrms.offboarding.manage']);
        $this->actAs($hr);

        $taskId = $this->postJson(
            "/api/hrms/offboarding/cases/{$case->id}/tasks/{$item->id}/convert",
            ['project_id' => $project->id],
        )->assertCreated()->json('project_task.id');

        $done = $project->statuses()->where('is_done', true)->firstOrFail();
        $this->putJson("/api/projects/{$project->id}/tasks/{$taskId}", ['status_id' => $done->id])->assertOk();

        $this->postJson("/api/hrms/offboarding/cases/{$case->id}/tasks/{$item->id}/sync", [])
            ->assertOk()
            ->assertJsonPath('task.status', 'done');
    }

    // ------------------------------------------------------------ helpers

    /**
     * An onboarding case with a single item reshaped to the given category.
     *
     * The template seeds an inert category on purpose: a `document` item
     * would materialise a document request at case creation, and the test
     * is about conversion, not the request hinge.
     *
     * @return array{0:OnboardingCase, 1:OnboardingCaseTask, 2:Employee}
     */
    private function onboardingItem(array $item): array
    {
        $this->connectTenant('acme');
        $employee = $this->employee('EMP-CONV-'.strtoupper(substr(md5(json_encode($item).microtime()), 0, 6)), 'Convert', true);

        $template = app(OnboardingService::class)->createTemplate('Convert '.uniqid(), [
            'tasks' => [['title' => 'Ship the laptop', 'category' => 'other', 'owner_scope' => 'hr']],
        ]);
        $case = app(OnboardingService::class)->createCase($employee, $template);

        $caseItem = $case->tasks()->firstOrFail();
        $caseItem->update(['category' => $item['category'], 'owner_employee_id' => $employee->id]);

        return [$case, $caseItem->refresh(), $employee];
    }

    private function employee(string $code, string $name, bool $withUser = false): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $user = $withUser ? $this->userWith(['hrms.view']) : null;

        return Employee::create([
            'employee_code' => "{$code}-{$sequence}",
            'name' => "{$name} {$sequence}",
            'status' => EmployeeStatus::Active,
            'user_id' => $user?->id,
        ]);
    }

    /**
     * @return array{Project, User} Project with the HR caller attached as developer.
     */
    private function projectWith(?User $member, array $permissionSlugs): array
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        // Task routes answer to the domain permission, HRMS routes to the
        // HRMS one — the caller crossing the bridge needs both stamped.
        $hr = $this->userWith(array_values(array_unique([...$permissionSlugs, 'workspaces.view'])));
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();

        $workspace = Workspace::create([
            'created_by' => $admin->id, 'name' => "Convert {$sequence}", 'slug' => "convert-{$sequence}",
        ]);
        $workspace->members()->attach($hr->id, ['role' => 'owner', 'added_by' => $admin->id]);

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $admin->id,
            'lead_user_id' => $admin->id,
            'name' => "Convert {$sequence}",
            'key' => "CV{$sequence}",
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

        $this->addProjectMember($project, $hr, 'developer');

        if ($member !== null) {
            $this->addProjectMember($project, $member, 'developer');
        }

        return [$project, $hr];
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
            'name' => "Convert User {$sequence}",
            'email' => "convert.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Convert Role {$sequence}",
            'slug' => "convert-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
