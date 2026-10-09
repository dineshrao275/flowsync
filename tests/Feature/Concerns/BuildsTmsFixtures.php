<?php

namespace Tests\Feature\Concerns;

use App\Models\IssueType;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;

/**
 * Shared fixtures for the Phase 4 TMS feature tests: a project in Acme with the default statuses,
 * the admin as lead, helper members with a chosen project role, and an API task factory.
 * Requires IsolatesDatabase on the using test case.
 */
trait BuildsTmsFixtures
{
    protected Project $project;

    protected function buildProject(string $key = 'PP', ?Workspace $workspace = null): Project
    {
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $workspace ??= Workspace::create(['created_by' => $admin->id, 'name' => 'W '.$key, 'slug' => 'w-'.strtolower($key)]);
        $workspace->members()->syncWithoutDetaching([$admin->id => ['role' => 'owner', 'added_by' => $admin->id]]);
        $project = Project::create(['workspace_id' => $workspace->id, 'created_by' => $admin->id, 'lead_user_id' => $admin->id, 'name' => 'P '.$key, 'key' => $key]);
        $project->members()->attach($admin->id, ['project_role_id' => ProjectRole::where('slug', 'lead')->firstOrFail()->id, 'added_by' => $admin->id]);
        foreach (config('task_statuses.statuses') as $s) {
            TaskStatus::create(['project_id' => $project->id, 'name' => $s['name'], 'slug' => $s['slug'], 'category' => $s['category'], 'position' => $s['position'], 'color' => $s['color'], 'is_done' => $s['is_done'], 'is_default' => $s['is_default'] ?? false]);
        }

        return $project;
    }

    /** A tenant member (viewer tenant role) who joined the project with the given project-role slug, or none. */
    protected function member(Project $project, string $email, ?string $projectRole = 'developer'): User
    {
        $user = User::where('email', $email)->first() ?? User::factory()->create(['email' => $email, 'password' => 'password']);
        if (! $user->roles()->exists()) {
            $user->roles()->attach(Role::where('slug', 'viewer')->firstOrFail());
        }
        $project->workspace->members()->syncWithoutDetaching([$user->id => ['role' => 'member', 'added_by' => $user->id]]);
        if ($projectRole !== null) {
            $project->members()->syncWithoutDetaching([$user->id => ['project_role_id' => ProjectRole::where('slug', $projectRole)->firstOrFail()->id, 'added_by' => $user->id]]);
        }

        return $user;
    }

    protected function issueType(string $slug): int
    {
        return IssueType::where('slug', $slug)->firstOrFail()->id;
    }

    protected function statusId(Project $project, string $category): int
    {
        return $project->statuses()->get()->first(fn ($s) => $s->category->value === $category)->id;
    }

    /** Creates a task through the API (as whoever is logged in) and returns the task payload. */
    protected function apiTask(Project $project, array $data = []): array
    {
        return $this->postJson("/api/projects/{$project->id}/tasks", $data + ['title' => 'Task '.uniqid()])->assertCreated()->json('task');
    }
}
