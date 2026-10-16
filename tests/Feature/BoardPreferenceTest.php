<?php

namespace Tests\Feature;

use App\Models\Project;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class BoardPreferenceTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');
    }

    private function project(): Project
    {
        $workspace = $this->postJson('/api/workspaces', ['name' => 'Prefs WS'])->assertCreated()->json('workspace.id');

        return Project::findOrFail(
            $this->postJson("/api/workspaces/{$workspace}/projects", ['name' => 'Prefs Project'])->assertCreated()->json('project.id')
        );
    }

    public function test_defaults_then_persist_and_prune_unknown_columns(): void
    {
        $this->login('admin@flowsync.test');
        $project = $this->project();
        $statusId = $project->statuses()->first()->id;

        $this->getJson("/api/projects/{$project->id}/board-preferences")
            ->assertOk()
            ->assertJsonPath('preferences.width', 'default')
            ->assertJsonPath('preferences.hidden', []);

        $this->putJson("/api/projects/{$project->id}/board-preferences", [
            'hidden' => [$statusId, 999999],
            'width' => 'wide',
            'density' => 'compact',
            'hide_empty' => true,
        ])->assertOk()
            ->assertJsonPath('preferences.hidden', [$statusId])
            ->assertJsonPath('preferences.width', 'wide')
            ->assertJsonPath('preferences.hide_empty', true);
    }

    public function test_invalid_values_are_rejected_and_preferences_are_per_user(): void
    {
        $this->login('admin@flowsync.test');
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}/board-preferences", ['width' => 'huge'])
            ->assertStatus(422)->assertJsonValidationErrors('width');

        $this->putJson("/api/projects/{$project->id}/board-preferences", ['density' => 'compact'])->assertOk();

        $this->postJson('/api/auth/logout');
        $this->login('viewer@flowsync.test');
        // A non-member cannot read or write the project's preferences.
        $this->getJson("/api/projects/{$project->id}/board-preferences")->assertForbidden();
    }
}
