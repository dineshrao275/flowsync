<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessGitWebhook;
use App\Jobs\SyncGitInstallation;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\GitWebhookDelivery;
use App\Models\PlatformRole;
use App\Models\SystemUser;
use App\Services\Integrations\Git\Webhook\GitWebhookProcessor;
use App\Services\Security\PlatformAccess;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Git Phase 8 - the platform sees health and can pause a connection, but never a tenant's repository content. */
class GitPlatformTest extends TestCase
{
    use IsolatesDatabase;

    private GitConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        config(['git.enabled' => true]);
        $this->connection = GitConnection::create(['tenant_id' => $this->acme()->id, 'provider' => 'github', 'installation_id' => 501, 'account_login' => 'acme-org', 'account_type' => 'Organization', 'status' => 'active', 'last_synced_at' => now()]);
        GitRepository::create(['git_connection_id' => $this->connection->id, 'provider_repo_id' => 42, 'full_name' => 'acme-org/secret-project', 'name' => 'secret-project', 'owner' => 'acme-org', 'available' => true]);
        GitWebhookDelivery::create(['delivery_uuid' => 'deliv-p0000001', 'git_connection_id' => $this->connection->id, 'tenant_id' => $this->acme()->id, 'event' => 'pull_request', 'action' => 'opened', 'status' => 'processed', 'payload_excerpt' => 'pull_request opened acme-org/secret-project by octo', 'received_at' => now()->subMinutes(5)]);
        GitWebhookDelivery::create(['delivery_uuid' => 'deliv-p0000002', 'git_connection_id' => $this->connection->id, 'tenant_id' => $this->acme()->id, 'event' => 'push', 'status' => 'failed', 'error' => 'boom', 'received_at' => now()->subMinutes(2)]);
    }

    private function signIn(string $email = 'superadmin@flowsync.test'): void
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    private function persona(string $slug): string
    {
        $user = SystemUser::create(['name' => ucfirst($slug), 'email' => "{$slug}@flowsync.test", 'password' => 'password', 'is_super_admin' => true]);
        app(PlatformAccess::class)->forget();
        DB::connection('iso_system')->table('platform_user_role')->insert(['user_id' => $user->id, 'platform_role_id' => PlatformRole::where('slug', $slug)->value('id'), 'created_at' => now(), 'updated_at' => now()]);

        return $user->email;
    }

    public function test_platform_staff_see_connection_health_with_no_repository_content(): void
    {
        $this->signIn();

        $res = $this->getJson('/api/system/git/connections')->assertOk();
        $row = $res->json('connections.0');
        $this->assertSame(['acme', 'acme-org', 1, 1], [$row['tenant']['slug'], $row['account_login'], $row['repositories_count'], $row['failed_deliveries_24h']]);
        $this->assertSame(1, $res->json('health.failed_deliveries_24h'));
        $this->assertStringNotContainsString('secret-project', $res->getContent(), 'repository names are tenant content');
        $this->assertStringNotContainsString('access_token', $res->getContent());
    }

    public function test_the_delivery_log_shows_outcomes_but_not_what_was_in_them(): void
    {
        $this->signIn();

        $res = $this->getJson('/api/system/git/deliveries')->assertOk();
        $this->assertCount(2, $res->json('deliveries'));
        $this->assertStringNotContainsString('secret-project', $res->getContent());
        $this->assertStringNotContainsString('octo', $res->getContent());
        $this->assertStringNotContainsString('payload_excerpt', $res->getContent());

        $failed = $this->getJson('/api/system/git/deliveries?status=failed')->assertOk()->json('deliveries');
        $this->assertSame(['push', 'boom'], [$failed[0]['event'], $failed[0]['error']]);
        $this->getJson('/api/system/git/deliveries?status=nonsense')->assertStatus(422);
    }

    public function test_sync_disable_and_enable_are_audited_and_the_pause_survives_a_provider_unsuspend(): void
    {
        Queue::fake();
        $this->signIn();

        $this->postJson("/api/system/git/connections/{$this->connection->uuid}/sync")->assertStatus(202);
        Queue::assertPushed(SyncGitInstallation::class);

        $this->postJson("/api/system/git/connections/{$this->connection->uuid}/disable")->assertOk();
        $row = $this->connection->fresh();
        $this->assertSame('suspended', $row->status);
        $this->assertTrue($row->settings['disabled_by_platform']);

        // GitHub un-suspending the installation must not undo the platform's decision.
        $this->dbm->using($this->acme(), fn () => app(GitWebhookProcessor::class)->handle($row, 'installation', ['action' => 'unsuspend', 'installation' => ['id' => 501]]));
        $this->assertSame('suspended', $this->connection->fresh()->status);
        $this->postJson("/api/system/git/connections/{$this->connection->uuid}/sync")->assertStatus(422);

        $this->postJson("/api/system/git/connections/{$this->connection->uuid}/enable")->assertOk();
        $this->assertSame('active', $this->connection->fresh()->status);
        $this->postJson("/api/system/git/connections/{$this->connection->uuid}/enable")->assertStatus(422);

        foreach (['git.platform_sync', 'git.platform_disabled', 'git.platform_enabled'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action], 'iso_system');
        }
    }

    public function test_tenants_cannot_reach_the_platform_routes(): void
    {
        $this->signIn('admin@flowsync.test');

        $this->getJson('/api/system/git/connections')->assertForbidden();
        $this->postJson("/api/system/git/connections/{$this->connection->uuid}/disable")->assertForbidden();
        $this->assertSame('active', $this->connection->fresh()->status);
    }

    public function test_personas_are_narrowed_to_the_right_permission(): void
    {
        $support = $this->persona('support');       // tenants.view only
        $operator = $this->persona('operator');     // tenants.view + tenants.manage
        $billing = $this->persona('billing_admin'); // tenants.view

        $this->signIn($support);
        $this->getJson('/api/system/git/connections')->assertOk();
        $this->postJson("/api/system/git/connections/{$this->connection->uuid}/disable")->assertForbidden();

        $this->signIn($billing);
        $this->postJson("/api/system/git/connections/{$this->connection->uuid}/sync")->assertForbidden();

        $this->signIn($operator);
        $this->postJson("/api/system/git/connections/{$this->connection->uuid}/disable")->assertOk();
    }

    public function test_health_and_metrics_carry_a_git_section_without_breaking_the_platform_status(): void
    {
        $this->signIn();
        $git = $this->getJson('/api/platform/health')->json('services.git');

        $this->assertSame('degraded', $git['status']);
        $this->assertSame([1, 1], [$git['connections']['active'], $git['failed_deliveries_24h']]);
        $this->assertSame(1, $git['repositories_available']);

        config(['observability.metrics_token' => 'tok']);
        $metrics = $this->get('/api/metrics', ['Authorization' => 'Bearer tok'])->assertOk()->getContent();
        $this->assertStringContainsString('flowsync_git_connections{status="active"} 1', $metrics);
        $this->assertStringContainsString('flowsync_git_deliveries_failed_24h 1', $metrics);
    }

    public function test_a_stale_connection_is_flagged(): void
    {
        $this->connection->update(['last_synced_at' => now()->subDays(10)]);
        $this->signIn();

        $this->assertTrue($this->getJson('/api/system/git/connections')->json('connections.0.stale'));
        $this->assertSame(1, $this->getJson('/api/platform/health')->json('services.git.stale_connections'));
    }

    public function test_the_fifth_failure_in_an_hour_raises_one_critical_alarm(): void
    {
        Log::spy();
        GitWebhookDelivery::query()->delete();
        foreach (range(1, 5) as $i) {
            $delivery = GitWebhookDelivery::create(['delivery_uuid' => "deliv-alarm00{$i}", 'git_connection_id' => $this->connection->id, 'tenant_id' => $this->acme()->id, 'event' => 'push', 'status' => 'received', 'received_at' => now()]);
            // An unknown tenant makes the handler fail exactly like a broken database would.
            $this->connection->update(['tenant_id' => 999999]);
            (new ProcessGitWebhook($delivery->id, 'push', []))->handle(app(GitWebhookProcessor::class), app(TenantDatabaseManager::class));
        }

        Log::shouldHaveReceived('critical')->once()->with('git.webhook.repeated_failures', \Mockery::type('array'));
    }
}
