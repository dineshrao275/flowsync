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

class StatusTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();
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

    private function makeProject(): Project
    {
        $ws = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => 'Design',
            'slug' => 'design',
        ]);
        $ws->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        $project = Project::create([
            'workspace_id' => $ws->id,
            'created_by' => $this->admin()->id,
            'lead_user_id' => $this->admin()->id,
            'name' => 'Alpha',
            'key' => 'ALPHA',
        ]);

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

        $project->members()->attach($this->admin()->id, [
            'project_role_id' => ProjectRole::where('slug', 'lead')->first()->id,
            'added_by' => $this->admin()->id,
        ]);

        return $project;
    }

    private function addDeveloper(Project $project, User $user): void
    {
        $project->members()->attach($user->id, [
            'project_role_id' => ProjectRole::where('slug', 'developer')->first()->id,
            'added_by' => $this->admin()->id,
        ]);
    }

    public function test_project_seeds_default_statuses_ordered(): void
    {
        $project = $this->makeProject();
        $this->login('admin@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/statuses")
            ->assertOk()
            ->assertJsonCount(5, 'statuses')
            ->assertJsonPath('statuses.0.slug', 'backlog')
            ->assertJsonPath('statuses.4.slug', 'done')
            ->assertJsonPath('statuses.4.is_done', true)
            ->assertJsonPath('statuses.1.is_default', true);
    }

    public function test_lead_can_add_status(): void
    {
        $project = $this->makeProject();
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->postJson("/api/projects/{$project->id}/statuses", [
            'name' => 'Blocked',
            'category' => 'in_progress',
            'color' => '#dc2626',
        ])->assertCreated()
            ->assertJsonPath('status.slug', 'blocked')
            ->assertJsonPath('status.is_done', false);

        $this->assertSame(6, $project->statuses()->count());
    }

    public function test_done_category_creates_is_done_true(): void
    {
        $project = $this->makeProject();
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/statuses", [
            'name' => 'Closed',
            'category' => 'done',
        ])->assertCreated()
            ->assertJsonPath('status.is_done', true);
    }

    public function test_developer_cannot_add_status_without_settings_permission(): void
    {
        $project = $this->makeProject();
        $this->addDeveloper($project, $this->editor());
        $this->login('editor@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/statuses", [
            'name' => 'Blocked',
            'category' => 'in_progress',
        ])->assertForbidden();
    }

    public function test_viewer_can_read_statuses_only(): void
    {
        $project = $this->makeProject();
        $project->members()->attach($this->viewer()->id, [
            'project_role_id' => ProjectRole::where('slug', 'viewer')->first()->id,
            'added_by' => $this->admin()->id,
        ]);
        $this->login('viewer@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/statuses")->assertOk();
        $this->postJson("/api/projects/{$project->id}/statuses", [
            'name' => 'Blocked',
            'category' => 'in_progress',
        ])->assertForbidden();
    }

    public function test_lead_can_rename_recolor_and_reorder_status(): void
    {
        $project = $this->makeProject();
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');
        $todo = $project->statuses()->where('slug', 'to-do')->first();

        $this->putJson("/api/projects/{$project->id}/statuses/{$todo->id}", [
            'name' => 'Ready to Start',
            'color' => '#7c3aed',
        ])->assertOk()
            ->assertJsonPath('status.name', 'Ready to Start')
            ->assertJsonPath('status.color', '#7c3aed');

        $this->putJson("/api/projects/{$project->id}/statuses/{$todo->id}", [
            'position' => 5,
        ])->assertOk();

        $order = $project->statuses()->orderBy('position')->pluck('slug')->values();
        $this->assertSame('to-do', $order[4] ?? null);
    }

    public function test_status_with_tasks_cannot_be_deleted(): void
    {
        $project = $this->makeProject();
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');
        $todo = $project->statuses()->where('slug', 'to-do')->first();

        Task::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'key' => 'ALPHA-1',
            'sequence' => 1,
            'title' => 'Task one',
            'status_id' => $todo->id,
        ]);

        $this->deleteJson("/api/projects/{$project->id}/statuses/{$todo->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('form');
    }

    public function test_last_status_cannot_be_deleted(): void
    {
        $project = $this->makeProject();
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        foreach ($project->statuses()->orderByDesc('position')->get() as $status) {
            if ($project->statuses()->count() > 1) {
                $this->deleteJson("/api/projects/{$project->id}/statuses/{$status->id}")->assertOk();
            }
        }

        $remaining = $project->fresh()->statuses()->first();
        $this->deleteJson("/api/projects/{$project->id}/statuses/{$remaining->id}")
            ->assertUnprocessable();
    }

    public function test_unused_status_can_be_deleted_and_positions_normalize(): void
    {
        $project = $this->makeProject();
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');
        $review = $project->statuses()->where('slug', 'in-review')->first();

        $this->deleteJson("/api/projects/{$project->id}/statuses/{$review->id}")->assertOk();

        $positions = $project->fresh()->statuses()->orderBy('position')->pluck('position')->values();
        $this->assertSame(range(1, 4), $positions->all());
    }
}
