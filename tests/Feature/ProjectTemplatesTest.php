<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\StatusTransition;
use App\Models\User;
use App\Models\Workspace;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F4.6 — save a project's workflow as a template and start new projects from it. */
class ProjectTemplatesTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private int $workspace;

    private int $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['time_tracking', 'project_templates']);
        $this->loginAdmin();

        $this->workspace = $this->postJson('/api/workspaces', ['name' => 'Tpl WS'])->assertCreated()->json('workspace.id');
        $this->source = $this->postJson("/api/workspaces/{$this->workspace}/projects", ['name' => 'Source', 'key' => 'SRC'])->assertCreated()->json('project.id');

        // A custom column with a WIP limit, a restricted transition set and a component.
        $this->postJson("/api/projects/{$this->source}/statuses", ['name' => 'QA', 'category' => 'in_progress', 'wip_limit' => 3])->assertCreated();
        $statuses = collect($this->getJson("/api/projects/{$this->source}/statuses")->json('statuses'));
        $todo = $statuses->firstWhere('slug', 'to-do')['id'];
        $qa = $statuses->firstWhere('slug', 'qa')['id'];
        $done = $statuses->firstWhere('slug', 'done')['id'];
        $this->putJson("/api/projects/{$this->source}/workflow", [
            'enforce_workflow' => true,
            'transitions' => [['from_status_id' => $todo, 'to_status_id' => $qa], ['from_status_id' => $qa, 'to_status_id' => $done]],
        ])->assertOk();
        $this->postJson("/api/projects/{$this->source}/components", ['name' => 'Backend'])->assertCreated();
        $this->postJson("/api/projects/{$this->source}/tasks", ['title' => 'Kick-off checklist', 'status_id' => $todo])->assertCreated();
    }

    private function save(array $extra = []): array
    {
        return $this->postJson("/api/projects/{$this->source}/save-as-template", ['name' => 'Delivery'] + $extra)->assertCreated()->json('template');
    }

    public function test_a_template_copies_the_workflow_components_and_optionally_starter_tasks(): void
    {
        $template = $this->save(['include_tasks' => true, 'description' => 'Standard delivery']);
        $this->assertSame(1, $template['tasks']);
        $this->assertSame(1, $template['components']);
        $this->assertContains('QA', $template['statuses']);

        $created = $this->postJson("/api/workspaces/{$this->workspace}/projects/from-template", [
            'template_id' => $template['id'], 'name' => 'Client A', 'key' => 'CLA',
        ])->assertCreated()->json('project');

        $project = Project::findOrFail($created['id']);
        $this->assertTrue((bool) $project->enforce_workflow);
        $statuses = $project->statuses()->orderBy('position')->get();
        $this->assertSame($this->getJson("/api/projects/{$this->source}/statuses")->json('statuses.*.name'), $statuses->pluck('name')->all());
        $this->assertSame(3, $statuses->firstWhere('slug', 'qa')->wip_limit);
        $this->assertSame(2, StatusTransition::where('project_id', $project->id)->count());
        $this->assertSame(['Backend'], $project->components()->pluck('name')->all());
        $this->assertSame(['Kick-off checklist'], $project->tasks()->pluck('title')->all());
        // The new project is its own thing: the creator leads it, the source is untouched.
        $this->assertSame(2, StatusTransition::where('project_id', $this->source)->count());
    }

    public function test_tasks_are_left_out_unless_asked_for(): void
    {
        $template = $this->save();
        $this->assertSame(0, $template['tasks']);

        $created = $this->postJson("/api/workspaces/{$this->workspace}/projects/from-template", ['template_id' => $template['id'], 'name' => 'No tasks'])->assertCreated()->json('project');

        $this->assertSame(0, Project::findOrFail($created['id'])->tasks()->count());
    }

    public function test_only_a_project_manager_saves_and_only_the_creator_or_an_admin_deletes(): void
    {
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        Workspace::find($this->workspace)->members()->syncWithoutDetaching([$editor->id => ['role' => 'member', 'added_by' => $editor->id]]);
        Project::find($this->source)->members()->attach($editor->id, ['project_role_id' => ProjectRole::where('slug', 'developer')->value('id'), 'added_by' => $editor->id]);
        $template = $this->save();

        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');

        $this->postJson("/api/projects/{$this->source}/save-as-template", ['name' => 'Nope'])->assertForbidden();
        $this->getJson('/api/project-templates')->assertOk()->assertJsonCount(1, 'templates');
        $this->deleteJson("/api/project-templates/{$template['id']}")->assertForbidden();

        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');
        $this->deleteJson("/api/project-templates/{$template['id']}")->assertOk();
    }

    public function test_an_unknown_template_is_refused_and_the_module_gates_the_feature(): void
    {
        $this->postJson("/api/workspaces/{$this->workspace}/projects/from-template", ['template_id' => 9999, 'name' => 'X'])->assertUnprocessable();

        $this->setAcmeModules(['time_tracking']);
        $this->getJson('/api/project-templates')->assertForbidden();
    }
}
