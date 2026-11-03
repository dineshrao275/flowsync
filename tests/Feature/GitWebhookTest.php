<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessGitWebhook;
use App\Jobs\SyncGitInstallation;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\GitWebhookDelivery;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Git Phase 2 - signed, idempotent, tenant-routed webhook intake. */
class GitWebhookTest extends TestCase
{
    use IsolatesDatabase;

    private const SECRET = 'whsec_test_secret';

    private GitConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        config(['git.enabled' => true, 'git.providers.github.webhook_secret' => self::SECRET]);
        $this->connection = $this->connectionFor($this->acme(), 501, 'acme-org');
    }

    private function connectionFor(Tenant $tenant, int $installation, string $login): GitConnection
    {
        $connection = GitConnection::create([
            'tenant_id' => $tenant->id, 'provider' => 'github', 'installation_id' => $installation,
            'account_login' => $login, 'account_type' => 'Organization', 'status' => 'active',
        ]);
        foreach ([[10, 'api'], [11, 'web']] as [$id, $name]) {
            GitRepository::create([
                'git_connection_id' => $connection->id, 'provider_repo_id' => $id + $installation, 'full_name' => "{$login}/{$name}",
                'name' => $name, 'owner' => $login, 'default_branch' => 'main', 'available' => true,
            ]);
        }

        return $connection;
    }

    private function deliver(string $event, array $payload, ?string $uuid = null, ?string $secret = self::SECRET, ?string $rawBody = null): TestResponse
    {
        $body = $rawBody ?? (string) json_encode($payload);
        $headers = [
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => $uuid ?? 'deliv-'.bin2hex(random_bytes(6)),
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
            'CONTENT_TYPE' => 'application/json',
        ];

        return $this->call('POST', '/api/integrations/git/github/webhook', [], [], [], $headers, $body);
    }

    public function test_a_valid_signature_is_accepted_recorded_and_queued(): void
    {
        Queue::fake();

        $this->deliver('pull_request', ['action' => 'opened', 'installation' => ['id' => 501], 'repository' => ['full_name' => 'acme-org/api'], 'sender' => ['login' => 'octo'], 'pull_request' => ['title' => 'SECRET TITLE', 'body' => 'SECRET BODY']], 'deliv-0001abcd')
            ->assertStatus(202)->assertJsonPath('status', 'accepted');

        Queue::assertPushed(ProcessGitWebhook::class);
        $row = GitWebhookDelivery::firstOrFail();
        $this->assertSame('received', $row->status);
        $this->assertSame($this->connection->id, (int) $row->git_connection_id);
        $this->assertSame($this->acme()->id, (int) $row->tenant_id);
        $this->assertSame('pull_request opened acme-org/api by octo', $row->payload_excerpt);
        $this->assertStringNotContainsString('SECRET', (string) json_encode($row->toArray()), 'no payload text is stored');
    }

    public function test_a_bad_missing_or_wrong_secret_signature_is_a_bare_404_and_leaves_no_trace(): void
    {
        Queue::fake();

        $this->deliver('push', ['installation' => ['id' => 501]], null, 'wrong-secret')->assertNotFound();
        $this->call('POST', '/api/integrations/git/github/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => 'push', 'HTTP_X_GITHUB_DELIVERY' => 'deliv-nosig001'], '{}')->assertNotFound();

        $this->assertSame(0, GitWebhookDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_the_endpoint_does_not_exist_while_the_feature_is_off_or_unconfigured(): void
    {
        config(['git.enabled' => false]);
        $this->deliver('ping', [])->assertNotFound();

        config(['git.enabled' => true, 'git.providers.github.webhook_secret' => '']);
        $this->deliver('ping', [], null, '')->assertNotFound();
    }

    public function test_a_redelivery_is_a_no_op(): void
    {
        Queue::fake();
        $payload = ['action' => 'opened', 'installation' => ['id' => 501]];

        $this->deliver('pull_request', $payload, 'deliv-same0001')->assertStatus(202)->assertJsonPath('status', 'accepted');
        $this->deliver('pull_request', $payload, 'deliv-same0001')->assertStatus(202)->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, GitWebhookDelivery::count());
        Queue::assertPushed(ProcessGitWebhook::class, 1);
    }

    public function test_a_failed_delivery_can_be_redelivered_but_a_settled_one_cannot(): void
    {
        $payload = ['action' => 'suspend', 'installation' => ['id' => 501]];
        $this->deliver('installation', $payload, 'deliv-redeliver1')->assertJsonPath('status', 'accepted');
        $this->connection->update(['status' => 'active']);
        GitWebhookDelivery::where('delivery_uuid', 'deliv-redeliver1')->update(['status' => 'failed', 'error' => 'boom']);

        $this->deliver('installation', $payload, 'deliv-redeliver1')->assertJsonPath('status', 'accepted');
        $this->assertSame('suspended', $this->connection->fresh()->status, 'the retry ran');
        $this->assertSame(['processed', null], [GitWebhookDelivery::firstOrFail()->status, GitWebhookDelivery::firstOrFail()->error]);

        $this->deliver('installation', $payload, 'deliv-redeliver1')->assertJsonPath('status', 'duplicate');
        $this->assertSame(1, GitWebhookDelivery::count());
    }

    public function test_ping_answers_pong_and_is_recorded(): void
    {
        $this->deliver('ping', ['zen' => 'Keep it logically awesome.'])->assertOk()->assertJsonPath('message', 'pong');

        $this->assertSame('processed', GitWebhookDelivery::firstOrFail()->status);
    }

    public function test_unknown_revoked_and_suspended_installations_are_ignored_not_leaked(): void
    {
        Queue::fake();

        $this->deliver('push', ['installation' => ['id' => 999]])->assertStatus(202)->assertJsonPath('status', 'ignored');
        $this->deliver('push', ['no' => 'installation'])->assertStatus(202)->assertJsonPath('status', 'ignored');

        $this->connection->update(['status' => 'suspended']);
        $this->deliver('push', ['installation' => ['id' => 501]])->assertStatus(202)->assertJsonPath('status', 'ignored');

        $this->connection->update(['status' => 'revoked']);
        $this->deliver('installation', ['action' => 'unsuspend', 'installation' => ['id' => 501]])->assertStatus(202)->assertJsonPath('status', 'ignored');

        Queue::assertNothingPushed();
    }

    public function test_a_suspended_connection_still_hears_the_event_that_resumes_it(): void
    {
        $this->connection->update(['status' => 'suspended']);

        $this->deliver('installation', ['action' => 'unsuspend', 'installation' => ['id' => 501]])->assertStatus(202)->assertJsonPath('status', 'accepted');

        $this->assertSame('active', $this->connection->fresh()->status);
    }

    public function test_malformed_json_is_recorded_as_ignored(): void
    {
        $this->deliver('push', [], null, self::SECRET, '{not json')->assertStatus(202)->assertJsonPath('status', 'ignored');

        $this->assertSame('Malformed payload.', GitWebhookDelivery::firstOrFail()->error);
    }

    public function test_missing_delivery_header_is_a_400_after_the_signature_passes(): void
    {
        $body = '{}';
        $this->call('POST', '/api/integrations/git/github/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body)->assertStatus(400);
    }

    public function test_an_inactive_tenant_gets_nothing_processed_but_can_still_be_revoked(): void
    {
        Queue::fake();
        $this->acme()->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->deliver('pull_request', ['action' => 'opened', 'installation' => ['id' => 501]])->assertJsonPath('status', 'ignored');
        $this->deliver('installation', ['action' => 'deleted', 'installation' => ['id' => 501]])->assertJsonPath('status', 'accepted');
    }

    public function test_oversized_payloads_are_recorded_but_never_queued(): void
    {
        Queue::fake();
        $big = ['action' => 'opened', 'installation' => ['id' => 501], 'pad' => str_repeat('x', 1_100_000)];

        $this->deliver('push', $big)->assertStatus(202)->assertJsonPath('status', 'ignored');

        Queue::assertNothingPushed();
    }

    public function test_deleting_the_installation_revokes_the_connection_and_wipes_tokens(): void
    {
        $this->connection->update(['access_token' => 'ghs_x']);

        $this->deliver('installation', ['action' => 'deleted', 'installation' => ['id' => 501]])->assertStatus(202);

        $row = $this->connection->fresh();
        $this->assertSame('revoked', $row->status);
        $this->assertNull($row->access_token);
        $this->assertSame('processed', GitWebhookDelivery::firstOrFail()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'git.revoked_by_provider'], config('tenancy.system.connection'));
    }

    public function test_suspend_and_unsuspend_toggle_the_connection(): void
    {
        $this->app->instance(\App\Services\Integrations\Git\GitProvider::class, new \App\Services\Integrations\Git\FakeGitProvider);
        $this->deliver('installation', ['action' => 'suspend', 'installation' => ['id' => 501]]);
        $this->assertSame('suspended', $this->connection->fresh()->status);

        $this->deliver('installation', ['action' => 'unsuspend', 'installation' => ['id' => 501]]);
        $this->assertSame('active', $this->connection->fresh()->status);
    }

    public function test_repositories_added_trigger_a_sync_and_removed_ones_become_unavailable(): void
    {
        $fake = new \App\Services\Integrations\Git\FakeGitProvider;
        $fake->repositories[501] = [['id' => 512, 'full_name' => 'acme-org/web', 'name' => 'web', 'owner' => ['login' => 'acme-org']], ['id' => 99, 'full_name' => 'acme-org/new', 'name' => 'new', 'owner' => ['login' => 'acme-org']]];
        $this->app->instance(\App\Services\Integrations\Git\GitProvider::class, $fake);
        $this->deliver('installation_repositories', ['action' => 'added', 'installation' => ['id' => 501], 'repositories_added' => [['id' => 99]]]);
        $this->deliver('installation_repositories', ['action' => 'removed', 'installation' => ['id' => 501], 'repositories_removed' => [['id' => 511]]]);

        $this->assertFalse((bool) GitRepository::where('provider_repo_id', 511)->value('available'));
        $this->assertTrue((bool) GitRepository::where('provider_repo_id', 512)->value('available'));
        $this->assertSame(1, GitRepository::where('provider_repo_id', 99)->count(), 'the sync brought the new repository in');
    }

    public function test_repository_rename_archive_and_delete_update_the_catalog(): void
    {
        $this->deliver('repository', ['action' => 'renamed', 'installation' => ['id' => 501], 'repository' => ['id' => 511, 'full_name' => 'acme-org/api-v2', 'name' => 'api-v2', 'owner' => ['login' => 'acme-org'], 'archived' => false, 'private' => true]]);
        $this->assertSame('acme-org/api-v2', GitRepository::where('provider_repo_id', 511)->value('full_name'));

        $this->deliver('repository', ['action' => 'archived', 'installation' => ['id' => 501], 'repository' => ['id' => 511, 'archived' => true]]);
        $this->assertTrue((bool) GitRepository::where('provider_repo_id', 511)->value('archived'));

        $this->deliver('repository', ['action' => 'deleted', 'installation' => ['id' => 501], 'repository' => ['id' => 511]]);
        $this->assertFalse((bool) GitRepository::where('provider_repo_id', 511)->value('available'));
    }

    public function test_an_event_for_another_tenants_installation_never_touches_this_tenants_data(): void
    {
        $other = $this->connectionFor($this->globex(), 777, 'globex-org');

        $this->deliver('repository', ['action' => 'deleted', 'installation' => ['id' => 777], 'repository' => ['id' => 511]]);
        $this->assertTrue((bool) GitRepository::where('git_connection_id', $this->connection->id)->where('provider_repo_id', 511)->value('available'), 'acme row untouched');

        $this->deliver('installation', ['action' => 'deleted', 'installation' => ['id' => 777]]);
        $this->assertSame('active', $this->connection->fresh()->status);
        $this->assertSame('revoked', $other->fresh()->status);
        $this->assertSame($this->globex()->id, (int) GitWebhookDelivery::where('event', 'installation')->value('tenant_id'));
    }

    public function test_a_failing_handler_is_recorded_as_failed_and_never_thrown(): void
    {
        $delivery = GitWebhookDelivery::create(['delivery_uuid' => 'deliv-fail0001', 'git_connection_id' => 424242, 'event' => 'push', 'status' => 'received', 'received_at' => now()]);

        (new ProcessGitWebhook($delivery->id, 'push', []))->handle(app(\App\Services\Integrations\Git\Webhook\GitWebhookProcessor::class), app(\App\Support\TenantDatabaseManager::class));

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->error);
    }

    public function test_an_unhandled_event_is_ignored_and_a_processed_delivery_is_not_run_twice(): void
    {
        $this->deliver('star', ['installation' => ['id' => 501]])->assertStatus(202);
        $this->assertSame('ignored', GitWebhookDelivery::firstOrFail()->status);

        $delivery = GitWebhookDelivery::firstOrFail();
        (new ProcessGitWebhook($delivery->id, 'installation', ['action' => 'deleted', 'installation' => ['id' => 501]]))
            ->handle(app(\App\Services\Integrations\Git\Webhook\GitWebhookProcessor::class), app(\App\Support\TenantDatabaseManager::class));
        $this->assertSame('active', $this->connection->fresh()->status, 'a settled delivery is never re-run');
    }

    public function test_prune_removes_only_old_deliveries(): void
    {
        GitWebhookDelivery::create(['delivery_uuid' => 'deliv-old00001', 'event' => 'push', 'status' => 'processed', 'received_at' => now()->subDays(40)]);
        GitWebhookDelivery::create(['delivery_uuid' => 'deliv-new00001', 'event' => 'push', 'status' => 'processed', 'received_at' => now()->subDay()]);

        $this->artisan('git:prune-deliveries')->assertSuccessful();

        $this->assertSame(['deliv-new00001'], GitWebhookDelivery::pluck('delivery_uuid')->all());
    }

    public function test_the_tenant_sees_webhook_health_and_can_queue_a_sync(): void
    {
        Queue::fake();
        GitWebhookDelivery::create(['delivery_uuid' => 'deliv-health01', 'git_connection_id' => $this->connection->id, 'event' => 'push', 'status' => 'failed', 'received_at' => now()->subHour()]);
        GitWebhookDelivery::create(['delivery_uuid' => 'deliv-health02', 'git_connection_id' => $this->connection->id, 'event' => 'push', 'status' => 'processed', 'received_at' => now()->subDays(3)]);
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();

        $row = $this->getJson('/api/integrations/git/connections')->assertOk()->json('connections.0');
        $this->assertSame(1, $row['failed_deliveries_24h']);
        $this->assertNotNull($row['last_delivery_at']);

        $this->postJson("/api/integrations/git/connections/{$this->connection->uuid}/sync")->assertStatus(202);
        Queue::assertPushed(SyncGitInstallation::class);

        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'owner@globex.test', 'password' => 'password'])->assertOk();
        $this->postJson("/api/integrations/git/connections/{$this->connection->uuid}/sync")->assertNotFound();
    }

    public function test_the_webhook_stays_open_during_maintenance(): void
    {
        \App\Models\PlatformSetting::set('maintenance_mode', '1');
        \Illuminate\Support\Facades\Cache::forget('platform.maintenance');

        $this->deliver('ping', [])->assertOk();
    }

    public function test_csrf_is_not_demanded_of_the_webhook(): void
    {
        $this->assertStringContainsString("'api/integrations/git/github/webhook'", (string) file_get_contents(base_path('bootstrap/app.php')));
    }
}
