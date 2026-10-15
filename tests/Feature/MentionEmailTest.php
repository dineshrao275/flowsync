<?php

namespace Tests\Feature;

use App\Events\NotificationSent;
use App\Mail\TaskNotificationMail;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class MentionEmailTest extends TestCase
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

    public function test_a_mention_emails_the_assignee_and_the_mentioned_user_but_not_the_author(): void
    {
        Mail::fake();
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Review', $this->admin());
        $this->login('editor@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/comments", [
            'comment' => 'Hey @viewer could you take a look?',
        ])->assertCreated();

        Mail::assertQueued(TaskNotificationMail::class, 2);
        Mail::assertQueued(TaskNotificationMail::class, fn (TaskNotificationMail $mail) => $mail->hasTo($this->admin()->email));
        Mail::assertQueued(TaskNotificationMail::class, fn (TaskNotificationMail $mail) => $mail->hasTo($this->viewer()->email));
        Mail::assertNotSent(TaskNotificationMail::class, fn (TaskNotificationMail $mail) => $mail->hasTo($this->editor()->email));

        $this->assertCount(1, app(NotificationService::class)->forUser($this->viewer()));
        $this->assertNotEmpty(app(NotificationService::class)->forUser($this->viewer())->where('type', 'task.commented'));
    }

    public function test_creating_a_task_emails_the_assignee(): void
    {
        Mail::fake();
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks", [
            'title' => 'Build login page',
            'assignee_id' => $this->editor()->id,
        ])->assertCreated();

        Mail::assertQueued(TaskNotificationMail::class, 1);
        Mail::assertQueued(TaskNotificationMail::class, function (TaskNotificationMail $mail) {
            return $mail->hasTo($this->editor()->email) && str_contains($mail->subjectText, '[WEB-1]');
        });
        Mail::assertNotSent(TaskNotificationMail::class, fn (TaskNotificationMail $mail) => $mail->hasTo($this->admin()->email));
    }

    public function test_a_self_assignment_emails_nobody(): void
    {
        Mail::fake();
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks", [
            'title' => 'Mine',
            'assignee_id' => $this->admin()->id,
        ])->assertCreated();

        Mail::assertNothingQueued();
    }

    public function test_a_status_change_emails_the_assignee(): void
    {
        Mail::fake();
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Ship it', $this->editor());
        $this->login('admin@flowsync.test');
        $inProgress = $project->statuses()->where('slug', 'in-progress')->first();

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/move", ['status_id' => $inProgress->id])
            ->assertOk();

        Mail::assertQueued(TaskNotificationMail::class, 1);
        Mail::assertQueued(TaskNotificationMail::class, function (TaskNotificationMail $mail) {
            return $mail->hasTo($this->editor()->email)
                && $mail->subjectText === '[WEB-1] Moved to In Progress'
                && $mail->task['to_status'] === 'In Progress';
        });
    }

    public function test_an_unblock_emails_the_assignee_with_the_blocker_name(): void
    {
        Mail::fake();
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

        Mail::assertQueued(TaskNotificationMail::class, 1);
        Mail::assertQueued(TaskNotificationMail::class, function (TaskNotificationMail $mail) {
            return $mail->hasTo($this->editor()->email)
                && $mail->task['blocked_by']['title'] === 'Blocking';
        });
    }

    public function test_an_opted_out_recipient_keeps_the_in_app_row_but_gets_no_email(): void
    {
        Mail::fake();
        Event::fake([NotificationSent::class]);
        NotificationPreference::create([
            'user_id' => $this->viewer()->id,
            'preferences' => ['task.commented' => false],
        ]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Review', $this->admin());
        $this->login('editor@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/comments", [
            'comment' => 'cc @viewer',
        ])->assertCreated();

        Mail::assertQueued(TaskNotificationMail::class, 1);
        Mail::assertQueued(TaskNotificationMail::class, fn (TaskNotificationMail $mail) => $mail->hasTo($this->admin()->email));
        Mail::assertNotSent(TaskNotificationMail::class, fn (TaskNotificationMail $mail) => $mail->hasTo($this->viewer()->email));

        $inApp = app(NotificationService::class)->forUser($this->viewer())->where('type', 'task.commented');
        $this->assertCount(1, $inApp);
    }

    public function test_mentions_beyond_the_cap_are_dropped_and_flagged(): void
    {
        Mail::fake();
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead('Cap', 'CAP');
        $this->addProjectMember($project, $this->editor(), 'developer');

        $tokens = collect();
        foreach (range(1, 25) as $i) {
            $user = User::create([
                'name' => "Mention {$i}",
                'email' => "mention{$i}@flowsync.test",
                'password' => 'password',
            ]);
            $this->addProjectMember($project, $user);
            $tokens->push("@mention{$i}");
        }

        // reporter == assignee == actor so only mentioned users remain.
        $task = $this->makeTask($project, 'Big thread', $this->editor());
        $task->forceFill(['reporter_id' => $this->editor()->id])->save();
        $this->login('editor@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/comments", [
            'comment' => $tokens->join(' '),
        ])
            ->assertCreated()
            ->assertJsonPath('truncated_mentions', true);

        Mail::assertQueued(TaskNotificationMail::class, 20);

        $this->assertSame(20, DB::table('notifications')->where('type', 'task.commented')->count());

        $mentionedIds = $tokens->map(fn ($token) => User::where('email', ltrim($token, '@').'@flowsync.test')->value('id'))->all();
        $inApp = DB::table('notifications')->where('type', 'task.commented')->pluck('user_id');
        $this->assertCount(20, $inApp);
        $this->assertCount(5, array_diff($mentionedIds, $inApp->all()));
    }

    public function test_mention_of_a_user_in_another_tenant_resolves_to_nobody(): void
    {
        Mail::fake();
        Event::fake([NotificationSent::class]);
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'No cross tenants');
        $task->forceFill(['reporter_id' => $this->editor()->id])->save();
        $this->login('editor@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/comments", [
            'comment' => 'cc @owner@globex.test',
        ])->assertCreated();

        Mail::assertNothingQueued();
    }

    public function test_the_mailable_renders_from_snapshot_data_without_the_database(): void
    {
        DB::table('tasks')->truncate();

        $mail = TaskNotificationMail::fromData(
            'task.commented',
            'Admin User',
            [
                'key' => 'WEB-1',
                'title' => 'Ship it',
                'project_name' => 'Website',
                'project_id' => 1,
                'workspace_id' => 1,
                'snippet' => 'Take a look at the…',
            ],
            'http://localhost/app/projects/1?tab=tasks&task=WEB-1&section=comments',
        );

        $rendered = $mail->render();

        $this->assertStringContainsString('WEB-1 — Ship it', $rendered);
        $this->assertStringContainsString('Website', $rendered);
        $this->assertStringContainsString('Take a look at the…', $rendered);
        $this->assertStringContainsString('/app/projects/1?tab=tasks&task=WEB-1&section=comments', $rendered);
    }
}
