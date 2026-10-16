<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F3.1 — saved task filters: private by default, shared by managers, owner/manager-only edits. */
class SavedFiltersTest extends TestCase
{
    use IsolatesDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');

        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $workspace = Workspace::create(['created_by' => $admin->id, 'name' => 'SF', 'slug' => 'sf']);
        $workspace->members()->attach($admin->id, ['role' => 'owner', 'added_by' => $admin->id]);
        $this->project = Project::create(['workspace_id' => $workspace->id, 'created_by' => $admin->id, 'lead_user_id' => $admin->id, 'name' => 'SF Project', 'key' => 'SFP']);
        $this->project->members()->attach($admin->id, ['project_role_id' => ProjectRole::where('slug', 'lead')->value('id'), 'added_by' => $admin->id]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/projects/{$this->project->id}/saved-filters".$suffix;
    }

    private function addDeveloper(string $email): void
    {
        $user = User::where('email', $email)->firstOrFail();
        Workspace::first()->members()->syncWithoutDetaching([$user->id => ['role' => 'member', 'added_by' => $user->id]]);
        $this->project->members()->attach($user->id, ['project_role_id' => ProjectRole::where('slug', 'developer')->value('id'), 'added_by' => $user->id]);
    }

    private function switchTo(string $email): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');
    }

    public function test_a_filter_is_saved_cleaned_and_listed_for_its_owner(): void
    {
        $this->postJson($this->url(), ['name' => 'My bugs', 'filters' => ['status_id' => 3, 'q' => 'crash', 'rm_rf' => '/', 'priority_id' => '']])
            ->assertCreated()->assertJsonPath('filter.mine', true)->assertJsonPath('filter.is_shared', false);

        $filters = $this->getJson($this->url())->assertOk()->json('filters');
        $this->assertCount(1, $filters);
        $this->assertEqualsCanonicalizing(['status_id' => 3, 'q' => 'crash'], $filters[0]['filters']);
    }

    public function test_a_private_filter_is_invisible_to_teammates_and_a_shared_one_is_not(): void
    {
        $this->addDeveloper('editor@flowsync.test');
        $this->postJson($this->url(), ['name' => 'Private', 'filters' => ['q' => 'a']])->assertCreated();
        $this->postJson($this->url(), ['name' => 'Team view', 'filters' => ['q' => 'b'], 'is_shared' => true])->assertCreated();

        $this->switchTo('editor@flowsync.test');

        $names = collect($this->getJson($this->url())->assertOk()->json('filters'))->pluck('name')->all();
        $this->assertSame(['Team view'], $names);
    }

    public function test_only_a_project_manager_may_share_and_only_owner_or_manager_may_edit(): void
    {
        $this->addDeveloper('editor@flowsync.test');
        $shared = $this->postJson($this->url(), ['name' => 'Team view', 'filters' => ['q' => 'b'], 'is_shared' => true])->assertCreated()->json('filter.id');

        $this->switchTo('editor@flowsync.test');

        // A developer cannot share, nor edit/delete the lead's shared filter...
        $this->postJson($this->url(), ['name' => 'Mine', 'filters' => ['q' => 'x'], 'is_shared' => true])->assertForbidden();
        $this->putJson($this->url("/{$shared}"), ['name' => 'Hijack'])->assertForbidden();
        $this->deleteJson($this->url("/{$shared}"))->assertForbidden();

        // ...but can keep and change a private filter of their own.
        $own = $this->postJson($this->url(), ['name' => 'Mine', 'filters' => ['q' => 'x']])->assertCreated()->json('filter.id');
        $this->putJson($this->url("/{$own}"), ['name' => 'Renamed'])->assertOk()->assertJsonPath('filter.name', 'Renamed');
        $this->deleteJson($this->url("/{$own}"))->assertOk();
    }

    public function test_someone_elses_private_filter_looks_like_it_does_not_exist(): void
    {
        $this->addDeveloper('editor@flowsync.test');
        $private = $this->postJson($this->url(), ['name' => 'Secret', 'filters' => ['q' => 'a']])->assertCreated()->json('filter.id');

        $this->switchTo('editor@flowsync.test');

        $this->putJson($this->url("/{$private}"), ['name' => 'x'])->assertNotFound();
        $this->deleteJson($this->url("/{$private}"))->assertNotFound();
    }

    public function test_non_members_cannot_use_the_project_filters(): void
    {
        $this->switchTo('viewer@flowsync.test');

        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url(), ['name' => 'n', 'filters' => ['q' => 'a']])->assertForbidden();
    }

    public function test_a_filter_from_another_project_is_not_reachable_through_this_one(): void
    {
        $id = $this->postJson($this->url(), ['name' => 'Mine', 'filters' => ['q' => 'a']])->assertCreated()->json('filter.id');
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $other = Project::create(['workspace_id' => $this->project->workspace_id, 'created_by' => $admin->id, 'lead_user_id' => $admin->id, 'name' => 'Other', 'key' => 'OTH']);
        $other->members()->attach($admin->id, ['project_role_id' => ProjectRole::where('slug', 'lead')->value('id'), 'added_by' => $admin->id]);

        $this->putJson("/api/projects/{$other->id}/saved-filters/{$id}", ['name' => 'x'])->assertNotFound();
    }
}
