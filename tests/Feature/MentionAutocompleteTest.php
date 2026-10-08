<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class MentionAutocompleteTest extends TestCase
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

    private function editor(): User
    {
        return User::where('email', 'editor@flowsync.test')->first();
    }

    private function viewer(): User
    {
        return User::where('email', 'viewer@flowsync.test')->first();
    }

    private function createProjectWithLead(string $name = 'Website', string $key = 'WEB'): Project
    {
        $workspace = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => 'Design',
            'slug' => 'design',
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

    public function test_a_member_can_autocomplete_by_name_prefix(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $this->login('editor@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/members/autocomplete?q=adm")
            ->assertOk()
            ->assertJsonCount(1, 'members')
            ->assertJsonPath('members.0.id', $this->admin()->id)
            ->assertJsonPath('members.0.name', $this->admin()->name)
            ->assertJsonPath('members.0.email', $this->admin()->email);
    }

    public function test_autocomplete_matches_the_email_local_part(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $this->login('editor@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/members/autocomplete?q=admin")
            ->assertOk()
            ->assertJsonCount(1, 'members');

        $this->getJson("/api/projects/{$project->id}/members/autocomplete?q=viewer")
            ->assertOk()
            ->assertJsonCount(0, 'members');
    }

    public function test_autocomplete_is_scoped_to_project_members(): void
    {
        $project = $this->createProjectWithLead();
        $this->login('admin@flowsync.test');

        // editor exists in the tenant but is not a member of this project.
        $this->getJson("/api/projects/{$project->id}/members/autocomplete?q=editor")
            ->assertOk()
            ->assertJsonCount(0, 'members');
    }

    public function test_an_empty_query_returns_up_to_ten_members(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $this->login('editor@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/members/autocomplete")
            ->assertOk()
            ->assertJsonCount(2, 'members')
            ->assertJsonStructure(['members' => [
                ['id', 'name', 'email'],
            ]]);
    }

    public function test_a_non_member_cannot_autocomplete(): void
    {
        $project = $this->createProjectWithLead();
        $this->login('viewer@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/members/autocomplete?q=a")
            ->assertForbidden();
    }

    public function test_an_overlong_query_is_rejected(): void
    {
        $project = $this->createProjectWithLead();
        $this->addProjectMember($project, $this->editor(), 'developer');
        $this->login('editor@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/members/autocomplete?q=".str_repeat('a', 51))
            ->assertUnprocessable();
    }
}
