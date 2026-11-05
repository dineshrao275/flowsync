<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GitConnection;
use App\Models\GitEventLink;
use App\Models\GitRepository;
use App\Models\GitWebhookDelivery;
use App\Models\ProjectRepository;
use App\Models\Tenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Git Phase 9 - secrets stay secret, tenants stay apart, operators can check their setup, retention is bounded. */
class GitHardeningTest extends TestCase
{
    use IsolatesDatabase;

    private const SECRETS = ['ghs_TOKEN_SECRET', 'whsec_SUPER_SECRET_VALUE_0123456789abcd', 'client-secret-XYZ'];

    private GitConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'git.enabled' => true,
            'git.providers.github.webhook_secret' => self::SECRETS[1],
            'git.providers.github.client_secret' => self::SECRETS[2],
        ]);
        $this->connection = GitConnection::create(['tenant_id' => $this->acme()->id, 'provider' => 'github', 'installation_id' => 501, 'account_login' => 'acme-org', 'account_type' => 'Organization', 'status' => 'active', 'access_token' => self::SECRETS[0], 'refresh_token' => 'refresh-SECRET']);
        GitRepository::create(['git_connection_id' => $this->connection->id, 'provider_repo_id' => 42, 'full_name' => 'acme-org/api', 'name' => 'api', 'owner' => 'acme-org', 'available' => true]);
    }

    private function signIn(string $email): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    public function test_no_response_anywhere_contains_a_stored_or_configured_secret(): void
    {
        config(['observability.metrics_token' => 'metrics-tok']);
        $bodies = [];

        $this->signIn('admin@flowsync.test');
        foreach (['/api/integrations/git/connections', '/api/integrations/git/repositories', '/api/integrations/git/identities'] as $url) {
            $bodies[$url] = $this->getJson($url)->getContent();
        }
        $this->signIn('superadmin@flowsync.test');
        foreach (['/api/system/git/connections', '/api/system/git/deliveries', '/api/platform/health'] as $url) {
            $bodies[$url] = $this->getJson($url)->getContent();
        }
        $bodies['metrics'] = $this->get('/api/metrics', ['Authorization' => 'Bearer metrics-tok'])->getContent();
        $bodies['model'] = $this->connection->toJson().json_encode($this->connection->toArray());

        foreach ($bodies as $where => $body) {
            foreach ([...self::SECRETS, 'refresh-SECRET'] as $secret) {
                $this->assertStringNotContainsString($secret, (string) $body, "{$where} leaked a secret");
            }
        }
        $raw = \DB::connection(config('tenancy.system.connection'))->table('git_connections')->first();
        $this->assertStringNotContainsString(self::SECRETS[0], (string) $raw->access_token, 'tokens are encrypted at rest');
    }

    public function test_the_webhook_never_logs_payload_text(): void
    {
        $lines = [];
        Log::listen(function ($event) use (&$lines): void {
            $lines[] = $event->message.' '.json_encode($event->context);
        });
        $body = (string) json_encode(['action' => 'opened', 'installation' => ['id' => 501], 'repository' => ['id' => 42, 'full_name' => 'acme-org/api'],
            'sender' => ['login' => 'octo', 'type' => 'User'], 'pull_request' => ['number' => 1, 'title' => 'TOPSECRET-TITLE', 'body' => 'TOPSECRET-BODY', 'head' => ['ref' => 'x']]]);

        $this->call('POST', '/api/integrations/git/github/webhook', [], [], [], [
            'HTTP_X_GITHUB_EVENT' => 'pull_request', 'HTTP_X_GITHUB_DELIVERY' => 'deliv-logcheck1',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRETS[1]), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(202);

        $this->assertStringNotContainsString('TOPSECRET', implode("\n", $lines));
        $stored = GitWebhookDelivery::firstOrFail();
        $this->assertStringNotContainsString('TOPSECRET', json_encode($stored->toArray()));
    }

    public function test_another_tenant_cannot_see_or_touch_any_of_this_tenants_git_resources(): void
    {
        $this->signIn('admin@flowsync.test');
        $ws = $this->postJson('/api/workspaces', ['name' => 'Iso WS'])->assertCreated()->json('workspace.id');
        $pid = (int) $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Iso', 'key' => 'ISO'])->assertCreated()->json('project.id');
        $repo = GitRepository::firstOrFail();
        $link = $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => $repo->uuid])->assertCreated()->json('repository.id');

        $this->signIn('owner@globex.test');
        $this->getJson('/api/integrations/git/connections')->assertOk()->assertJsonCount(0, 'connections');
        $this->getJson('/api/integrations/git/repositories')->assertOk()->assertJsonCount(0, 'repositories');
        $this->getJson("/api/integrations/git/repositories/{$repo->uuid}/branches")->assertNotFound();
        $this->postJson("/api/integrations/git/connections/{$this->connection->uuid}/sync")->assertNotFound();
        $this->deleteJson("/api/integrations/git/connections/{$this->connection->uuid}")->assertNotFound();
        $this->getJson("/api/projects/$pid/repositories")->assertStatus(404);
        $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => $repo->uuid])->assertStatus(404);
        $this->deleteJson("/api/projects/$pid/repositories/$link")->assertStatus(404);
        $this->getJson("/api/projects/$pid/development/summary")->assertStatus(404);

        $this->assertSame('active', $this->connection->fresh()->status);
    }

    public function test_the_config_check_reports_problems_without_printing_secrets(): void
    {
        config(['git.providers.github.app_id' => null, 'git.providers.github.slug' => null, 'git.providers.github.private_key' => null, 'git.providers.github.private_key_path' => null,
            'git.providers.github.webhook_secret' => 'short', 'git.providers.github.client_id' => null, 'git.providers.github.client_secret' => self::SECRETS[2], 'git.providers.github.api_url' => 'http://insecure']);

        $this->artisan('git:check-config')
            ->expectsOutputToContain('[FAIL] GITHUB_APP_ID and GITHUB_APP_SLUG must both be set.')
            ->expectsOutputToContain('[WARN] GITHUB_APP_WEBHOOK_SECRET is shorter than 32 characters')
            ->expectsOutputToContain('[FAIL] GITHUB_APP_API_URL must be an https:// URL.')
            ->doesntExpectOutputToContain(self::SECRETS[2])
            ->assertFailed();
    }

    public function test_the_config_check_passes_for_a_sound_setup_and_flags_a_loose_key_file(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        $path = sys_get_temp_dir().'/gh-key-'.uniqid().'.pem';
        file_put_contents($path, $pem);
        config(['git.providers.github.app_id' => '9', 'git.providers.github.slug' => 'flowsync', 'git.providers.github.private_key' => null, 'git.providers.github.private_key_path' => $path,
            'git.providers.github.client_id' => 'Iv1.x', 'git.providers.github.api_url' => 'https://api.github.com', 'app.url' => 'https://app.example.com']);

        chmod($path, 0644);
        $this->artisan('git:check-config')->expectsOutputToContain('[WARN] Private key file mode is 0644')->assertSuccessful();

        chmod($path, 0600);
        $this->artisan('git:check-config')->expectsOutputToContain('[OK] Private key file is private to its owner.')->expectsOutputToContain('[OK] Private key parses and can sign.')->assertSuccessful();
        File::delete($path);
    }

    public function test_legacy_status_lists_only_tenants_still_on_the_old_webhook(): void
    {
        $this->acme()->update(['settings' => ['github' => ['enabled' => true, 'secret' => 'x']]]);
        $this->globex()->update(['settings' => ['github' => ['enabled' => false]]]);

        $this->artisan('git:legacy-status')->expectsOutputToContain('acme')->doesntExpectOutputToContain('globex')->assertSuccessful();

        $this->acme()->update(['settings' => null]);
        $this->artisan('git:legacy-status')->expectsOutputToContain('No tenant uses the legacy GitHub webhook.')->assertSuccessful();
    }

    public function test_retention_prunes_old_deliveries_and_old_event_ledger_rows_but_never_task_data(): void
    {
        $this->signIn('admin@flowsync.test');
        $ws = $this->postJson('/api/workspaces', ['name' => 'Ret WS'])->assertCreated()->json('workspace.id');
        $pid = (int) $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Ret', 'key' => 'RET'])->assertCreated()->json('project.id');
        $taskId = (int) $this->postJson("/api/projects/$pid/tasks", ['title' => 'x'])->assertCreated()->json('task.id');
        $this->connectTenant('acme');
        $link = ProjectRepository::create(['project_id' => $pid, 'git_connection_uuid' => $this->connection->uuid, 'provider' => 'github', 'provider_repo_id' => 42, 'full_name' => 'acme-org/api']);
        $commit = \App\Models\TaskCommit::create(['task_id' => $taskId, 'project_repository_id' => $link->id, 'sha' => 'abc', 'message' => 'm']);
        foreach ([['old', 40], ['new', 1]] as [$name, $age]) {
            GitEventLink::create(['delivery_uuid' => "d-{$name}", 'project_repository_id' => $link->id, 'task_id' => $taskId, 'kind' => 'commit', 'provider_object_id' => $name])->forceFill(['created_at' => now()->subDays($age)])->save();
        }
        GitWebhookDelivery::create(['delivery_uuid' => 'deliv-old00001', 'event' => 'push', 'status' => 'processed', 'received_at' => now()->subDays(40)]);

        $this->artisan('git:prune-deliveries')->assertSuccessful();

        $this->connectTenant('acme');
        $this->assertSame(['d-new'], GitEventLink::pluck('delivery_uuid')->all());
        $this->assertSame(0, GitWebhookDelivery::count());
        $this->assertNotNull(\App\Models\TaskCommit::find($commit->id), 'task-side history is never pruned');
    }

    public function test_a_backup_contains_the_connection_and_the_linked_repository(): void
    {
        if (config('tenancy.tenant.driver') !== 'sqlite' && config('database.connections.'.config('tenancy.system.connection').'.driver') !== 'sqlite') {
            $this->markTestSkipped('The sqlite snapshot is inspected directly.');
        }
        $this->signIn('admin@flowsync.test');
        $ws = $this->postJson('/api/workspaces', ['name' => 'Bak WS'])->assertCreated()->json('workspace.id');
        $pid = (int) $this->postJson("/api/workspaces/$ws/projects", ['name' => 'Bak', 'key' => 'BAK'])->assertCreated()->json('project.id');
        $this->postJson("/api/projects/$pid/repositories", ['repository_uuid' => GitRepository::firstOrFail()->uuid])->assertCreated();

        $dir = sys_get_temp_dir().'/git_backup_'.uniqid();
        $this->artisan('tenants:backup', ['--all' => true, '--path' => $dir, '--verify' => true])->assertSuccessful();
        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

        $system = new \PDO('sqlite:'.$manifest['system_database']['path']);
        $this->assertSame(1, (int) $system->query('select count(*) from git_connections')->fetchColumn());
        $acme = collect($manifest['tenants'])->first(fn ($t) => str_contains($t['path'], 'acme'));
        $tenant = new \PDO('sqlite:'.$acme['path']);
        $this->assertSame(1, (int) $tenant->query('select count(*) from project_repositories')->fetchColumn());

        $this->artisan('tenants:backup', ['--restore-drill' => $dir.'/manifest.json'])->assertSuccessful();
        File::deleteDirectory($dir);
    }
}
