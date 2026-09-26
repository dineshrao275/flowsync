<?php

namespace Tests\Feature;

use App\Enums\TaskDependencyType;
use App\Events\CommentSynced;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class CollaborationTest extends TestCase
{
    use IsolatesDatabase;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = Tenant::where('slug', 'acme')->first();
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

    private function makeWorkspace(string $name = 'Design', string $slug = 'design'): Workspace
    {
        $workspace = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => $name,
            'slug' => $slug,
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        return $workspace;
    }

    private function createProjectWithLead(string $name = 'Website', string $key = 'WEB', string $workspaceName = 'Design', string $workspaceSlug = 'design'): Project
    {
        $workspace = $this->makeWorkspace($workspaceName, $workspaceSlug);
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

    private function makeTask(Project $project, string $title = 'Task'): Task
    {
        $project->increment('last_task_sequence');

        return Task::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => $title,
            'status_id' => $project->statuses()->where('is_default', true)->first()->id,
            'position' => 1,
        ]);
    }

    // ------------------------------------------------------------------ comments

    public function test_member_with_comment_permission_can_create_comment_and_reply(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Discuss');
        $this->login('editor@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/comments", ['comment' => 'First!'])
            ->assertCreated()
            ->assertJsonPath('comment.comment', 'First!')
            ->assertJsonPath('comment.user.id', $this->editor()->id);

        $firstId = $task->comments()->first()->id;

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/comments", [
            'comment' => 'Replying.',
            'parent_id' => $firstId,
        ])->assertCreated();

        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'comments')
            ->assertJsonCount(1, 'comments.0.replies')
            ->assertJsonPath('comments.0.replies.0.comment', 'Replying.');
    }

    public function test_viewer_can_create_but_not_edit_others_comments(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->viewer());
        $task = $this->makeTask($project, 'Thread');
        $adminComment = $task->comments()->create([
            'task_id' => $task->id,
            'user_id' => $this->admin()->id,
            'comment' => 'From the lead',
        ]);
        $this->login('viewer@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/comments", ['comment' => 'Viewer note'])->assertCreated();

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}/comments/{$adminComment->id}", ['comment' => 'Hijack'])
            ->assertForbidden();

        $own = $task->comments()->where('user_id', $this->viewer()->id)->first();
        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}/comments/{$own->id}", ['comment' => 'Edited own'])
            ->assertOk()
            ->assertJsonPath('comment.comment', 'Edited own')
            ->assertJsonPath('comment.edited_at', fn ($v) => $v !== null);
    }

    public function test_comment_update_and_delete_are_task_scoped(): void
    {
        $project = $this->createProjectWithLead();
        $a = $this->makeTask($project, 'A');
        $b = $this->makeTask($project, 'B');
        $comment = $a->comments()->create([
            'task_id' => $a->id,
            'user_id' => $this->admin()->id,
            'comment' => 'On A',
        ]);
        $this->login('admin@flowsync.test');

        $this->putJson("/api/projects/{$project->id}/tasks/{$b->id}/comments/{$comment->id}", ['comment' => 'Nope'])
            ->assertNotFound();
        $this->deleteJson("/api/projects/{$project->id}/tasks/{$b->id}/comments/{$comment->id}")
            ->assertNotFound();

        $this->deleteJson("/api/projects/{$project->id}/tasks/{$a->id}/comments/{$comment->id}")->assertOk();
        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    public function test_comment_events_broadcast_on_project_channel(): void
    {
        $project = $this->createProjectWithLead();
        $task = $this->makeTask($project, 'Loud thread');
        Event::fake(CommentSynced::class);
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/comments", ['comment' => 'Hi'])
            ->assertCreated();

        Event::assertDispatched(CommentSynced::class, function ($event) use ($task) {
            return $event->comment->task_id === $task->id && $event->action === 'created';
        });
    }

    // ------------------------------------------------------------------ dependencies

    public function test_block_dependency_is_created_listed_and_exposed(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $parent = $this->makeTask($project, 'Feature');
        $child = $this->makeTask($project, 'Sub-task');
        $this->login('editor@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$child->id}/dependencies", [
            'depends_on_task_id' => $parent->id,
            'type' => 'blocks',
        ])->assertCreated()->assertJsonPath('dependency.task.key', 'WEB-1');

        $this->getJson("/api/projects/{$project->id}/tasks/{$child->id}/dependencies")
            ->assertOk()
            ->assertJsonCount(1, 'blocked_by')
            ->assertJsonCount(0, 'blocks')
            ->assertJsonPath('blocks', []);

        $this->getJson("/api/projects/{$project->id}/tasks/{$child->id}")
            ->assertOk()
            ->assertJsonPath('task.open_blockers_count', 1);
    }

    public function test_cycle_dependency_is_rejected(): void
    {
        $project = $this->createProjectWithLead();
        $a = $this->makeTask($project, 'A');
        $b = $this->makeTask($project, 'B');
        $c = $this->makeTask($project, 'C');

        TaskDependency::create(['task_id' => $a->id, 'depends_on_task_id' => $b->id, 'type' => TaskDependencyType::Blocks]);
        TaskDependency::create(['task_id' => $b->id, 'depends_on_task_id' => $c->id, 'type' => TaskDependencyType::Blocks]);

        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$c->id}/dependencies", [
            'depends_on_task_id' => $a->id,
            'type' => 'blocks',
        ])->assertUnprocessable()->assertJsonValidationErrors('form');

        $this->postJson("/api/projects/{$project->id}/tasks/{$a->id}/dependencies", [
            'depends_on_task_id' => $a->id,
            'type' => 'blocks',
        ])->assertUnprocessable()->assertJsonValidationErrors('depends_on_task_id');
    }

    public function test_cross_project_dependency_and_duplicates_are_rejected(): void
    {
        $project = $this->createProjectWithLead();
        $other = $this->createProjectWithLead('Mobile', 'Mob', 'Mobile', 'mobile');
        $task = $this->makeTask($project, 'Main');
        $outside = $this->makeTask($other, 'Elsewhere');
        $local = $this->makeTask($project, 'Local');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/dependencies", [
            'depends_on_task_id' => $outside->id,
            'type' => 'blocks',
        ])->assertUnprocessable()->assertJsonValidationErrors('depends_on_task_id');

        TaskDependency::create(['task_id' => $task->id, 'depends_on_task_id' => $local->id, 'type' => TaskDependencyType::Blocks]);

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/dependencies", [
            'depends_on_task_id' => $local->id,
            'type' => 'blocks',
        ])->assertUnprocessable()->assertJsonValidationErrors('form');
    }

    public function test_viewer_cannot_add_dependency_but_array_remove_unblocks(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->viewer());
        $done = $project->statuses()->where('slug', 'done')->first();
        $parent = $this->makeTask($project, 'Parent');
        $child = $this->makeTask($project, 'Child');
        $child->update(['completed_at' => now()]);
        TaskDependency::create(['task_id' => $child->id, 'depends_on_task_id' => $parent->id, 'type' => TaskDependencyType::Blocks]);
        $this->login('viewer@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$child->id}/dependencies", [
            'depends_on_task_id' => $parent->id,
            'type' => 'related_to',
        ])->assertForbidden();

        $this->deleteJson("/api/projects/{$project->id}/tasks/{$child->id}/dependencies/{$child->dependencies()->first()->id}")
            ->assertForbidden();
    }

    public function test_removing_dependency_unblocks_move_to_done(): void
    {
        $project = $this->createProjectWithLead();
        $done = $project->statuses()->where('slug', 'done')->first();
        $parent = $this->makeTask($project, 'Parent');
        $child = $this->makeTask($project, 'Child');
        $dep = TaskDependency::create(['task_id' => $child->id, 'depends_on_task_id' => $parent->id, 'type' => TaskDependencyType::Blocks]);
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$child->id}/move", ['status_id' => $done->id])
            ->assertUnprocessable();

        $this->deleteJson("/api/projects/{$project->id}/tasks/{$child->id}/dependencies/{$dep->id}")->assertOk();

        $this->postJson("/api/projects/{$project->id}/tasks/{$child->id}/move", ['status_id' => $done->id])
            ->assertOk();
    }

    // ------------------------------------------------------------------ attachments

    public function test_member_with_attachment_permission_can_upload_and_list(): void
    {
        Storage::fake('local');
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $task = $this->makeTask($project, 'Files');
        $this->login('editor@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/attachments", [
            'file' => UploadedFile::fake()->create('design-spec.pdf', 512),
        ])->assertCreated()
            ->assertJsonPath('attachment.original_name', 'design-spec.pdf')
            ->assertJsonPath('attachment.download_url', fn ($url) => str_contains($url, '/attachments/') && str_contains($url, '/download'));

        $this->assertDatabaseHas('attachments', ['task_id' => $task->id, 'original_name' => 'design-spec.pdf']);

        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/attachments")
            ->assertOk()
            ->assertJsonCount(1, 'attachments')
            ->assertJsonPath('attachments.0.user.name', 'Editor User');
    }

    public function test_signed_download_serves_the_file_and_bad_signatures_are_rejected(): void
    {
        Storage::fake('local');
        $project = $this->createProjectWithLead();
        $task = $this->makeTask($project, 'Files');

        $file = UploadedFile::fake()->create('notes.txt', 100, 'text/plain');
        $path = $file->storeAs("tasks/{$this->acme->id}/{$task->id}", 'abc.txt', ['disk' => 'local']);
        $attachment = Attachment::create([
            'task_id' => $task->id,
            'user_id' => $this->admin()->id,
            'stored_name' => 'abc.txt',
            'original_name' => 'notes.txt',
            'mime' => 'text/plain',
            'size' => 100,
            'disk' => 'local',
            'path' => $path,
        ]);

        $this->login('admin@flowsync.test');

        $url = $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/attachments")
            ->json('attachments.0.download_url');

        $this->get($url)->assertOk()->assertHeader('content-disposition', 'attachment; filename=notes.txt');

        $this->get("/api/tasks/{$task->id}/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }

    public function test_signed_download_works_without_a_session_on_the_central_connection(): void
    {
        Storage::fake('local');
        $project = $this->createProjectWithLead();
        $task = $this->makeTask($project, 'Files');

        $file = UploadedFile::fake()->create('notes.txt', 100, 'text/plain');
        $path = $file->storeAs("tasks/{$this->acme->id}/{$task->id}", 'fresh-tab.txt', ['disk' => 'local']);
        $attachment = Attachment::create([
            'task_id' => $task->id,
            'user_id' => $this->admin()->id,
            'stored_name' => 'fresh-tab.txt',
            'original_name' => 'notes.txt',
            'mime' => 'text/plain',
            'size' => 100,
            'disk' => 'local',
            'path' => $path,
        ]);

        $this->login('admin@flowsync.test');

        $url = $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/attachments")
            ->json('attachments.0.download_url');

        $this->assertStringContainsString('tenant='.$this->acme->id, $url);

        // A brand-new browser tab carries no tenant state at all: no session and
        // the default connection still pointing at the central system DB. The
        // signature alone has to be enough to find the tenant and the file.
        $this->flushSession();
        DB::setDefaultConnection('iso_system');

        $this->get($url)->assertOk()->assertHeader('content-disposition', 'attachment; filename=notes.txt');

        // Signing the link by hand (rather than reading download_url) keeps this
        // test honest about the download path itself: task/attachment ids are
        // tenant-local, so without the signed tenant the binding query would run
        // against the central database and blow up on "relation tasks does not exist".
        $handSigned = URL::temporarySignedRoute('attachments.download', now()->addHour(), [
            'task' => $task->id,
            'attachment' => $attachment->id,
            'tenant' => $this->acme->id,
        ]);

        $this->get($handSigned)->assertOk()->assertHeader('content-disposition', 'attachment; filename=notes.txt');

        // An attachment id from a different tenant must not resolve here.
        $wrongTenant = URL::temporarySignedRoute('attachments.download', now()->addHour(), [
            'task' => $task->id,
            'attachment' => $attachment->id,
            'tenant' => $this->acme->id + 1,
        ]);

        $this->get($wrongTenant)->assertNotFound();
    }

    public function test_oversized_upload_is_rejected(): void
    {
        Storage::fake('local');
        $project = $this->createProjectWithLead();
        $task = $this->makeTask($project, 'Files');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/attachments", [
            'file' => UploadedFile::fake()->create('huge.zip', 11 * 1024),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_viewer_cannot_upload_and_owner_can_delete_removing_file(): void
    {
        Storage::fake('local');
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->viewer());
        $task = $this->makeTask($project, 'Files');

        $this->login('viewer@flowsync.test');
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/attachments", [
            'file' => UploadedFile::fake()->create('secret.txt', 10),
        ])->assertForbidden();

        $this->login('admin@flowsync.test');
        $attachment = Attachment::create([
            'task_id' => $task->id,
            'user_id' => $this->admin()->id,
            'stored_name' => 'keep.txt',
            'original_name' => 'keep.txt',
            'mime' => 'text/plain',
            'size' => 10,
            'disk' => 'local',
            'path' => "tasks/{$this->acme->id}/{$task->id}/keep.txt",
        ]);
        Storage::disk('local')->put($attachment->path, 'data');

        $this->deleteJson("/api/projects/{$project->id}/tasks/{$task->id}/attachments/{$attachment->id}")
            ->assertOk();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->path);
    }

    // ------------------------------------------------------------------ activity

    public function test_task_activity_feed_tracks_mutations(): void
    {
        $project = $this->createProjectWithLead();
        $task = $this->makeTask($project, 'Saga');
        $this->login('admin@flowsync.test');

        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}", ['title' => 'Saga v2'])->assertOk();
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/comments", ['comment' => 'noted'])->assertCreated();

        $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/activities")
            ->assertOk()
            ->assertJsonCount(2, 'activities')
            ->assertJsonPath('activities.0.action', 'task.commented')
            ->assertJsonPath('activities.1.action', 'task.updated')
            ->assertJsonPath('activities.1.actor.name', 'Admin User');
    }

    public function test_project_activity_feed_includes_task_events(): void
    {
        $project = $this->createProjectWithLead();
        $task = $this->makeTask($project, 'Eventful');
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/move", ['status_id' => $project->statuses()->where('slug', 'in-progress')->first()->id])
            ->assertOk();

        $this->getJson("/api/projects/{$project->id}/activities")
            ->assertOk()
            ->assertJsonPath('activities.0.action', 'task.moved');

        $saga = $this->makeTask($project, 'Saga');
        $this->postJson("/api/projects/{$project->id}/tasks/{$saga->id}/comments", ['comment' => 'hello'])
            ->assertCreated();

        $this->getJson("/api/projects/{$project->id}/activities")
            ->assertOk()
            ->assertJsonPath('activities.0.action', 'task.commented');
    }
}
