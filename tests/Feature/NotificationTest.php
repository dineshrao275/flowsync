<?php

namespace Tests\Feature;

use App\Events\NotificationSent;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Event;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();

        $this->connectTenant('acme');
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
            'created_by' => $this->admin()->id,
            'name' => $workspaceName,
            'slug' => $workspaceSlug,
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        $project = Project::create([
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

    private function makeTask(Project $project, string $title, ?User $assignee = null): Task
    {
        $project->increment('last_task_sequence');
        $default = $project->statuses()->where('is_default', true)->first();

        return Task::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'reporter_id' => $this->admin()->id,
            'assignee_id' => $assignee?->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => $title,
            'status_id' => $default->id,
            'position' => 1,
        ]);
    }

    private function notificationFor(User $user, string $type): array
    {
        $notification = app(NotificationService::class)
            ->forUser($user)
            ->firstWhere('type', $type);

        return $notification ? $notification->toArray() : [];
    }

    public function test_creating_task_notifies_the_assignee_but_not_the_creator(): void
    {
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks", [
            'title' => 'Build login page',
            'assignee_id' => $this->editor()->id,
        ])->assertCreated();

        $notification = $this->notificationFor($this->editor(), 'task.assigned');
        $this->assertNotEmpty($notification);
        $this->assertSame($this->admin()->id, $notification['actor_id']);
        $this->assertSame('Build login page', $notification['data']['title']);
        $this->assertSame('WEB-1', $notification['data']['key']);

        $this->assertSame(0, app(NotificationService::class)->unreadCount($this->admin()));
        Event::assertDispatched(NotificationSent::class, function (NotificationSent $event) use ($notification) {
            return $event->notification->id === $notification['id'];
        });
    }

    public function test_reassigning_a_task_notifies_the_new_assignee_only(): void
    {
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $this->addProjectMember($project, $this->viewer());
        $task = $this->makeTask($project, 'Hand-off', $this->editor());
        $this->login('admin@flowsync.test');

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}", [
            'assignee_id' => $this->viewer()->id,
        ])->assertOk();

        $assigned = $this->notificationFor($this->viewer(), 'task.assigned');
        $this->assertNotEmpty($assigned);
        $this->assertSame($task->id, $assigned['data']['task_id']);

        $this->assertCount(0, app(NotificationService::class)->forUser($this->editor())
            ->where('type', 'task.assigned'));
    }

    public function test_status_change_notifies_the_assignee(): void
    {
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Ship it', $this->editor());
        $this->login('admin@flowsync.test');
        $inProgress = $project->statuses()->where('slug', 'in-progress')->first();

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/move", ['status_id' => $inProgress->id])
            ->assertOk();

        $notification = $this->notificationFor($this->editor(), 'task.status_changed');
        $this->assertNotEmpty($notification);
        $this->assertSame('To Do', $notification['data']['from_status']);
        $this->assertSame('In Progress', $notification['data']['to_status']);
    }

    public function test_comment_mentions_and_assignee_are_notified_but_not_the_author(): void
    {
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Review', $this->admin());
        $this->login('editor@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/comments", [
            'comment' => 'Hey @viewer could you take a look?',
        ])->assertCreated();

        $mention = $this->notificationFor($this->viewer(), 'task.commented');
        $this->assertNotEmpty($mention);
        $this->assertSame($task->id, $mention['data']['task_id']);
        $this->assertSame('Hey @viewer could you take a look?', $mention['data']['snippet']);

        $assignee = $this->notificationFor($this->admin(), 'task.commented');
        $this->assertNotEmpty($assignee);

        $this->assertEmpty($this->notificationFor($this->editor(), 'task.commented'));
    }

    public function test_mention_by_email_and_name_are_resolved_case_insensitively(): void
    {
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $this->addProjectMember($project, $this->viewer());
        $task = $this->makeTask($project, 'Naming');
        $this->login('editor@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/comments", [
            'comment' => 'cc @VIEWER@FLOWSYNC.TEST and @viewer.',
        ])->assertCreated();

        $this->assertCount(1, app(NotificationService::class)->forUser($this->viewer())
            ->where('type', 'task.commented'));
    }

    public function test_removing_the_last_blocker_unblocks_the_assignee(): void
    {
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $blocker = $this->makeTask($project, 'Blocking');
        $task = $this->makeTask($project, 'Blocked', $this->editor());
        TaskDependency::create(['task_id' => $task->id, 'depends_on_task_id' => $blocker->id, 'type' => 'blocks']);
        $this->login('admin@flowsync.test');

        $dependency = $task->dependencies()->first();
        $this->deleteJson("/api/projects/{$project->id}/tasks/{$task->id}/dependencies/{$dependency->id}")
            ->assertOk();

        $notification = $this->notificationFor($this->editor(), 'task.unblocked');
        $this->assertNotEmpty($notification);
        $this->assertSame('Blocking', $notification['data']['blocked_by']['title']);
    }

    public function test_removing_a_blocker_while_others_remain_sends_no_unblock_notification(): void
    {
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $one = $this->makeTask($project, 'One');
        $two = $this->makeTask($project, 'Two');
        $task = $this->makeTask($project, 'Still blocked', $this->editor());
        TaskDependency::create(['task_id' => $task->id, 'depends_on_task_id' => $one->id, 'type' => 'blocks']);
        TaskDependency::create(['task_id' => $task->id, 'depends_on_task_id' => $two->id, 'type' => 'blocks']);
        $this->login('admin@flowsync.test');

        $dependency = $task->dependencies()->where('depends_on_task_id', $one->id)->first();
        $this->deleteJson("/api/projects/{$project->id}/tasks/{$task->id}/dependencies/{$dependency->id}")
            ->assertOk();

        $this->assertEmpty($this->notificationFor($this->editor(), 'task.unblocked'));
    }

    public function test_notification_listing_unread_and_mark_all_read(): void
    {
        Event::fake([NotificationSent::class]);
        $service = app(NotificationService::class);
        $service->notify($this->admin(), 'task.assigned', ['task_id' => 1, 'key' => 'WEB-1'], $this->editor());
        $service->notify($this->admin(), 'task.commented', ['task_id' => 2, 'key' => 'WEB-2'], $this->editor());
        $this->login('admin@flowsync.test');

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'notifications')
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('notifications.1.type', 'task.assigned')
            ->assertJsonPath('notifications.0.actor.name', 'Editor User');

        $this->getJson('/api/notifications/unread')->assertOk()->assertJsonPath('count', 2);

        $this->postJson('/api/notifications/mark-all-read')->assertOk();

        $this->getJson('/api/notifications/unread')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_single_notification_can_be_marked_read(): void
    {
        Event::fake([NotificationSent::class]);
        $notification = app(NotificationService::class)
            ->notify($this->admin(), 'task.assigned', ['task_id' => 1], $this->editor());
        $this->login('admin@flowsync.test');

        $this->postJson("/api/notifications/{$notification->id}/read")->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);

        $this->getJson('/api/notifications/unread')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_marking_another_users_notification_read_is_denied(): void
    {
        Event::fake([NotificationSent::class]);
        $notification = app(NotificationService::class)
            ->notify($this->editor(), 'task.assigned', ['task_id' => 1], $this->admin());
        $this->login('admin@flowsync.test');

        $this->postJson("/api/notifications/{$notification->id}/read")->assertNotFound();
    }
}
