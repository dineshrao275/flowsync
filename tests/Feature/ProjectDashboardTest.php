<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\User;
use App\Models\Workspace;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F4.5 — project dashboards: a widget catalogue, personal layouts over a shared default, scoped data. */
class ProjectDashboardTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private int $project;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['reports', 'time_tracking']);
        $this->loginAdmin();
        $this->adminId = (int) User::where('email', 'admin@flowsync.test')->value('id');

        $workspace = $this->postJson('/api/workspaces', ['name' => 'Dash WS'])->assertCreated()->json('workspace.id');
        $this->project = $this->postJson("/api/workspaces/{$workspace}/projects", ['name' => 'Dash', 'key' => 'DSH'])->assertCreated()->json('project.id');

        $this->postJson("/api/projects/{$this->project}/tasks", ['title' => 'Late one', 'assignee_id' => $this->adminId, 'due_date' => now()->subDays(3)->toDateString()])->assertCreated();
        $this->postJson("/api/projects/{$this->project}/tasks", ['title' => 'Upcoming', 'assignee_id' => $this->adminId, 'due_date' => now()->addDays(2)->toDateString()])->assertCreated();
        $this->postJson("/api/projects/{$this->project}/tasks", ['title' => 'Unowned'])->assertCreated();
    }

    public function test_the_default_layout_returns_live_widget_data(): void
    {
        $r = $this->getJson("/api/projects/{$this->project}/dashboard")->assertOk()->json();

        $this->assertSame(['overview', 'status_breakdown', 'assignee_load', 'overdue_list', 'created_vs_done'], $r['widgets']);
        $this->assertSame(['total' => 3, 'open' => 3, 'done' => 0, 'overdue' => 1], $r['data']['overview']);
        $this->assertSame(3, $r['data']['status_breakdown'][0]['count']);
        $this->assertSame(['Late one'], array_column($r['data']['overdue_list'], 'title'));
        $this->assertCount(14, $r['data']['created_vs_done']);
        $this->assertSame(3, array_sum(array_column($r['data']['created_vs_done'], 'created')));

        $load = collect($r['data']['assignee_load']);
        $this->assertSame(2, $load->firstWhere('label', User::find($this->adminId)->name)['open']);
        $this->assertSame(1, $load->firstWhere('label', 'Unassigned')['open']);
    }

    public function test_a_personal_layout_overrides_the_shared_default_and_can_be_reset(): void
    {
        $this->putJson("/api/projects/{$this->project}/dashboard", ['widgets' => ['due_soon', 'priority_breakdown'], 'shared' => true])->assertOk();
        $this->assertSame(['due_soon', 'priority_breakdown'], $this->getJson("/api/projects/{$this->project}/dashboard")->json('widgets'));
        $this->assertSame(['Upcoming'], array_column($this->getJson("/api/projects/{$this->project}/dashboard")->json('data.due_soon'), 'title'));

        $this->putJson("/api/projects/{$this->project}/dashboard", ['widgets' => ['overview']])->assertOk();
        $this->assertSame(['overview'], $this->getJson("/api/projects/{$this->project}/dashboard")->json('widgets'));

        $this->deleteJson("/api/projects/{$this->project}/dashboard")->assertOk();
        $this->assertSame(['due_soon', 'priority_breakdown'], $this->getJson("/api/projects/{$this->project}/dashboard")->json('widgets'));
    }

    public function test_unknown_widgets_are_refused_and_only_managers_set_the_shared_default(): void
    {
        $this->putJson("/api/projects/{$this->project}/dashboard", ['widgets' => ['eval_php']])->assertUnprocessable();
        $this->putJson("/api/projects/{$this->project}/dashboard", ['widgets' => []])->assertUnprocessable();

        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        Workspace::first()->members()->syncWithoutDetaching([$editor->id => ['role' => 'member', 'added_by' => $editor->id]]);
        Project::find($this->project)->members()->attach($editor->id, ['project_role_id' => ProjectRole::where('slug', 'developer')->value('id'), 'added_by' => $editor->id]);

        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');

        $this->putJson("/api/projects/{$this->project}/dashboard", ['widgets' => ['overview'], 'shared' => true])->assertForbidden();
        $this->putJson("/api/projects/{$this->project}/dashboard", ['widgets' => ['overview']])->assertOk();
    }

    public function test_non_members_are_refused_and_the_reports_module_gates_it(): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'viewer@flowsync.test', 'password' => 'password'])->assertOk();
        $this->getJson("/api/projects/{$this->project}/dashboard")->assertForbidden();

        $this->postJson('/api/auth/logout');
        $this->loginAdmin();
        $this->setAcmeModules(['time_tracking']);
        $this->getJson("/api/projects/{$this->project}/dashboard")->assertForbidden();
    }
}
