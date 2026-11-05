<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncGitInstallation;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\Tenant;
use App\Services\Integrations\Git\FakeGitProvider;
use App\Services\Integrations\Git\GitHubAppClient;
use App\Services\Integrations\Git\GitProvider;
use App\Services\Integrations\Git\GitProviderException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Git Phase 3 - the repository catalog, the picker and cached read-only browsing. */
class GitRepositorySyncTest extends TestCase
{
    use IsolatesDatabase;

    private FakeGitProvider $github;

    private GitConnection $acme;

    protected function setUp(): void
    {
        parent::setUp();
        config(['git.enabled' => true, 'git.per_page' => 2]);
        Cache::flush();
        $this->github = new FakeGitProvider;
        $this->app->instance(GitProvider::class, $this->github);
        $this->acme = $this->connection($this->acme(), 501, 'acme-org');
    }

    private function connection(Tenant $tenant, int $installation, string $login, string $status = 'active'): GitConnection
    {
        return GitConnection::create([
            'tenant_id' => $tenant->id, 'provider' => 'github', 'installation_id' => $installation,
            'account_login' => $login, 'account_type' => 'Organization', 'status' => $status,
        ]);
    }

    private function repo(GitConnection $connection, int $id, string $name, bool $available = true): GitRepository
    {
        return GitRepository::create([
            'git_connection_id' => $connection->id, 'provider_repo_id' => $id, 'full_name' => "{$connection->account_login}/{$name}",
            'name' => $name, 'owner' => $connection->account_login, 'default_branch' => 'main', 'available' => $available,
        ]);
    }

    private function login(string $email = 'admin@flowsync.test'): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    public function test_a_sync_is_idempotent_pages_through_everything_and_never_deletes(): void
    {
        $this->github->repositories[501] = array_map(fn ($i) => ['id' => $i, 'full_name' => "acme-org/r{$i}", 'name' => "r{$i}", 'owner' => ['login' => 'acme-org']], [1, 2, 3, 4, 5]);

        (new SyncGitInstallation($this->acme->id))->handle(app(\App\Services\Integrations\Git\GitRepositorySync::class));
        (new SyncGitInstallation($this->acme->id))->handle(app(\App\Services\Integrations\Git\GitRepositorySync::class));
        $this->assertSame(5, GitRepository::count(), 'two runs, five rows');
        $this->assertNotNull($this->acme->fresh()->last_synced_at);

        $this->github->repositories[501] = [$this->github->repositories[501][0]];
        (new SyncGitInstallation($this->acme->id))->handle(app(\App\Services\Integrations\Git\GitRepositorySync::class));
        $this->assertSame(5, GitRepository::count());
        $this->assertSame(1, GitRepository::where('available', true)->count());

        // A repository that comes back is revived rather than duplicated.
        $this->github->repositories[501] = array_map(fn ($i) => ['id' => $i, 'full_name' => "acme-org/r{$i}", 'name' => "r{$i}", 'owner' => ['login' => 'acme-org']], [1, 2, 3, 4, 5]);
        (new SyncGitInstallation($this->acme->id))->handle(app(\App\Services\Integrations\Git\GitRepositorySync::class));
        $this->assertSame(5, GitRepository::where('available', true)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'git.synced'], config('tenancy.system.connection'));
    }

    public function test_a_failed_sync_is_shown_to_the_admin_and_cleared_by_the_next_good_one(): void
    {
        $sync = app(\App\Services\Integrations\Git\GitRepositorySync::class);
        $this->github->failWith = new GitProviderException('boom', 429, 60);
        (new SyncGitInstallation($this->acme->id))->handle($sync);

        $this->login();
        $this->assertStringContainsString('rate limiting', (string) $this->getJson('/api/integrations/git/connections')->json('connections.0.sync_error'));

        $this->github->failWith = null;
        (new SyncGitInstallation($this->acme->id))->handle($sync);
        $this->assertNull($this->getJson('/api/integrations/git/connections')->json('connections.0.sync_error'));
    }

    public function test_the_sync_command_queues_active_connections_only_and_respects_scope(): void
    {
        Queue::fake();
        $globex = $this->connection($this->globex(), 777, 'globex-org');
        $this->connection($this->acme(), 888, 'old-org', 'revoked');

        $this->artisan('git:sync-installations')->assertFailed();
        $this->artisan('git:sync-installations --tenant='.$this->globex()->id)->assertSuccessful();
        Queue::assertPushed(SyncGitInstallation::class, fn ($job) => $job->connectionId === $globex->id);
        Queue::assertPushed(SyncGitInstallation::class, 1);

        $this->artisan('git:sync-installations --all')->assertSuccessful();
        Queue::assertPushed(SyncGitInstallation::class, 3);

        config(['git.enabled' => false]);
        Queue::fake();
        $this->artisan('git:sync-installations --all')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_the_picker_searches_a_tenants_own_available_repositories_only(): void
    {
        $this->repo($this->acme, 1, 'billing-api');
        $this->repo($this->acme, 2, 'web');
        $this->repo($this->acme, 3, 'gone', false);
        $this->repo($this->acme, 4, '100%_done');
        $other = $this->connection($this->globex(), 777, 'globex-org');
        $this->repo($other, 9, 'billing-secret');
        $this->login();

        $all = collect($this->getJson('/api/integrations/git/repositories')->assertOk()->json('repositories'))->pluck('full_name');
        $this->assertEqualsCanonicalizing(['acme-org/100%_done', 'acme-org/billing-api', 'acme-org/web'], $all->all());

        $found = $this->getJson('/api/integrations/git/repositories?q=billing')->assertOk()->json('repositories');
        $this->assertSame(['acme-org/billing-api'], array_column($found, 'full_name'));

        $this->assertSame(['acme-org/100%_done'], array_column($this->getJson('/api/integrations/git/repositories?q=%25')->json('repositories'), 'full_name'), 'a % is matched literally');
        $this->getJson('/api/integrations/git/repositories?connection='.$other->uuid)->assertOk()->assertJsonCount(0, 'repositories');
    }

    public function test_the_picker_and_browsing_need_git_view(): void
    {
        $repo = $this->repo($this->acme, 1, 'api');
        $this->login('viewer@flowsync.test');
        $this->getJson('/api/integrations/git/repositories')->assertForbidden();
        $this->getJson("/api/integrations/git/repositories/{$repo->uuid}/branches")->assertForbidden();
        $this->postJson('/api/auth/logout');

        $this->login('editor@flowsync.test');
        $this->getJson('/api/integrations/git/repositories')->assertOk();
    }

    public function test_browsing_is_cached_and_tenant_isolated(): void
    {
        $repo = $this->repo($this->acme, 1, 'api');
        $this->github->browse['acme-org/api:branches'] = [['name' => 'main', 'sha' => 'abc', 'protected' => true]];
        $this->github->browse['acme-org/api:commits'] = [['sha' => 'abc', 'message' => 'Fix', 'author_login' => 'octo', 'author_name' => 'Octo', 'html_url' => 'https://github.com/acme-org/api/commit/abc', 'committed_at' => null]];
        $this->github->browse['acme-org/api:pulls'] = [['number' => 7, 'title' => 'T', 'state' => 'open']];
        $this->login();

        $this->getJson("/api/integrations/git/repositories/{$repo->uuid}/branches")->assertOk()->assertJsonPath('branches.0.name', 'main');
        $this->getJson("/api/integrations/git/repositories/{$repo->uuid}/branches")->assertOk();
        $this->assertSame(1, count(array_filter($this->github->calls, fn ($c) => str_ends_with($c, ':branches'))), 'second read served from cache');

        $this->getJson("/api/integrations/git/repositories/{$repo->uuid}/commits?branch=main")->assertOk()->assertJsonPath('commits.0.sha', 'abc');
        $this->getJson("/api/integrations/git/repositories/{$repo->uuid}/pull-requests?state=open")->assertOk()->assertJsonPath('pull_requests.0.number', 7);
        $this->getJson("/api/integrations/git/repositories/{$repo->uuid}/pull-requests?state=bogus")->assertStatus(422);

        $foreign = $this->repo($this->connection($this->globex(), 777, 'globex-org'), 9, 'secret');
        $this->getJson("/api/integrations/git/repositories/{$foreign->uuid}/branches")->assertNotFound();

        $repo->update(['available' => false]);
        Cache::flush();
        $this->getJson("/api/integrations/git/repositories/{$repo->uuid}/branches")->assertNotFound();
    }

    public function test_a_rate_limited_provider_is_a_429_with_retry_after_and_no_body_leak(): void
    {
        $repo = $this->repo($this->acme, 1, 'api');
        $this->github->failWith = new GitProviderException('leaky ghs_SECRET', 403, 42);
        $this->login();

        $res = $this->getJson("/api/integrations/git/repositories/{$repo->uuid}/branches")->assertStatus(429)->assertHeader('Retry-After', '42');
        $this->assertStringNotContainsString('ghs_SECRET', $res->getContent());

        $this->github->failWith = new GitProviderException('down', 500);
        $this->getJson("/api/integrations/git/repositories/{$repo->uuid}/commits")->assertStatus(502);
    }

    public function test_the_client_maps_github_payloads_and_rolls_up_check_runs(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        config(['git.providers.github.app_id' => '9', 'git.providers.github.private_key' => $pem, 'git.providers.github.api_url' => 'https://api.github.com']);
        Http::fake([
            'api.github.com/app/installations/501/access_tokens' => Http::response(['token' => 't', 'expires_at' => now()->addHour()->toIso8601String()], 201),
            'api.github.com/repos/acme-org/api/branches*' => Http::response([['name' => 'main', 'commit' => ['sha' => 'abc'], 'protected' => true]]),
            'api.github.com/repos/acme-org/api/commits/abc/check-runs*' => Http::response(['check_runs' => [['status' => 'completed', 'conclusion' => 'success'], ['status' => 'in_progress']]]),
            'api.github.com/repos/acme-org/api/commits/def/check-runs*' => Http::response(['check_runs' => [['status' => 'completed', 'conclusion' => 'failure'], ['status' => 'completed', 'conclusion' => 'success']]]),
            'api.github.com/repos/acme-org/api/commits/none/check-runs*' => Http::response(['check_runs' => []]),
            'api.github.com/repos/acme-org/api/pulls*' => Http::response([
                ['number' => 1, 'title' => 'a', 'state' => 'open', 'draft' => true, 'user' => ['login' => 'o'], 'head' => ['ref' => 'f'], 'base' => ['ref' => 'main']],
                ['number' => 2, 'title' => 'b', 'state' => 'closed', 'merged_at' => '2026-01-01T00:00:00Z', 'head' => ['ref' => 'g'], 'base' => ['ref' => 'main']],
                ['number' => 3, 'title' => 'c', 'state' => 'closed', 'head' => ['ref' => 'h'], 'base' => ['ref' => 'main']],
            ]),
        ]);
        $client = new GitHubAppClient;

        $this->assertSame([['name' => 'main', 'sha' => 'abc', 'protected' => true]], $client->branches(501, 'acme-org/api'));
        $this->assertSame(['draft', 'merged', 'closed'], array_column($client->pullRequests(501, 'acme-org/api'), 'state'));
        $this->assertSame('pending', $client->checks(501, 'acme-org/api', 'abc'));
        $this->assertSame('failure', $client->checks(501, 'acme-org/api', 'def'));
        $this->assertSame('none', $client->checks(501, 'acme-org/api', 'none'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/branches') && str_contains($r->url(), 'per_page=2'));

        $this->expectException(GitProviderException::class);
        $client->branches(501, '../../etc/passwd');
    }
}
