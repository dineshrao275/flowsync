<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\ProjectRepository;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\Integrations\Git\FakeGitProvider;
use App\Services\Integrations\Git\GitProvider;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Git Phase 4 - project managers link repositories their own organization was granted, and nothing else. */
class GitRepositoryLinkTest extends TestCase
{
    use IsolatesDatabase;

    private GitConnection $connection;

    private GitRepository $repo;

    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['git.enabled' => true]);
        Cache::flush();
        $this->app->instance(GitProvider::class, new FakeGitProvider);
        $this->connection = $this->connect($this->acme(), 501, 'acme-org');
        $this->repo = $this->repoFor($this->connection, 1, 'api');
    }

    private function connect(Tenant $tenant, int $installation, string $login): GitConnection
    {
        return GitConnection::create(['tenant_id' => $tenant->id, 'provider' => 'github', 'installation_id' => $installation, 'account_login' => $login, 'account_type' => 'Organization', 'status' => 'active']);
    }

    private function repoFor(GitConnection $c, int $id, string $name, bool $available = true): GitRepository
    {
        return GitRepository::create(['git_connection_id' => $c->id, 'provider_repo_id' => $id, 'full_name' => "{$c->account_login}/{$name}", 'name' => $name, 'owner' => $c->account_login, 'default_branch' => 'main', 'available' => $available, 'html_url' => "https://github.com/{$c->account_login}/{$name}"]);
    }

    private function login(string $email = 'admin@flowsync.test'): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    private function project(string $key = 'GLP'): int
    {
        $ws = $this->postJson('/api/workspaces', ['name' => 'Git WS '.$key])->assertCreated()->json('workspace.id');

        return $this->projectId = (int) $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Git Project '.$key, 'key' => $key])->assertCreated()->json('project.id');
    }

    public function test_a_manager_links_updates_and_unlinks_a_repository(): void
    {
        $this->login();
        $pid = $this->project();

        $link = $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => $this->repo->uuid])->assertCreated()->json('repository');
        $this->assertSame('acme-org/api', $link['full_name']);
        $this->assertFalse($link['smart_commits_enabled'], 'smart commits are off by default');
        $this->assertFalse($link['allow_cross_project']);
        $this->assertTrue($link['sync_enabled']);

        $this->getJson("/api/projects/$pid/repositories")->assertOk()->assertJsonCount(1, 'repositories');

        $this->putJson("/api/projects/$pid/repositories/{$link['id']}", ['smart_commits_enabled' => true, 'sync_enabled' => false])->assertOk()
            ->assertJsonPath('repository.smart_commits_enabled', true)->assertJsonPath('repository.sync_enabled', false);

        $this->deleteJson("/api/projects/$pid/repositories/{$link['id']}")->assertOk();
        $this->assertSame(0, ProjectRepository::count());
    }

    public function test_duplicates_and_foreign_or_unavailable_repositories_are_refused(): void
    {
        $this->login();
        $pid = $this->project();
        $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => $this->repo->uuid])->assertCreated();
        $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => $this->repo->uuid])->assertStatus(422)->assertJsonValidationErrors('repository_uuid');

        $foreign = $this->repoFor($this->connect($this->globex(), 777, 'globex-org'), 9, 'secret');
        $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => $foreign->uuid])->assertStatus(422)->assertJsonValidationErrors('repository_uuid');

        $gone = $this->repoFor($this->connection, 2, 'gone', false);
        $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => $gone->uuid])->assertStatus(422);
        $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => 'not-a-uuid'])->assertStatus(422);
        $this->assertSame(1, ProjectRepository::count());
    }

    public function test_the_same_repository_may_serve_two_projects(): void
    {
        $this->login();
        $a = $this->project('GLA');
        $b = $this->project('GLB');
        $this->postJson("/api/projects/$a/repositories", ['repository_uuid' => $this->repo->uuid])->assertCreated();
        $this->postJson("/api/projects/$b/repositories", ['repository_uuid' => $this->repo->uuid])->assertCreated();

        $this->assertSame(2, ProjectRepository::count());
    }

    public function test_writes_need_git_manage_and_project_settings_reads_need_git_view(): void
    {
        $this->login();
        $pid = $this->project();
        $link = $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => $this->repo->uuid])->assertCreated()->json('repository.id');
        $this->postJson('/api/auth/logout');

        $this->login('viewer@flowsync.test');
        $this->getJson("/api/projects/$pid/repositories")->assertForbidden();
        $this->postJson('/api/auth/logout');

        // Editor: git.view, but not git.manage.
        $this->login('editor@flowsync.test');
        $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => $this->repo->uuid])->assertForbidden();
        $this->putJson("/api/projects/$pid/repositories/$link", ['sync_enabled' => false])->assertForbidden();
        $this->deleteJson("/api/projects/$pid/repositories/$link")->assertForbidden();
        $this->assertTrue(ProjectRepository::findOrFail($link)->sync_enabled);
    }

    public function test_a_link_belongs_to_its_project_only(): void
    {
        $this->login();
        $a = $this->project('GLA');
        $b = $this->project('GLB');
        $link = $this->postJson("/api/projects/$a/repositories", ['repository_uuid' => $this->repo->uuid])->assertCreated()->json('repository.id');

        $this->putJson("/api/projects/$b/repositories/$link", ['sync_enabled' => false])->assertNotFound();
        $this->deleteJson("/api/projects/$b/repositories/$link")->assertNotFound();
        $this->getJson("/api/projects/$b/repositories/$link/branches")->assertNotFound();
    }

    public function test_unlinking_removes_derived_task_data_only_when_asked(): void
    {
        $this->login();
        $pid = $this->project();
        $taskId = (int) $this->postJson("/api/projects/$pid/tasks", ['title' => 'Linked work'])->assertCreated()->json('task.id');
        $link = $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => $this->repo->uuid])->assertCreated()->json('repository.id');
        $other = ProjectRepository::create(['project_id' => $pid, 'git_connection_uuid' => $this->connection->uuid, 'provider' => 'github', 'provider_repo_id' => 999, 'full_name' => 'acme-org/other']);
        $this->connectTenant('acme');
        $commit = fn (int $linkId, string $sha) => \App\Models\TaskCommit::create(['task_id' => $taskId, 'project_repository_id' => $linkId, 'sha' => $sha, 'message' => 'm']);
        $commit($link, 'aaa');
        $commit($other->id, 'bbb');

        $this->deleteJson("/api/projects/$pid/repositories/$link")->assertOk();
        $this->assertSame(2, \App\Models\TaskCommit::count(), 'default keeps the history');
        $this->assertNull(\App\Models\TaskCommit::where('sha', 'aaa')->value('project_repository_id'), 'but it no longer points at a repository link');

        $again = $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => $this->repo->uuid])->assertCreated()->json('repository.id');
        $this->connectTenant('acme');
        $commit($again, 'ccc');
        $this->deleteJson("/api/projects/$pid/repositories/$again", ['remove_linked_data' => true])->assertOk();
        $this->assertSame(['aaa', 'bbb'], \App\Models\TaskCommit::orderBy('sha')->pluck('sha')->all(), 'only that link\'s rows go');
    }

    public function test_project_scoped_browsing_reads_the_linked_repository(): void
    {
        $fake = new FakeGitProvider;
        $fake->browse['acme-org/api:branches'] = [['name' => 'main', 'sha' => 'abc', 'protected' => false]];
        $this->app->instance(GitProvider::class, $fake);
        $this->login();
        $pid = $this->project();
        $link = $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => $this->repo->uuid])->assertCreated()->json('repository.id');

        $this->getJson("/api/projects/$pid/repositories/$link/branches")->assertOk()->assertJsonPath('branches.0.name', 'main');
        $this->getJson("/api/projects/$pid/repositories/$link/commits")->assertOk();
        $this->getJson("/api/projects/$pid/repositories/$link/pull-requests?state=open")->assertOk();
    }

    public function test_disconnecting_pauses_links_but_keeps_them(): void
    {
        $this->login();
        $pid = $this->project();
        $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => $this->repo->uuid])->assertCreated();

        $this->deleteJson("/api/integrations/git/connections/{$this->connection->uuid}")->assertOk();

        $this->connectTenant('acme');
        $this->assertSame(1, ProjectRepository::count());
        $this->assertFalse(ProjectRepository::firstOrFail()->sync_enabled);
    }

    public function test_the_module_gate_and_product_gate_apply(): void
    {
        $this->login();
        $pid = $this->project();
        app(SubscriptionService::class)->assign($this->acme(), SubscriptionPlan::where('slug', 'starter')->firstOrFail());

        $this->getJson("/api/projects/$pid/repositories")->assertForbidden()->assertHeader('X-Module-Denied', 'git');
    }

    public function test_the_migration_outcome_is_the_documented_shape(): void
    {
        $this->connectTenant('acme');
        $this->assertTrue(Schema::hasColumns('project_repositories', ['project_id', 'git_connection_uuid', 'provider', 'provider_repo_id', 'full_name', 'sync_enabled', 'smart_commits_enabled', 'allow_cross_project', 'linked_by', 'linked_at']));
        $indexes = collect(Schema::getIndexes('project_repositories'));
        $this->assertTrue($indexes->contains(fn ($i) => $i['unique'] && $i['columns'] === ['project_id', 'provider', 'provider_repo_id']));
        $this->assertTrue($indexes->contains(fn ($i) => $i['columns'] === ['git_connection_uuid', 'provider_repo_id']));
    }
}
