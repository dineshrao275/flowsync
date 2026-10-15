<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** FB-2c — losing a workspace takes every project of it, and every task action, with it. */
class WorkspaceAccessRevocationTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    public function test_removing_a_workspace_member_revokes_their_project_access(): void
    {
        $admin = User::where('email', 'admin@flowsync.test')->first();
        $editor = User::where('email', 'editor@flowsync.test')->first();

        $workspace = Workspace::create(['created_by' => $admin->id, 'name' => 'Rev', 'slug' => 'rev']);
        $workspace->members()->attach($admin->id, ['role' => 'owner', 'added_by' => $admin->id]);
        $workspace->members()->attach($editor->id, ['role' => 'member', 'added_by' => $admin->id]);

        $project = Project::create([
            'workspace_id' => $workspace->id, 'created_by' => $admin->id, 'lead_user_id' => $admin->id,
            'name' => 'Rev project', 'key' => 'REV',
        ]);
        $project->members()->attach($admin->id, ['project_role_id' => ProjectRole::where('slug', 'lead')->first()->id, 'added_by' => $admin->id]);
        $project->members()->attach($editor->id, ['project_role_id' => ProjectRole::where('slug', 'developer')->first()->id, 'added_by' => $admin->id]);
        foreach (config('task_statuses.statuses') as $s) {
            TaskStatus::create([
                'project_id' => $project->id, 'name' => $s['name'], 'slug' => $s['slug'], 'category' => $s['category'],
                'position' => $s['position'], 'color' => $s['color'], 'is_done' => $s['is_done'], 'is_default' => $s['is_default'] ?? false,
            ]);
        }
        $todo = TaskStatus::where('project_id', $project->id)->where('slug', 'to-do')->first();
        $done = TaskStatus::where('project_id', $project->id)->where('category', 'done')->first();
        $task = Task::create([
            'workspace_id' => $workspace->id, 'project_id' => $project->id, 'created_by' => $admin->id,
            'reporter_user_id' => $admin->id, 'status_id' => $todo->id, 'key' => 'REV-1', 'sequence' => 1,
            'title' => 'Revocable', 'position' => 1,
        ]);

        // Before: the developer can move and comment, and sees the project.
        $this->login('editor@flowsync.test');
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/move", ['status_id' => $done->id])->assertOk();
        $this->assertContains($project->id, array_column($this->getJson('/api/projects')->assertOk()->json('projects'), 'id'));

        // The workspace owner removes them.
        $this->postJson('/api/auth/logout');
        $this->login('admin@flowsync.test');
        $this->deleteJson("/api/workspaces/{$workspace->id}/members/{$editor->id}")->assertSuccessful();
        $this->assertDatabaseMissing('project_members', ['project_id' => $project->id, 'user_id' => $editor->id]);

        // After: nothing in the workspace is reachable.
        $this->postJson('/api/auth/logout');
        $this->login('editor@flowsync.test');
        $this->assertNotContains($project->id, array_column($this->getJson('/api/projects')->assertOk()->json('projects'), 'id'));
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/move", ['status_id' => $todo->id])->assertForbidden();
        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/comments", ['comment' => 'still here?'])->assertForbidden();
        $this->getJson("/api/projects/{$project->id}/tasks")->assertForbidden();
    }
}
