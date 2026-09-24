<?php

namespace Tests\Feature;

use App\Events\NotificationSent;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\Workspace;
use App\Services\NotificationService;
use App\Services\WorkLogService;
use App\Support\TenantContext;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WorkLogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->acme = Tenant::where('slug', 'acme')->first();
        app(TenantContext::class)->setTenantId($this->acme->id);
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->first();
    }

    private function editor(): User
    {
        return User::where('email', 'editor@flowsync.test')->first();
    }

    private function viewer(): User
    {
        return User::where('email', 'viewer@flowsync.test')->first();
    }

    private function createProjectWithLead(string $name = 'Website', string $key = 'WEB', string $workspaceName = 'Design', string $workspaceSlug = 'design'): Project
    {
        $workspace = Workspace::create([
            'tenant_id' => $this->acme->id,
            'created_by' => $this->admin()->id,
            'name' => $workspaceName,
            'slug' => $workspaceSlug,
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        return $this->createProjectIn($workspace, $name, $key);
    }

    private function createProjectIn(Workspace $workspace, string $name, string $key): Project
    {
        $project = Project::create([
            'tenant_id' => $this->acme->id,
            'workspace_id' => $workspace->id,
            'created_by' => $this->admin()->id,
            'lead_user_id' => $this->admin()->id,
            'name' => $name,
            'key' => $key,
        ]);
        $leadRole = ProjectRole::where('slug', 'lead')->first();
        $project->members()->attach($this->admin()->id, ['project_role_id' => $leadRole->id, 'added_by' => $this->admin()->id]);

        $position = 0;
        foreach (config('task_statuses.statuses') as $status) {
            $position++;
            TaskStatus::create([
                'tenant_id' => $this->acme->id,
                'project_id' => $project->id,
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => $status['category'],
                'position' => $position,
                'color' => $status['color'] ?? null,
                'is_default' => $status['is_default'] ?? false,
                'is_done' => $status['is_done'] ?? false,
            ]);
        }

        return $project;
    }

    private function addProjectMember(Project $project, User $user, string $roleSlug = 'viewer'): void
    {
        $role = ProjectRole::where('slug', $roleSlug)->first();
        $project->members()->attach($user->id, ['project_role_id' => $role->id, 'added_by' => $this->admin()->id]);
    }

    private function makeTask(Project $project, string $title, ?string $statusSlug = null): Task
    {
        $project->increment('last_task_sequence');
        $status = $statusSlug
            ? $project->statuses()->where('slug', $statusSlug)->first()
            : $project->statuses()->where('is_default', true)->first();

        return Task::create([
            'tenant_id' => $this->acme->id,
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'reporter_id' => $this->admin()->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => $title,
            'status_id' => $status->id,
            'position' => 1,
        ]);
    }

    private function log(Task $task, User $user, string $start, string $end, ?string $description = null): WorkLog
    {
        $service = app(WorkLogService::class);

        return $service->create($task, [
            'started_at' => $start,
            'ended_at' => $end,
            'description' => $description,
        ], $user);
    }

    public function test_duration_is_recomputed_from_interval_on_create(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Estimate me');
        $task->update(['estimate_minutes' => 120]);
        $task = $task->fresh();
        $this->login('editor@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs", [
            'started_at' => '2026-01-01 09:00:00',
            'ended_at' => '2026-01-01 10:45:00',
            'description' => 'Deep work',
        ])->assertCreated()
            ->assertJsonPath('work_log.duration_minutes', 105)
            ->assertJsonPath('work_log.effective_minutes', 105)
            ->assertJsonPath('work_log.user.name', 'Editor User');

        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs")
            ->assertOk()
            ->assertJsonPath('totals.total_minutes', 105)
            ->assertJsonPath('totals.estimate_minutes', 120)
            ->assertJsonPath('totals.remaining_minutes', 15);
    }

    public function test_adjacent_logs_are_allowed_but_overlaps_are_rejected(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Careful timing');
        $first = $this->log($task, $this->editor(), '2026-01-01 09:00:00', '2026-01-01 10:00:00');
        $this->assertSame(60, $first->fresh()->duration_minutes);
        $this->login('editor@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs", [
            'started_at' => '2026-01-01 10:00:00',
            'ended_at' => '2026-01-01 11:00:00',
        ])->assertCreated();

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs", [
            'started_at' => '2026-01-01 09:30:00',
            'ended_at' => '2026-01-01 11:30:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('form');
    }

    public function test_update_recomputes_duration_and_skips_self_when_checking_overlaps(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Double booked');
        $log = $this->log($task, $this->editor(), '2026-01-01 09:00:00', '2026-01-01 10:00:00');
        $this->login('editor@flowsync.test');

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs/{$log->id}", [
            'started_at' => '2026-01-01 09:15:00',
            'ended_at' => '2026-01-01 10:45:00',
        ])->assertOk()
            ->assertJsonPath('work_log.duration_minutes', 90);
    }

    public function test_invalid_interval_and_backwards_ended_at_are_rejected(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Backwards');
        $this->login('editor@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs", [
            'started_at' => 'not-a-date',
        ])->assertUnprocessable()->assertJsonValidationErrors('started_at');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs", [
            'started_at' => '2026-01-01 11:00:00',
            'ended_at' => '2026-01-01 09:00:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('ended_at');
    }

    public function test_viewer_cannot_log_time_but_developer_can_edit_and_delete_own(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->viewer());
        $task = $this->makeTask($project, 'Gated');
        $this->login('viewer@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs", [
            'started_at' => '2026-01-01 09:00:00',
            'ended_at' => '2026-01-01 10:00:00',
        ])->assertForbidden();

        $this->addProjectMember($project, $this->editor(), 'developer');
        $this->login('editor@flowsync.test');
        $log = $this->log($task, $this->editor(), '2026-01-01 09:00:00', '2026-01-01 10:00:00');

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs/{$log->id}", [
            'started_at' => '2026-01-01 09:30:00',
            'ended_at' => '2026-01-01 10:30:00',
        ])->assertOk();

        $this->deleteJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs/{$log->id}")
            ->assertOk();

        $this->assertDatabaseMissing('work_logs', ['id' => $log->id]);
    }

    public function test_user_cannot_edit_others_logs_unless_tenant_admin_or_manager(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->viewer());
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Mine not yours');

        $log = $this->log($task, $this->editor(), '2026-01-01 09:00:00', '2026-01-01 10:00:00');
        $this->login('viewer@flowsync.test');

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs/{$log->id}", [
            'started_at' => '2026-01-01 09:00:00',
            'ended_at' => '2026-01-01 10:00:00',
        ])->assertForbidden();

        $this->login('admin@flowsync.test');

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs/{$log->id}", [
            'started_at' => '2026-01-01 09:00:00',
            'ended_at' => '2026-01-01 11:00:00',
        ])->assertOk()
            ->assertJsonPath('work_log.duration_minutes', 120);
    }

    public function test_work_log_routes_are_task_scoped(): void
    {
        $project = $this->createProjectWithLead();
        $a = $this->makeTask($project, 'A');
        $b = $this->makeTask($project, 'B');
        $log = $this->log($a, $this->admin(), '2026-01-01 09:00:00', '2026-01-01 10:00:00');
        $this->login('admin@flowsync.test');

        $this->putJson("/api/projects/{$project->id}/tasks/{$b->id}/work-logs/{$log->id}", [
            'started_at' => '2026-01-01 09:00:00',
            'ended_at' => '2026-01-01 11:00:00',
        ])->assertNotFound();

        $this->deleteJson("/api/projects/{$project->id}/tasks/{$b->id}/work-logs/{$log->id}")
            ->assertNotFound();
    }

    public function test_project_summary_groups_by_user_status_and_date(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $todo = $this->makeTask($project, 'Todo item');
        $done = $this->makeTask($project, 'Done item', 'done');
        $this->log($todo, $this->editor(), '2026-01-05 09:00:00', '2026-01-05 09:30:00');
        $this->log($done, $this->admin(), '2026-01-06 10:00:00', '2026-01-06 10:45:00');
        $this->login('admin@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/time-summary?group_by=user")
            ->assertOk()
            ->assertJsonPath('summary.total_minutes', 75)
            ->assertJsonPath('summary.logs_count', 2)
            ->assertJsonPath('summary.groups.0.label', 'Admin User')
            ->assertJsonPath('summary.groups.0.minutes', 45)
            ->assertJsonPath('summary.groups.1.label', 'Editor User')
            ->assertJsonPath('summary.groups.1.minutes', 30);

        $this->getJson("/api/projects/{$project->id}/time-summary?group_by=status")
            ->assertOk()
            ->assertJsonPath('summary.groups.0.label', 'Done')
            ->assertJsonPath('summary.groups.0.minutes', 45);

        $this->getJson("/api/projects/{$project->id}/time-summary?group_by=date&from=2026-01-06")
            ->assertOk()
            ->assertJsonPath('summary.total_minutes', 45)
            ->assertJsonCount(1, 'summary.groups')
            ->assertJsonPath('summary.groups.0.key', 'date:2026-01-06');
    }

    public function test_workspace_summary_aggregates_across_projects(): void
    {
        $workspace = Workspace::create([
            'tenant_id' => $this->acme->id,
            'created_by' => $this->admin()->id,
            'name' => 'Shared',
            'slug' => 'shared',
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        $project = $this->createProjectIn($workspace, 'Desktop', 'DESK');
        $other = $this->createProjectIn($workspace, 'Mobile', 'MOB');
        $this->addProjectMember($project, $this->editor(), 'developer');
        $this->addProjectMember($other, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Desktop');
        $otherTask = $this->makeTask($other, 'Mobile');
        $this->log($task, $this->editor(), '2026-01-05 09:00:00', '2026-01-05 10:00:00');
        $this->log($otherTask, $this->editor(), '2026-01-06 14:00:00', '2026-01-06 15:00:00');
        $this->login('admin@flowsync.test');

        $this->getJson("/api/workspaces/{$workspace->id}/time-summary?group_by=user")
            ->assertOk()
            ->assertJsonPath('summary.total_minutes', 120)
            ->assertJsonCount(1, 'summary.groups')
            ->assertJsonPath('summary.groups.0.minutes', 120);
    }

    public function test_logging_time_notifies_the_assignee_but_not_the_logger(): void
    {
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Assigned task');
        $task->update(['assignee_id' => $this->editor()->id]);
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs", [
            'started_at' => '2026-01-01 09:00:00',
            'ended_at' => '2026-01-01 10:00:00',
        ])->assertCreated();

        $notification = app(NotificationService::class)
            ->forUser($this->editor())
            ->firstWhere('type', 'task.work_logged');

        $this->assertNotNull($notification);
        $this->assertSame($task->id, $notification->data['task_id']);
        $this->assertSame(60, $notification->data['duration_minutes']);

        $this->assertCount(0, app(NotificationService::class)->forUser($this->admin())
            ->where('type', 'task.work_logged'));
    }

    public function test_work_log_mutations_are_recorded_in_activity_feed(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Audited');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/work-logs", [
            'started_at' => '2026-01-01 09:00:00',
            'ended_at' => '2026-01-01 10:00:00',
        ])->assertCreated();

        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/activities")
            ->assertOk()
            ->assertJsonPath('activities.0.action', 'task.work_logged');
    }
}
