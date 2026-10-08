<?php

namespace Tests\Feature;

use App\Models\IssueType;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\ProjectRole;
use App\Models\ProjectVersion;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class TmsExpansionTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email = 'admin@flowsync.test'): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();

        $this->connectTenant('acme');
    }

    private function admin(): User
    {
        $this->connectTenant('acme');

        return User::where('email', 'admin@flowsync.test')->first();
    }

    private function editor(): User
    {
        $this->connectTenant('acme');

        return User::where('email', 'editor@flowsync.test')->first();
    }

    private function makeWorkspace(string $name = 'TMS WS'): Workspace
    {
        $ws = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
            'description' => 'Test workspace description',
            'color' => '#10b981',
            'timezone' => 'UTC',
        ]);
        $ws->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);
        $ws->members()->attach($this->editor()->id, ['role' => 'member', 'added_by' => $this->admin()->id]);

        return $ws;
    }

    private function makeProject(Workspace $ws, string $name = 'TMS Project', string $key = 'TMS'): Project
    {
        $project = Project::create([
            'workspace_id' => $ws->id,
            'created_by' => $this->admin()->id,
            'lead_user_id' => $this->admin()->id,
            'name' => $name,
            'key' => $key,
            'description' => 'TMS project description',
            'color' => '#6366f1',
        ]);
        $leadRole = ProjectRole::where('slug', 'lead')->first();
        $devRole = ProjectRole::where('slug', 'developer')->first();
        $project->members()->attach($this->admin()->id, ['project_role_id' => $leadRole->id]);
        $project->members()->attach($this->editor()->id, ['project_role_id' => $devRole->id]);

        $this->seedStatuses($project);

        return $project;
    }

    private function seedStatuses(Project $project): void
    {
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
    }

    public function test_workspace_and_project_metadata_expansion(): void
    {
        $this->login();

        $resWs = $this->postJson('/api/workspaces', [
            'name' => 'Extended Workspace',
            'description' => 'Detailed workspace description',
            'icon' => 'briefcase',
            'color' => '#3b82f6',
            'timezone' => 'America/New_York',
            'default_assignee_id' => $this->admin()->id,
            'settings' => ['require_story_points' => true],
        ])->assertCreated();

        $wsId = $resWs->json('workspace.id');
        $this->assertEquals('Detailed workspace description', $resWs->json('workspace.description'));
        $this->assertEquals('#3b82f6', $resWs->json('workspace.color'));
        $this->assertEquals('America/New_York', $resWs->json('workspace.timezone'));
        $this->assertEquals($this->admin()->id, $resWs->json('workspace.default_assignee_id'));
        $this->assertTrue($resWs->json('workspace.settings.require_story_points'));

        $resPrj = $this->postJson("/api/workspaces/{$wsId}/projects", [
            'name' => 'Extended Project',
            'key' => 'EXT',
            'description' => 'Project with Jira features',
            'icon' => 'rocket',
            'color' => '#8b5cf6',
            'default_assignee_id' => $this->admin()->id,
        ])->assertCreated();

        $this->assertEquals('Project with Jira features', $resPrj->json('project.description'));
        $this->assertEquals('#8b5cf6', $resPrj->json('project.color'));
        $this->assertEquals($this->admin()->id, $resPrj->json('project.default_assignee_id'));
    }

    public function test_issue_types_catalog_endpoint(): void
    {
        $this->login();

        $res = $this->getJson('/api/issue-types')->assertOk();
        $slugs = collect($res->json('issue_types'))->pluck('slug')->all();

        $this->assertContains('task', $slugs);
        $this->assertContains('bug', $slugs);
        $this->assertContains('story', $slugs);
        $this->assertContains('epic', $slugs);
        $this->assertContains('subtask', $slugs);
    }

    public function test_project_components_crud_and_uniqueness(): void
    {
        $this->login();
        $ws = $this->makeWorkspace();
        $project = $this->makeProject($ws, 'Component Prj', 'COMP');

        // Create component
        $createRes = $this->postJson("/api/projects/{$project->id}/components", [
            'name' => 'Backend API',
            'description' => 'Core API services',
            'lead_user_id' => $this->admin()->id,
        ])->assertCreated();

        $compId = $createRes->json('component.id');
        $this->assertEquals('Backend API', $createRes->json('component.name'));

        // Duplicate name in same project fails
        $this->postJson("/api/projects/{$project->id}/components", [
            'name' => 'Backend API',
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        // List components
        $listRes = $this->getJson("/api/projects/{$project->id}/components")->assertOk();
        $this->assertCount(1, $listRes->json('components'));
        $this->assertEquals('Backend API', $listRes->json('components.0.name'));

        // Update component
        $this->putJson("/api/projects/{$project->id}/components/{$compId}", [
            'name' => 'Backend Core API',
            'description' => 'Updated description',
        ])->assertOk();

        // Delete component
        $this->deleteJson("/api/projects/{$project->id}/components/{$compId}")->assertOk();
        $this->assertDatabaseMissing('project_components', ['id' => $compId]);
    }

    public function test_project_versions_crud_and_uniqueness(): void
    {
        $this->login();
        $ws = $this->makeWorkspace();
        $project = $this->makeProject($ws, 'Version Prj', 'VER');

        // Create version
        $createRes = $this->postJson("/api/projects/{$project->id}/versions", [
            'name' => 'v1.0.0',
            'description' => 'Initial major release',
            'release_date' => '2026-12-31',
            'released' => false,
        ])->assertCreated();

        $verId = $createRes->json('version.id');
        $this->assertEquals('v1.0.0', $createRes->json('version.name'));

        // Duplicate name in same project fails
        $this->postJson("/api/projects/{$project->id}/versions", [
            'name' => 'v1.0.0',
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        // List versions
        $listRes = $this->getJson("/api/projects/{$project->id}/versions")->assertOk();
        $this->assertCount(1, $listRes->json('versions'));

        // Update version
        $this->putJson("/api/projects/{$project->id}/versions/{$verId}", [
            'name' => 'v1.0.0-rc1',
            'released' => true,
        ])->assertOk();

        $this->assertDatabaseHas('project_versions', ['id' => $verId, 'name' => 'v1.0.0-rc1', 'released' => 1]);

        // Delete version
        $this->deleteJson("/api/projects/{$project->id}/versions/{$verId}")->assertOk();
        $this->assertDatabaseMissing('project_versions', ['id' => $verId]);
    }

    public function test_task_creation_and_updating_with_expanded_tms_attributes(): void
    {
        $this->login();
        $ws = $this->makeWorkspace();
        $project = $this->makeProject($ws, 'Task Prj', 'TSK');

        $component = ProjectComponent::create([
            'project_id' => $project->id,
            'name' => 'Frontend UI',
        ]);

        $version = ProjectVersion::create([
            'project_id' => $project->id,
            'name' => 'v2.0.0',
        ]);

        $bugType = IssueType::where('slug', 'bug')->first();

        // 1. Create task with expanded attributes
        $createRes = $this->postJson("/api/projects/{$project->id}/tasks", [
            'title' => 'Fix header navigation dropdown bug',
            'start_date' => '2026-10-10',
            'due_date' => '2026-10-15',
            'story_points' => 3.5,
            'issue_type_id' => $bugType->id,
            'version_id' => $version->id,
            'components' => [$component->id],
        ])->assertCreated();

        $taskId = $createRes->json('task.id');
        $this->assertEquals('2026-10-10', $createRes->json('task.start_date'));
        $this->assertEquals('2026-10-15', $createRes->json('task.due_date'));
        $this->assertEquals(3.5, $createRes->json('task.story_points'));
        $this->assertEquals($bugType->id, $createRes->json('task.issue_type.id'));
        $this->assertEquals('bug', $createRes->json('task.issue_type.slug'));
        $this->assertEquals($version->id, $createRes->json('task.version.id'));
        $this->assertEquals('v2.0.0', $createRes->json('task.version.name'));
        $this->assertCount(1, $createRes->json('task.components'));
        $this->assertEquals('Frontend UI', $createRes->json('task.components.0.name'));

        // 2. Show task returns expanded attributes
        $showRes = $this->getJson("/api/projects/{$project->id}/tasks/{$taskId}")->assertOk();
        $this->assertEquals(3.5, $showRes->json('task.story_points'));
        $this->assertEquals('bug', $showRes->json('task.issue_type.slug'));

        // 3. Update task expanded attributes
        $storyType = IssueType::where('slug', 'story')->first();
        $updateRes = $this->putJson("/api/projects/{$project->id}/tasks/{$taskId}", [
            'story_points' => 5.0,
            'issue_type_id' => $storyType->id,
            'components' => [], // clear components
        ])->assertOk();

        $this->assertEquals(5.0, $updateRes->json('task.story_points'));
        $this->assertEquals('story', $updateRes->json('task.issue_type.slug'));
        $this->assertEmpty($updateRes->json('task.components'));

        // 4. Index filtering by issue_type_id and version_id
        $listRes = $this->getJson("/api/projects/{$project->id}/tasks?view=list&issue_type_id={$storyType->id}")->assertOk();
        $this->assertCount(1, $listRes->json('tasks'));

        $emptyList = $this->getJson("/api/projects/{$project->id}/tasks?view=list&issue_type_id={$bugType->id}")->assertOk();
        $this->assertCount(0, $emptyList->json('tasks'));

        // 5. Filters payload has issue_types, versions, and components
        $filters = $listRes->json('filters');
        $this->assertArrayHasKey('issue_types', $filters);
        $this->assertArrayHasKey('versions', $filters);
        $this->assertArrayHasKey('components', $filters);
    }

    public function test_task_watchers_lifecycle(): void
    {
        $this->login();
        $ws = $this->makeWorkspace();
        $project = $this->makeProject($ws, 'Watcher Prj', 'WAT');

        $task = Task::create([
            'workspace_id' => $ws->id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'reporter_id' => $this->admin()->id,
            'status_id' => $project->statuses()->first()->id,
            'key' => 'WAT-1',
            'sequence' => 1,
            'title' => 'Watchable task',
            'position' => 1,
        ]);

        // 1. Current user watches task
        $watchRes = $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/watchers")->assertOk();
        $this->assertCount(1, $watchRes->json('watchers'));
        $this->assertEquals($this->admin()->id, $watchRes->json('watchers.0.id'));

        // 2. Add another member as watcher
        $addOther = $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/watchers", [
            'user_id' => $this->editor()->id,
        ])->assertOk();
        $this->assertCount(2, $addOther->json('watchers'));

        // 3. List watchers
        $listWatchers = $this->getJson("/api/projects/{$project->id}/tasks/{$task->id}/watchers")->assertOk();
        $this->assertCount(2, $listWatchers->json('watchers'));

        // 4. Remove watcher
        $this->deleteJson("/api/projects/{$project->id}/tasks/{$task->id}/watchers/{$this->editor()->id}")->assertOk();
        $this->assertDatabaseMissing('task_watchers', [
            'task_id' => $task->id,
            'user_id' => $this->editor()->id,
        ]);

        // 5. Unwatch self
        $this->deleteJson("/api/projects/{$project->id}/tasks/{$task->id}/watchers")->assertOk();
        $this->assertDatabaseMissing('task_watchers', [
            'task_id' => $task->id,
            'user_id' => $this->admin()->id,
        ]);
    }
}
