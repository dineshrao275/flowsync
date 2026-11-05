<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\SubscriptionPlan;
use App\Services\Integrations\Git\FakeGitProvider;
use App\Services\Integrations\Git\GitHubAppClient;
use App\Services\Integrations\Git\GitHubAppJwt;
use App\Services\Integrations\Git\GitInstallState;
use App\Services\Integrations\Git\GitProvider;
use App\Services\Integrations\Git\GitProviderException;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Git Phase 1 — a tenant admin installs the GitHub App and gets a connection nobody else can touch. */
class GitConnectionTest extends TestCase
{
    use IsolatesDatabase;

    private FakeGitProvider $github;

    protected function setUp(): void
    {
        parent::setUp();
        config(['git.enabled' => true, 'git.per_page' => 2]);
        $this->github = new FakeGitProvider;
        $this->github->installations[501] = ['id' => 501, 'account' => ['login' => 'acme-org', 'type' => 'Organization'], 'suspended_at' => null, 'permissions' => ['contents' => 'write']];
        $this->github->repositories[501] = [
            ['id' => 1, 'full_name' => 'acme-org/api', 'name' => 'api', 'owner' => ['login' => 'acme-org'], 'default_branch' => 'main', 'private' => true],
            ['id' => 2, 'full_name' => 'acme-org/web', 'name' => 'web', 'owner' => ['login' => 'acme-org'], 'default_branch' => 'main', 'private' => true],
            ['id' => 3, 'full_name' => 'acme-org/docs', 'name' => 'docs', 'owner' => ['login' => 'acme-org'], 'default_branch' => 'trunk', 'private' => false],
        ];
        $this->github->userInstallations['good-code'] = [501];
        $this->app->instance(GitProvider::class, $this->github);
    }

    private function login(string $email = 'admin@flowsync.test'): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    private function logout(): void
    {
        $this->postJson('/api/auth/logout');
    }

    private function stateFor(string $email = 'admin@flowsync.test'): string
    {
        $this->login($email);
        $url = $this->getJson('/api/integrations/git/github/install')->assertOk()->json('url');
        $this->logout();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function redeem(string $state, int $installation = 501, ?string $code = 'good-code'): \Illuminate\Testing\TestResponse
    {
        return $this->get('/api/integrations/git/github/setup?'.http_build_query(array_filter([
            'state' => $state, 'installation_id' => $installation, 'code' => $code,
        ])));
    }

    private function connect(string $email = 'admin@flowsync.test'): void
    {
        $this->redeem($this->stateFor($email))->assertRedirect('/app/integrations?git_connected=1');
    }

    public function test_an_admin_installs_the_app_and_sees_the_connection_and_its_repositories(): void
    {
        $this->connect();

        $this->login();
        $body = $this->getJson('/api/integrations/git/connections')->assertOk()->json();
        $this->assertTrue($body['enabled']);
        $this->assertCount(1, $body['connections']);
        $this->assertSame('acme-org', $body['connections'][0]['account_login']);
        $this->assertSame(3, $body['connections'][0]['repositories_count'], 'repositories are paged and all arrive');
        $this->assertSame('main', GitRepository::where('provider_repo_id', 1)->value('default_branch'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'git.connected'], config('tenancy.system.connection'));
    }

    public function test_no_secret_or_installation_id_is_ever_returned(): void
    {
        $this->connect();
        GitConnection::firstOrFail()->update(['access_token' => 'ghs_SECRETTOKEN']);

        $this->login();
        $json = $this->getJson('/api/integrations/git/connections')->assertOk()->getContent();

        $this->assertStringNotContainsString('ghs_SECRETTOKEN', $json);
        $this->assertStringNotContainsString('access_token', $json);
        $this->assertStringNotContainsString('installation_id', $json);
        $this->assertStringNotContainsString('SECRETTOKEN', (string) GitConnection::first()->toJson());
        $this->assertStringNotContainsString('SECRETTOKEN', (string) DB::connection(config('tenancy.system.connection'))->table('git_connections')->value('access_token'), 'stored encrypted');
    }

    public function test_the_state_is_single_use_expires_and_cannot_be_tampered(): void
    {
        $state = $this->stateFor();
        $this->redeem($state)->assertRedirect('/app/integrations?git_connected=1');
        $this->redeem($state)->assertRedirectContains('git_error');          // replay
        $this->assertSame(1, GitConnection::count());

        $this->redeem('garbage')->assertRedirectContains('git_error');
        $this->redeem(substr($state, 0, -4).'AAAA')->assertRedirectContains('git_error');

        $expired = Crypt::encryptString((string) json_encode(['t' => $this->acme()->id, 'u' => 1, 'n' => 'old', 'exp' => time() - 5]));
        Cache::put('git:state:old', true, 600);
        $this->redeem($expired)->assertRedirectContains('git_error');
        $this->assertSame(1, GitConnection::count());
    }

    public function test_an_installation_the_installer_cannot_see_is_refused(): void
    {
        // Forging installation_id must not work: GitHub's OAuth code is what proves ownership.
        $this->github->installations[777] = ['id' => 777, 'account' => ['login' => 'victim', 'type' => 'Organization'], 'suspended_at' => null, 'permissions' => []];

        $this->redeem($this->stateFor(), 777)->assertRedirectContains('git_error');
        $this->redeem($this->stateFor(), 501, null)->assertRedirectContains('git_error');
        $this->redeem($this->stateFor(), 501, 'unknown-code')->assertRedirectContains('git_error');

        $this->assertSame(0, GitConnection::count());
    }

    public function test_the_same_installation_cannot_be_claimed_by_a_second_tenant(): void
    {
        $this->connect();
        $this->github->userInstallations['globex-code'] = [501];

        $state = $this->stateFor('owner@globex.test');
        $this->redeem($state, 501, 'globex-code')->assertRedirectContains('already+connected');

        $this->assertSame(1, GitConnection::count());
        $this->assertSame($this->acme()->id, (int) GitConnection::first()->tenant_id);

        $this->login('owner@globex.test');
        $this->getJson('/api/integrations/git/connections')->assertOk()->assertJsonCount(0, 'connections');
    }

    public function test_a_user_who_lost_the_permission_before_the_redirect_connects_nothing(): void
    {
        $state = $this->stateFor();
        $this->dbm->using($this->acme(), fn () => \App\Models\User::where('email', 'admin@flowsync.test')->firstOrFail()->roles()->detach());

        $this->redeem($state)->assertRedirectContains('git_error');
        $this->assertSame(0, GitConnection::count());
    }

    public function test_only_git_manage_connects_and_only_git_view_reads(): void
    {
        $this->login('viewer@flowsync.test');
        $this->getJson('/api/integrations/git/connections')->assertForbidden();
        $this->logout();

        $this->login('editor@flowsync.test');
        $this->getJson('/api/integrations/git/connections')->assertOk();
        $this->getJson('/api/integrations/git/github/install')->assertForbidden();
    }

    public function test_a_plan_without_the_git_module_is_refused(): void
    {
        app(SubscriptionService::class)->assign($this->acme(), SubscriptionPlan::where('slug', 'starter')->firstOrFail());
        $this->login();

        $this->getJson('/api/integrations/git/connections')->assertForbidden()->assertHeader('X-Module-Denied', 'git');
        $this->getJson('/api/integrations/git/github/install')->assertForbidden();
    }

    public function test_the_feature_is_off_until_the_operator_enables_it(): void
    {
        config(['git.enabled' => false]);
        $this->login();

        $this->getJson('/api/integrations/git/connections')->assertOk()->assertJsonPath('enabled', false);
        $this->getJson('/api/integrations/git/github/install')->assertNotFound();
    }

    public function test_disconnecting_revokes_and_another_tenant_cannot(): void
    {
        $this->connect();
        $uuid = GitConnection::firstOrFail()->uuid;
        GitConnection::firstOrFail()->update(['access_token' => 'ghs_x']);

        $this->login('owner@globex.test');
        $this->deleteJson("/api/integrations/git/connections/{$uuid}")->assertNotFound();
        $this->logout();

        $this->login();
        $this->deleteJson("/api/integrations/git/connections/{$uuid}")->assertOk();
        $this->getJson('/api/integrations/git/connections')->assertJsonCount(0, 'connections');

        $row = GitConnection::firstOrFail();
        $this->assertSame('revoked', $row->status);
        $this->assertNotNull($row->revoked_at);
        $this->assertNull($row->access_token);
        $this->assertDatabaseHas('audit_logs', ['action' => 'git.disconnected'], config('tenancy.system.connection'));

        // Reinstalling the same account revives the same row instead of duplicating it.
        $this->redeem($this->stateFor())->assertRedirect('/app/integrations?git_connected=1');
        $this->assertSame(1, GitConnection::count());
        $this->assertSame('active', GitConnection::first()->status);
    }

    public function test_repositories_the_installation_stops_granting_are_marked_unavailable_not_deleted(): void
    {
        $this->connect();
        $this->github->repositories[501] = [$this->github->repositories[501][0]];
        app(\App\Services\Integrations\Git\GitRepositorySync::class)->sync(GitConnection::firstOrFail());

        $this->assertSame(3, GitRepository::count());
        $this->assertSame(1, GitRepository::where('available', true)->count());
    }

    public function test_the_app_jwt_is_a_valid_rs256_token(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        $public = openssl_pkey_get_details($key)['key'];

        $jwt = (new GitHubAppJwt('12345', $pem))->token(1_700_000_000);
        [$h, $c, $s] = explode('.', $jwt);

        $this->assertSame(1, openssl_verify("{$h}.{$c}", base64_decode(strtr($s, '-_', '+/')), $public, OPENSSL_ALGO_SHA256));
        $claims = json_decode(base64_decode(strtr($c, '-_', '+/')), true);
        $this->assertSame('12345', $claims['iss']);
        $this->assertSame(1_700_000_000 - 60, $claims['iat']);
        $this->assertLessThanOrEqual(600, $claims['exp'] - $claims['iat']);
        $this->assertSame('RS256', json_decode(base64_decode(strtr($h, '-_', '+/')), true)['alg']);
    }

    public function test_the_private_key_may_be_base64_and_a_missing_one_is_a_clean_error(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        config(['git.providers.github.app_id' => '9', 'git.providers.github.private_key' => 'base64:'.base64_encode($pem)]);
        $this->assertCount(3, explode('.', GitHubAppJwt::fromConfig()->token()));

        config(['git.providers.github.private_key' => null, 'git.providers.github.private_key_path' => null]);
        $this->expectException(GitProviderException::class);
        GitHubAppJwt::fromConfig();
    }

    public function test_the_installation_token_is_cached_encrypted_and_errors_hide_the_body(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        config(['git.providers.github.app_id' => '9', 'git.providers.github.private_key' => $pem, 'git.providers.github.api_url' => 'https://api.github.com']);
        Cache::flush();
        Http::fake(['api.github.com/app/installations/501/access_tokens' => Http::response(['token' => 'ghs_PLAINTEXT', 'expires_at' => now()->addHour()->toIso8601String()], 201)]);

        $client = new GitHubAppClient;
        $this->assertSame('ghs_PLAINTEXT', $client->installationToken(501));
        $this->assertSame('ghs_PLAINTEXT', $client->installationToken(501));
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('ghs_PLAINTEXT', (string) Cache::get('git:token:501'));

        Http::fake(['api.github.com/app/installations/9/access_tokens' => Http::response(['message' => 'leaky body ghs_SECRET'], 403, ['Retry-After' => '30'])]);
        try {
            $client->installationToken(9);
            $this->fail('expected a provider exception');
        } catch (GitProviderException $e) {
            $this->assertTrue($e->isRateLimited());
            $this->assertSame(30, $e->retryAfter);
            $this->assertStringNotContainsString('ghs_SECRET', $e->getMessage());
        }
    }

    public function test_state_is_bound_to_the_tenant_and_user_that_asked(): void
    {
        $state = (new GitInstallState)->issue(7, 9);
        $this->assertSame(['tenant_id' => 7, 'user_id' => 9], (new GitInstallState)->redeem($state));
    }
}
