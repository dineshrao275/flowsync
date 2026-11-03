<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GitConnection;
use App\Models\GitIdentity;
use App\Models\GitRepository;
use App\Models\ProjectRepository;
use App\Models\TaskBranch;
use App\Models\User;
use App\Services\Integrations\Git\BranchNamer;
use App\Services\Integrations\Git\FakeGitProvider;
use App\Services\Integrations\Git\GitProvider;
use App\Services\Integrations\Git\GitProviderException;
use Illuminate\Support\Facades\Cache;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Git Phase 7 - branch creation from a task, and the GitHub login <-> user map. */
class GitBranchIdentityTest extends TestCase
{
    use IsolatesDatabase;

    private FakeGitProvider $github;

    private int $pid;

    private int $linkId;

    /** @var array{id: int, key: string} */
    private array $task;

    protected function setUp(): void
    {
        parent::setUp();
        config(['git.enabled' => true]);
        Cache::flush();
        $this->github = new FakeGitProvider;
        $this->app->instance(GitProvider::class, $this->github);
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $ws = $this->postJson('/api/workspaces', ['name' => 'Branch WS'])->assertCreated()->json('workspace.id');
        $this->pid = (int) $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Branch', 'key' => 'BRN'])->assertCreated()->json('project.id');
        $this->task = $this->postJson("/api/projects/{$this->pid}/tasks", ['title' => 'Add OAuth login!'])->assertCreated()->json('task');

        $connection = GitConnection::create(['tenant_id' => $this->acme()->id, 'provider' => 'github', 'installation_id' => 501, 'account_login' => 'acme-org', 'account_type' => 'Organization', 'status' => 'active']);
        GitRepository::create(['git_connection_id' => $connection->id, 'provider_repo_id' => 42, 'full_name' => 'acme-org/api', 'name' => 'api', 'owner' => 'acme-org', 'default_branch' => 'trunk', 'available' => true]);
        $this->connectTenant('acme');
        $this->linkId = ProjectRepository::create(['project_id' => $this->pid, 'git_connection_uuid' => $connection->uuid, 'provider' => 'github', 'provider_repo_id' => 42, 'full_name' => 'acme-org/api', 'html_url' => 'https://github.com/acme-org/api', 'default_branch' => 'trunk'])->id;
    }

    private function create(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/projects/{$this->pid}/repositories/{$this->linkId}/branches", $over + ['task_id' => $this->task['id']]);
    }

    public function test_the_branch_name_is_key_plus_a_safe_slug(): void
    {
        $task = new \App\Models\Task(['key' => 'ABC-7', 'title' => 'Fix "login" — now! ünïcode / slashes']);
        $this->assertSame('feature/ABC-7-fix-login-now-unicode-slashes', BranchNamer::for($task));

        $long = new \App\Models\Task(['key' => 'ABC-7', 'title' => str_repeat('word ', 40)]);
        $this->assertLessThanOrEqual(8 + 5 + 60, strlen(BranchNamer::for($long)));
        $this->assertSame('feature/ABC-7', BranchNamer::for(new \App\Models\Task(['key' => 'ABC-7', 'title' => '!!!'])));
    }

    public function test_a_branch_is_created_from_the_default_branch_and_recorded(): void
    {
        $this->create()->assertCreated()->assertJsonPath('branch.name', "feature/{$this->task['key']}-add-oauth-login");

        $this->assertSame(["acme-org/api:feature/{$this->task['key']}-add-oauth-login@trunk"], $this->github->created);
        $this->connectTenant('acme');
        $row = TaskBranch::firstOrFail();
        $this->assertSame([$this->task['id'], 'refs/heads/feature/'.$this->task['key'].'-add-oauth-login'], [$row->task_id, $row->provider_ref]);
        $this->assertNotNull($row->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'git.branch_created'], config('tenancy.system.connection'));

        $this->create()->assertStatus(422)->assertJsonValidationErrors('form');
        $this->assertCount(1, $this->github->created);
    }

    public function test_provider_refusals_become_actionable_messages(): void
    {
        $this->github->failWith = new GitProviderException('x', 422);
        $this->create()->assertStatus(422)->assertJsonPath('errors.form.0', fn ($m) => str_contains($m, 'already exists in the repository'));

        $this->github->failWith = new GitProviderException('x', 409);
        $this->create()->assertStatus(422)->assertJsonPath('errors.form.0', fn ($m) => str_contains($m, 'repository is empty'));

        $this->github->failWith = new GitProviderException('x', 403);
        $this->create()->assertStatus(422)->assertJsonPath('errors.form.0', fn ($m) => str_contains($m, 'Contents'));

        $this->github->failWith = new GitProviderException('x', 403, 30);
        $this->create()->assertStatus(429)->assertHeader('Retry-After', '30');

        $this->github->failWith = new GitProviderException('x', 500);
        $this->create()->assertStatus(502);
        $this->connectTenant('acme');
        $this->assertSame(0, TaskBranch::count());
    }

    public function test_only_a_manager_with_git_manage_may_create_and_only_from_this_project(): void
    {
        ProjectRepository::findOrFail($this->linkId)->update(['sync_enabled' => false]);
        $this->create()->assertStatus(422);
        ProjectRepository::findOrFail($this->linkId)->update(['sync_enabled' => true]);

        $ws = $this->postJson('/api/workspaces', ['name' => 'Other WS'])->assertCreated()->json('workspace.id');
        $other = (int) $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Other', 'key' => 'OTH'])->assertCreated()->json('project.id');
        $foreign = $this->postJson("/api/projects/$other/tasks", ['title' => 'Elsewhere'])->assertCreated()->json('task.id');
        $this->create(['task_id' => $foreign])->assertNotFound();
        $this->postJson("/api/projects/$other/repositories/{$this->linkId}/branches", ['task_id' => $foreign])->assertNotFound();

        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();
        $this->create()->assertForbidden();
        $this->assertSame([], $this->github->created);
    }

    public function test_identities_are_mapped_unmapped_and_unique(): void
    {
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();

        $this->postJson('/api/integrations/git/identities', ['user_id' => $editor->id, 'login' => '@Editor-GH'])->assertCreated()->assertJsonPath('identity.login', 'editor-gh');
        $this->postJson('/api/integrations/git/identities', ['user_id' => $admin->id, 'login' => 'editor-gh'])->assertStatus(422)->assertJsonValidationErrors('login');
        $this->postJson('/api/integrations/git/identities', ['user_id' => $editor->id, 'login' => 'new-name'])->assertCreated();
        $this->postJson('/api/integrations/git/identities', ['user_id' => $editor->id, 'login' => 'not a login'])->assertStatus(422);
        $this->postJson('/api/integrations/git/identities', ['user_id' => 99999, 'login' => 'ghost'])->assertStatus(422);

        $list = $this->getJson('/api/integrations/git/identities')->assertOk()->json('identities');
        $this->assertSame(['new-name'], array_column($list, 'login'), 'one login per user: remapping replaces');
        $this->assertSame('editor@flowsync.test', $list[0]['user']['email']);

        $this->deleteJson('/api/integrations/git/identities/'.$list[0]['id'])->assertOk();
        $this->connectTenant('acme');
        $this->assertSame(0, GitIdentity::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'git.identity_mapped'], config('tenancy.system.connection'));
    }

    public function test_identity_management_needs_git_manage(): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();

        $this->getJson('/api/integrations/git/identities')->assertForbidden();
        $this->postJson('/api/integrations/git/identities', ['user_id' => 1, 'login' => 'x'])->assertForbidden();
    }
}
