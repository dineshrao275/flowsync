<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\NotificationService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class DeepLinkAccessTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();

        $this->connectTenant('acme');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->first();
    }

    private function viewer(): User
    {
        return User::where('email', 'viewer@flowsync.test')->first();
    }

    private function makeWorkspace(): Workspace
    {
        $workspace = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => 'Design',
            'slug' => 'design',
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
            'name' => 'Releases',
            'key' => 'REL',
        ]);
        $leadRole = ProjectRole::where('slug', 'lead')->first();
        $project->members()->attach($this->admin()->id, ['project_role_id' => $leadRole->id, 'added_by' => $this->admin()->id]);

        foreach (config('task_statuses.statuses') as $status) {
            TaskStatus::create([
                'project_id' => $project->id,
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => $status['category'],
                'position' => $status['position'],
                'color' => $status['color'],
                'is_done' => $status['is_done'],
                'is_default' => $status['is_default'] ?? false,
            ]);
        }

        return $project;
    }

    private function makeTask(Project $project, string $key = 'REL-1'): Task
    {
        $status = TaskStatus::where('project_id', $project->id)->where('slug', 'to-do')->first();

        return Task::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'reporter_id' => $this->admin()->id,
            'assignee_id' => null,
            'status_id' => $status->id,
            'priority_id' => null,
            'key' => $key,
            'sequence' => 1,
            'title' => 'Ship release',
            'position' => 1,
        ]);
    }

    public function test_deep_link_to_project_returns_403_for_non_member(): void
    {
        $workspace = $this->makeWorkspace();
        $project = $this->makeProject($workspace);
        $this->makeTask($project);

        $this->login('viewer@flowsync.test');

        $this->getJson("/api/projects/{$project->id}")->assertForbidden();
        $this->getJson("/api/projects/{$project->id}/tasks")->assertForbidden();
        $this->getJson("/api/projects/{$project->id}/tasks/{$project->tasks()->first()->id}")->assertForbidden();
    }

    public function test_deep_link_to_workspace_returns_403_for_non_member(): void
    {
        $workspace = $this->makeWorkspace();

        $this->login('viewer@flowsync.test');

        $this->getJson("/api/workspaces/{$workspace->id}")->assertForbidden();
    }

    public function test_deep_link_exposes_nothing_across_tenants(): void
    {
        $workspace = $this->makeWorkspace();
        $project = $this->makeProject($workspace);
        $this->makeTask($project);

        $globexOwner = $this->dbm->using($this->globex(), fn () => User::where('email', 'owner@globex.test')->first());

        // Cross-tenant deep link: the Acme project does not exist in the Globex tenant database.
        $this->postJson('/api/auth/login', [
            'email' => $globexOwner->email,
            'password' => 'password',
        ])->assertOk();

        $this->getJson("/api/projects/{$project->id}")->assertNotFound();

        $this->connectTenant('acme');
        $this->assertSame($project->id, Project::find($project->id)->id);
    }

    public function test_notification_payload_carries_deep_link_fields(): void
    {
        $workspace = $this->makeWorkspace();
        $project = $this->makeProject($workspace);
        $task = $this->makeTask($project, 'REL-7');

        $this->login('viewer@flowsync.test');

        $payload = app(NotificationService::class)->notify($this->viewer(), 'task.commented', [
            'task_id' => $task->id,
            'key' => $task->key,
            'title' => $task->title,
            'project_id' => $project->id,
            'project_name' => $project->name,
            'workspace_id' => $project->workspace_id,
            'comment_id' => 1,
            'snippet' => 'Hello',
        ]);

        $response = $this->getJson('/api/notifications')->assertOk();

        $data = collect($response->json('notifications'))->firstWhere('id', $payload->id)['data'];

        $this->assertSame($task->key, $data['key']);
        $this->assertSame($project->id, $data['project_id']);
        $this->assertSame($task->id, $data['task_id']);
        $this->assertSame($project->workspace_id, $data['workspace_id']);
    }
}
