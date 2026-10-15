<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Storage;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase E step 17 — Comprehensive Member Access Matrix.
 *
 * Verifies domain × {own row, report row, stranger row} × {none, own, assigned, all, manage}
 * across TMS (tasks) and HRMS (leave, expense, document, performance).
 */
class MemberAccessMatrixTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_task_access_matrix_across_scopes(): void
    {
        $admin = $this->admin();
        $workspace = $this->makeWorkspace($admin);
        $project = $this->makeProject($workspace, $admin);

        // Setup users and reporting chain
        $caller = $this->makeUserWithEmployee('Caller');
        $report = $this->makeReportUser($caller['employee'], 'Report');
        $stranger = $this->makeUserWithEmployee('Stranger');

        // Give caller tenant-level workspaces.view so route middleware passes
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['workspaces.view']);

        // Create tasks (reportTask has report as reporter so it is not caller's own)
        $ownTask = $this->makeTask($project, $caller['user'], $caller['user'], 'Own Task');
        $reportTask = $this->makeTask($project, $report['user'], $report['user'], 'Report Task');
        $strangerTask = $this->makeTask($project, $stranger['user'], $stranger['user'], 'Stranger Task');

        // 1. No task perm (projects.view only)
        $noPermRole = $this->makeProjectRole('no-perm', ['projects.view']);
        $this->setProjectMember($project, $caller['user'], $noPermRole);
        $this->actAs($caller['user']);

        $this->getJson("/api/projects/{$project->id}/tasks?view=board")->assertForbidden();
        $this->getJson("/api/projects/{$project->id}/tasks/{$ownTask->id}")->assertForbidden();
        $this->getJson("/api/projects/{$project->id}/tasks/{$reportTask->id}")->assertForbidden();
        $this->getJson("/api/projects/{$project->id}/tasks/{$strangerTask->id}")->assertForbidden();

        // 2. tasks.view_own
        $ownRole = $this->makeProjectRole('view-own', ['projects.view', 'tasks.view_own']);
        $this->setProjectMember($project, $caller['user'], $ownRole);
        $this->actAs($caller['user']);

        $boardOwn = $this->getJson("/api/projects/{$project->id}/tasks?view=board")->assertOk()->json('board.statuses');
        $visibleTaskIdsOwn = $this->extractTaskIdsFromBoard($boardOwn);
        $this->assertContains($ownTask->id, $visibleTaskIdsOwn);
        $this->assertNotContains($reportTask->id, $visibleTaskIdsOwn);
        $this->assertNotContains($strangerTask->id, $visibleTaskIdsOwn);

        $this->getJson("/api/projects/{$project->id}/tasks/{$ownTask->id}")->assertOk();
        $this->getJson("/api/projects/{$project->id}/tasks/{$reportTask->id}")->assertForbidden();
        $this->getJson("/api/projects/{$project->id}/tasks/{$strangerTask->id}")->assertForbidden();

        // 3. tasks.view_assigned
        $assignedRole = $this->makeProjectRole('view-assigned', ['projects.view', 'tasks.view_assigned']);
        $this->setProjectMember($project, $caller['user'], $assignedRole);
        $this->actAs($caller['user']);

        $boardAssigned = $this->getJson("/api/projects/{$project->id}/tasks?view=board")->assertOk()->json('board.statuses');
        $visibleTaskIdsAssigned = $this->extractTaskIdsFromBoard($boardAssigned);
        $this->assertContains($ownTask->id, $visibleTaskIdsAssigned);
        $this->assertContains($reportTask->id, $visibleTaskIdsAssigned);
        $this->assertNotContains($strangerTask->id, $visibleTaskIdsAssigned);

        $this->getJson("/api/projects/{$project->id}/tasks/{$ownTask->id}")->assertOk();
        $this->getJson("/api/projects/{$project->id}/tasks/{$reportTask->id}")->assertOk();
        $this->getJson("/api/projects/{$project->id}/tasks/{$strangerTask->id}")->assertForbidden();

        // 4. tasks.view_all
        $allRole = $this->makeProjectRole('view-all', ['projects.view', 'tasks.view_all']);
        $this->setProjectMember($project, $caller['user'], $allRole);
        $this->actAs($caller['user']);

        $boardAll = $this->getJson("/api/projects/{$project->id}/tasks?view=board")->assertOk()->json('board.statuses');
        $visibleTaskIdsAll = $this->extractTaskIdsFromBoard($boardAll);
        $this->assertContains($ownTask->id, $visibleTaskIdsAll);
        $this->assertContains($reportTask->id, $visibleTaskIdsAll);
        $this->assertContains($strangerTask->id, $visibleTaskIdsAll);

        $this->getJson("/api/projects/{$project->id}/tasks/{$ownTask->id}")->assertOk();
        $this->getJson("/api/projects/{$project->id}/tasks/{$reportTask->id}")->assertOk();
        $this->getJson("/api/projects/{$project->id}/tasks/{$strangerTask->id}")->assertOk();
    }

    public function test_leave_access_matrix_across_scopes(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.leave']);

        $caller = $this->makeUserWithEmployee('Caller Leave');
        $report = $this->makeReportUser($caller['employee'], 'Report Leave');
        $stranger = $this->makeUserWithEmployee('Stranger Leave');

        $ownAsk = $this->makeLeaveRequest($caller['employee']);
        $reportAsk = $this->makeLeaveRequest($report['employee']);
        $strangerAsk = $this->makeLeaveRequest($stranger['employee']);

        // 1. None (a user without employment record and without leave view gets 403 on requests list)
        $orphan = User::create([
            'name' => 'Orphan User',
            'email' => 'orphan.leave@flowsync.test',
            'password' => 'password',
        ]);
        $this->assignUserPermissions($orphan, ['hrms.view']);
        $this->actAs($orphan);
        $this->getJson('/api/hrms/leave/requests')->assertForbidden();

        // 2. view_own
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['hrms.view', 'hrms.leave.view_own']);
        $this->actAs($caller['user']);
        $idsOwn = $this->getJson('/api/hrms/leave/requests')->assertOk()->json('requests.*.id');
        $this->assertSame([$ownAsk->id], $idsOwn);
        $this->getJson("/api/hrms/leave/requests/{$ownAsk->id}")->assertOk();
        $this->getJson("/api/hrms/leave/requests/{$reportAsk->id}")->assertForbidden();
        $this->getJson("/api/hrms/leave/requests/{$strangerAsk->id}")->assertForbidden();

        // 3. view_assigned
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['hrms.view', 'hrms.leave.view_assigned']);
        $this->actAs($caller['user']);
        $idsAssigned = $this->getJson('/api/hrms/leave/requests')->assertOk()->json('requests.*.id');
        $this->assertContains($ownAsk->id, $idsAssigned);
        $this->assertContains($reportAsk->id, $idsAssigned);
        $this->assertNotContains($strangerAsk->id, $idsAssigned);
        $this->getJson("/api/hrms/leave/requests/{$ownAsk->id}")->assertOk();
        $this->getJson("/api/hrms/leave/requests/{$reportAsk->id}")->assertOk();
        $this->getJson("/api/hrms/leave/requests/{$strangerAsk->id}")->assertForbidden();

        // 4. view_all
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['hrms.view', 'hrms.leave.view_all']);
        $this->actAs($caller['user']);
        $idsAll = $this->getJson('/api/hrms/leave/requests')->assertOk()->json('requests.*.id');
        $this->assertContains($ownAsk->id, $idsAll);
        $this->assertContains($reportAsk->id, $idsAll);
        $this->assertContains($strangerAsk->id, $idsAll);
        $this->getJson("/api/hrms/leave/requests/{$ownAsk->id}")->assertOk();
        $this->getJson("/api/hrms/leave/requests/{$reportAsk->id}")->assertOk();
        $this->getJson("/api/hrms/leave/requests/{$strangerAsk->id}")->assertOk();

        // 5. manage
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['hrms.view', 'hrms.leave.manage']);
        $this->actAs($caller['user']);
        $idsManage = $this->getJson('/api/hrms/leave/requests')->assertOk()->json('requests.*.id');
        $this->assertContains($ownAsk->id, $idsManage);
        $this->assertContains($reportAsk->id, $idsManage);
        $this->assertContains($strangerAsk->id, $idsManage);
        $this->getJson("/api/hrms/leave/requests/{$ownAsk->id}")->assertOk();
        $this->getJson("/api/hrms/leave/requests/{$reportAsk->id}")->assertOk();
        $this->getJson("/api/hrms/leave/requests/{$strangerAsk->id}")->assertOk();
    }

    public function test_expense_access_matrix_across_scopes(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.expenses']);

        $caller = $this->makeUserWithEmployee('Caller Expense');
        $report = $this->makeReportUser($caller['employee'], 'Report Expense');
        $stranger = $this->makeUserWithEmployee('Stranger Expense');

        $ownClaim = ExpenseClaim::factory()->create(['employee_id' => $caller['employee']->id]);
        $reportClaim = ExpenseClaim::factory()->create(['employee_id' => $report['employee']->id]);
        $strangerClaim = ExpenseClaim::factory()->create(['employee_id' => $stranger['employee']->id]);

        // 1. None (orphan user without employee record cannot read any expense claims)
        $orphan = User::create([
            'name' => 'Orphan User Expense',
            'email' => 'orphan.expense@flowsync.test',
            'password' => 'password',
        ]);
        $this->assignUserPermissions($orphan, ['hrms.view']);
        $this->actAs($orphan);
        $this->getJson('/api/hrms/expenses/claims')->assertForbidden();
        $this->getJson("/api/hrms/expenses/claims/{$ownClaim->id}")->assertForbidden();

        // 2. view_own
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['hrms.view', 'hrms.expenses.view_own']);
        $this->actAs($caller['user']);
        $idsOwn = $this->getJson('/api/hrms/expenses/claims')->assertOk()->json('claims.*.id');
        $this->assertSame([$ownClaim->id], $idsOwn);
        $this->getJson("/api/hrms/expenses/claims/{$ownClaim->id}")->assertOk();
        $this->getJson("/api/hrms/expenses/claims/{$reportClaim->id}")->assertForbidden();
        $this->getJson("/api/hrms/expenses/claims/{$strangerClaim->id}")->assertForbidden();

        // 3. view_assigned
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['hrms.view', 'hrms.expenses.view_assigned']);
        $this->actAs($caller['user']);
        $idsAssigned = $this->getJson('/api/hrms/expenses/claims')->assertOk()->json('claims.*.id');
        $this->assertContains($ownClaim->id, $idsAssigned);
        $this->assertContains($reportClaim->id, $idsAssigned);
        $this->assertNotContains($strangerClaim->id, $idsAssigned);
        $this->getJson("/api/hrms/expenses/claims/{$ownClaim->id}")->assertOk();
        $this->getJson("/api/hrms/expenses/claims/{$reportClaim->id}")->assertOk();
        $this->getJson("/api/hrms/expenses/claims/{$strangerClaim->id}")->assertForbidden();

        // 4. view_all
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['hrms.view', 'hrms.expenses.view_all']);
        $this->actAs($caller['user']);
        $idsAll = $this->getJson('/api/hrms/expenses/claims')->assertOk()->json('claims.*.id');
        $this->assertContains($ownClaim->id, $idsAll);
        $this->assertContains($reportClaim->id, $idsAll);
        $this->assertContains($strangerClaim->id, $idsAll);
        $this->getJson("/api/hrms/expenses/claims/{$ownClaim->id}")->assertOk();
        $this->getJson("/api/hrms/expenses/claims/{$reportClaim->id}")->assertOk();
        $this->getJson("/api/hrms/expenses/claims/{$strangerClaim->id}")->assertOk();
    }

    public function test_document_access_matrix_across_scopes(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.documents']);

        $caller = $this->makeUserWithEmployee('Caller Doc');
        $report = $this->makeReportUser($caller['employee'], 'Report Doc');
        $stranger = $this->makeUserWithEmployee('Stranger Doc');

        $ownDoc = $this->uploadFor($caller['employee']);
        $reportDoc = $this->uploadFor($report['employee']);
        $strangerDoc = $this->uploadFor($stranger['employee']);

        // 1. None (orphan user without employee record cannot read document directory)
        $orphan = User::create([
            'name' => 'Orphan User Doc',
            'email' => 'orphan.doc@flowsync.test',
            'password' => 'password',
        ]);
        $this->assignUserPermissions($orphan, ['hrms.view']);
        $this->actAs($orphan);
        $this->getJson('/api/hrms/documents')->assertForbidden();
        $this->getJson("/api/hrms/documents/{$ownDoc->id}")->assertForbidden();

        // 2. view_own
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['hrms.view', 'hrms.documents.view_own']);
        $this->actAs($caller['user']);
        $idsOwn = $this->getJson('/api/hrms/documents')->assertOk()->json('documents.*.id');
        $this->assertSame([$ownDoc->id], $idsOwn);
        $this->getJson("/api/hrms/documents/{$ownDoc->id}")->assertOk();
        $this->getJson("/api/hrms/documents/{$reportDoc->id}")->assertForbidden();
        $this->getJson("/api/hrms/documents/{$strangerDoc->id}")->assertForbidden();

        // 3. view_assigned
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['hrms.view', 'hrms.documents.view_assigned']);
        $this->actAs($caller['user']);
        $idsAssigned = $this->getJson('/api/hrms/documents')->assertOk()->json('documents.*.id');
        $this->assertContains($ownDoc->id, $idsAssigned);
        $this->assertContains($reportDoc->id, $idsAssigned);
        $this->assertNotContains($strangerDoc->id, $idsAssigned);
        $this->getJson("/api/hrms/documents/{$ownDoc->id}")->assertOk();
        $this->getJson("/api/hrms/documents/{$reportDoc->id}")->assertOk();
        $this->getJson("/api/hrms/documents/{$strangerDoc->id}")->assertForbidden();

        // 4. view_all
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['hrms.view', 'hrms.documents.view_all']);
        $this->actAs($caller['user']);
        $idsAll = $this->getJson('/api/hrms/documents')->assertOk()->json('documents.*.id');
        $this->assertContains($ownDoc->id, $idsAll);
        $this->assertContains($reportDoc->id, $idsAll);
        $this->assertContains($strangerDoc->id, $idsAll);
        $this->getJson("/api/hrms/documents/{$ownDoc->id}")->assertOk();
        $this->getJson("/api/hrms/documents/{$reportDoc->id}")->assertOk();
        $this->getJson("/api/hrms/documents/{$strangerDoc->id}")->assertOk();
    }

    public function test_performance_goal_access_matrix_across_scopes(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.performance']);
        $cycle = $this->cycle();

        $caller = $this->makeUserWithEmployee('Caller Perf');
        $report = $this->makeReportUser($caller['employee'], 'Report Perf');
        $stranger = $this->makeUserWithEmployee('Stranger Perf');

        $ownGoal = PerformanceGoal::create([
            'cycle_id' => $cycle->id,
            'employee_id' => $caller['employee']->id,
            'title' => 'Own Goal',
            'metric_type' => 'manual',
            'status' => 'draft',
        ]);
        $reportGoal = PerformanceGoal::create([
            'cycle_id' => $cycle->id,
            'employee_id' => $report['employee']->id,
            'title' => 'Report Goal',
            'metric_type' => 'manual',
            'status' => 'draft',
        ]);
        $strangerGoal = PerformanceGoal::create([
            'cycle_id' => $cycle->id,
            'employee_id' => $stranger['employee']->id,
            'title' => 'Stranger Goal',
            'metric_type' => 'manual',
            'status' => 'draft',
        ]);

        // 1. None (orphan user without employee record cannot read goals)
        $orphan = User::create([
            'name' => 'Orphan User Goal',
            'email' => 'orphan.goal@flowsync.test',
            'password' => 'password',
        ]);
        $this->assignUserPermissions($orphan, ['hrms.view']);
        $this->actAs($orphan);
        $this->getJson("/api/hrms/performance/cycles/{$cycle->id}/goals")->assertForbidden();
        $this->getJson("/api/hrms/performance/goals/{$ownGoal->id}")->assertForbidden();

        // 2. view_own
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['hrms.view', 'hrms.performance.view_own']);
        $this->actAs($caller['user']);
        $idsOwn = $this->getJson("/api/hrms/performance/cycles/{$cycle->id}/goals")->assertOk()->json('goals.*.id');
        $this->assertSame([$ownGoal->id], $idsOwn);
        $this->getJson("/api/hrms/performance/goals/{$ownGoal->id}")->assertOk();
        // A direct manager may always view their report's individual goal (PerformanceGoalPolicy::isManagerOf),
        // while an unrelated stranger's goal stays forbidden.
        $this->getJson("/api/hrms/performance/goals/{$reportGoal->id}")->assertOk();
        $this->getJson("/api/hrms/performance/goals/{$strangerGoal->id}")->assertForbidden();

        // 3. view_assigned
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['hrms.view', 'hrms.performance.view_assigned']);
        $this->actAs($caller['user']);
        $idsAssigned = $this->getJson("/api/hrms/performance/cycles/{$cycle->id}/goals")->assertOk()->json('goals.*.id');
        $this->assertContains($ownGoal->id, $idsAssigned);
        $this->assertContains($reportGoal->id, $idsAssigned);
        $this->assertNotContains($strangerGoal->id, $idsAssigned);
        $this->getJson("/api/hrms/performance/goals/{$ownGoal->id}")->assertOk();
        $this->getJson("/api/hrms/performance/goals/{$reportGoal->id}")->assertOk();
        $this->getJson("/api/hrms/performance/goals/{$strangerGoal->id}")->assertForbidden();

        // 4. view_all
        $caller['user'] = $this->assignUserPermissions($caller['user'], ['hrms.view', 'hrms.performance.view_all']);
        $this->actAs($caller['user']);
        $idsAll = $this->getJson("/api/hrms/performance/cycles/{$cycle->id}/goals")->assertOk()->json('goals.*.id');
        $this->assertContains($ownGoal->id, $idsAll);
        $this->assertContains($reportGoal->id, $idsAll);
        $this->assertContains($strangerGoal->id, $idsAll);
        $this->getJson("/api/hrms/performance/goals/{$ownGoal->id}")->assertOk();
        $this->getJson("/api/hrms/performance/goals/{$reportGoal->id}")->assertOk();
        $this->getJson("/api/hrms/performance/goals/{$strangerGoal->id}")->assertOk();
    }

    public function test_sidebar_and_me_capabilities_reflect_scope_and_modules(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.leave']);

        $user = $this->makeUserWithEmployee('Capabilities User')['user'];
        $user = $this->assignUserPermissions($user, [
            'hrms.view',
            'hrms.leave.view_own',
            'workspaces.view',
        ]);
        $this->actAs($user);

        $me = $this->getJson('/api/auth/me')->assertOk()->json();

        // Effective modules contain allowed modules
        $this->assertContains('hrms.core', $me['user']['modules']);
        $this->assertContains('hrms.leave', $me['user']['modules']);

        // Explicit permissions reflect granted scopes
        $this->assertContains('hrms.leave.view_own', $me['user']['permissions']);
        $this->assertNotContains('hrms.leave.view_all', $me['user']['permissions']);
    }

    // ------------------------------------------------------------ helpers

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->firstOrFail();
    }

    private function makeWorkspace(User $admin): Workspace
    {
        $workspace = Workspace::create([
            'created_by' => $admin->id,
            'name' => 'Matrix Workspace',
            'slug' => 'matrix-workspace-'.uniqid(),
        ]);
        $workspace->members()->attach($admin->id, ['role' => 'owner', 'added_by' => $admin->id]);

        return $workspace;
    }

    private function makeProject(Workspace $workspace, User $admin): Project
    {
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $admin->id,
            'lead_user_id' => $admin->id,
            'name' => 'Matrix Project',
            'key' => 'MAT'.rand(10, 99),
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
        $project->members()->attach($admin->id, ['project_role_id' => $lead->id, 'added_by' => $admin->id]);

        return $project;
    }

    private function makeProjectRole(string $slug, array $permissions): ProjectRole
    {
        return ProjectRole::create([
            'name' => str($slug)->headline(),
            'slug' => 'mat-'.$slug.'-'.uniqid(),
            'is_system' => false,
            'permissions' => $permissions,
        ]);
    }

    private function setProjectMember(Project $project, User $user, ProjectRole $role): void
    {
        $admin = $this->admin();

        // Ensure user is workspace member first
        if (! $project->workspace->members()->where('user_id', $user->id)->exists()) {
            $project->workspace->members()->attach($user->id, ['role' => 'member', 'added_by' => $admin->id]);
        }

        $project->members()->syncWithoutDetaching([
            $user->id => ['project_role_id' => $role->id, 'added_by' => $admin->id],
        ]);
    }

    private function makeTask(Project $project, User $assignee, User $reporter, string $title): Task
    {
        $project->increment('last_task_sequence');
        $defaultStatus = $project->statuses()->where('is_default', true)->firstOrFail();

        return Task::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $reporter->id,
            'assignee_id' => $assignee->id,
            'reporter_id' => $reporter->id,
            'status_id' => $defaultStatus->id,
            'priority_id' => $project->statuses()->first()->id, // fallback
            'key' => "{$project->key}-{$project->last_task_sequence}",
            'sequence' => $project->last_task_sequence,
            'title' => $title,
            'position' => 1,
        ]);
    }

    private function extractTaskIdsFromBoard(array $statuses): array
    {
        $ids = [];
        foreach ($statuses as $status) {
            foreach ($status['tasks'] ?? [] as $task) {
                $ids[] = $task['id'];
            }
        }

        return $ids;
    }

    /**
     * @return array{user: User, employee: Employee}
     */
    private function makeUserWithEmployee(string $name): array
    {
        static $seq = 0;
        $seq++;

        $this->connectTenant('acme');

        $user = User::create([
            'name' => "{$name} {$seq}",
            'email' => 'matrix.'.str($name)->slug().".{$seq}@flowsync.test",
            'password' => 'password',
        ]);

        $employee = Employee::create([
            'employee_code' => "EMP-MAT-{$seq}",
            'name' => "{$name} {$seq}",
            'status' => EmployeeStatus::Active,
            'user_id' => $user->id,
        ]);

        return ['user' => $user, 'employee' => $employee];
    }

    /**
     * @return array{user: User, employee: Employee}
     */
    private function makeReportUser(Employee $manager, string $name): array
    {
        static $seq = 0;
        $seq++;

        $this->connectTenant('acme');

        $user = User::create([
            'name' => "{$name} {$seq}",
            'email' => "matrix.report.{$seq}@flowsync.test",
            'password' => 'password',
        ]);

        $employee = Employee::create([
            'employee_code' => "EMP-REP-{$seq}",
            'name' => "{$name} {$seq}",
            'status' => EmployeeStatus::Active,
            'user_id' => $user->id,
            'manager_id' => $manager->id,
        ]);

        return ['user' => $user, 'employee' => $employee];
    }

    private function assignUserPermissions(User $user, array $permissionSlugs): User
    {
        $this->connectTenant('acme');

        $role = Role::create([
            'name' => 'Matrix Role '.uniqid(),
            'slug' => 'matrix-role-'.uniqid(),
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    private function makeLeaveRequest(Employee $employee): LeaveRequest
    {
        $this->connectTenant('acme');

        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();

        return LeaveRequest::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
        ]);
    }

    private function makeType(): DocumentType
    {
        static $seq = 0;
        $seq++;

        return DocumentType::create([
            'name' => "Matrix Doc Type {$seq}",
            'slug' => "mat-doc-type-{$seq}",
            'category' => 'identity',
            'is_mandatory' => false,
            'requires_expiry' => false,
            'retention_months' => null,
            'is_sensitive' => false,
            'position' => $seq * 10,
            'is_active' => true,
            'is_system' => false,
        ]);
    }

    private function uploadFor(Employee $employee): EmployeeDocument
    {
        static $seq = 0;
        $seq++;

        $path = "hrms/{$this->acme()->id}/{$employee->id}/mat-{$seq}.pdf";
        Storage::disk('local')->put($path, 'pdf-bytes');

        return EmployeeDocument::create([
            'employee_id' => $employee->id,
            'document_type_id' => $this->makeType()->id,
            'title' => "Matrix Doc {$seq}",
            'file_disk' => 'local',
            'file_path' => $path,
            'original_name' => "mat-{$seq}.pdf",
            'mime' => 'application/pdf',
            'size' => 10,
            'status' => 'pending',
            'visibility' => 'hr',
            'confidential' => false,
            'source' => 'hr',
        ]);
    }

    private function cycle(): PerformanceCycle
    {
        $this->connectTenant('acme');

        return PerformanceCycle::firstOrCreate(
            ['slug' => 'matrix-cycle'],
            ['name' => 'Matrix Cycle 2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31'],
        );
    }

    private function setAcmeModules(array $modules): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }
}
