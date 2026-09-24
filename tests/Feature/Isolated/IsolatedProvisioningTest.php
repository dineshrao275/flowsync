<?php

namespace Tests\Feature\Isolated;

use App\Jobs\ProvisionTenantJob;
use App\Models\ProvisioningRun;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\SystemUser;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\TenantLifecycle;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Isolated (one-database-per-tenant) mode end-to-end, using the sqlite tenant
 * fast-path (tenancy.tenant.driver=sqlite → one .sqlite file per tenant) so the
 * provisioning/login/impersonation pipeline is fully exercisable without a PG.
 */
class IsolatedProvisioningTest extends TestCase
{
    protected string $tenantDir;

    protected string $systemDb;

    protected TenantDatabaseManager $dbm;

    protected TenantProvisioner $provisioner;

    protected TenantLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDir = sys_get_temp_dir().'/flowsync-isolated-'.Str::random(8);
        $this->systemDb = $this->tenantDir.'/system.sqlite';
        File::makeDirectory($this->tenantDir, 0775, true);

        Config::set('tenancy.driver', 'isolated');
        Config::set('tenancy.tenant.driver', 'sqlite');
        Config::set('tenancy.tenant.db_path', $this->tenantDir);
        Config::set('tenancy.system.connection', 'iso_system');
        Config::set('database.connections.iso_system', $this->sqliteConfig($this->systemDb));

        Artisan::call('migrate', [
            '--database' => 'iso_system',
            '--path' => 'database/migrations/system',
            '--force' => true,
        ]);

        $this->dbm = app(TenantDatabaseManager::class);
        $this->provisioner = app(TenantProvisioner::class);
        $this->lifecycle = app(TenantLifecycle::class);
    }

    protected function tearDown(): void
    {
        Config::set('tenancy.driver', 'isolated');
        Config::set('tenancy.system.connection', 'system');
        Config::set('tenancy.tenant.driver', 'pgsql');
        Config::set('tenancy.tenant.db_path', database_path('tenants'));
        Config::offsetUnset('database.connections.iso_system');

        DB::purge('iso_system');
        DB::purge('tenant');
        DB::setDefaultConnection('sqlite');

        if (File::isDirectory($this->tenantDir)) {
            File::deleteDirectory($this->tenantDir);
        }

        parent::tearDown();
    }

    private function sqliteConfig(string $path): array
    {
        return [
            'driver' => 'sqlite',
            'url' => '',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ];
    }

    private function pendingTenant(string $slug = 'globex', string $name = 'Globex Inc.'): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'slug' => $slug,
            'status' => Tenant::STATUS_PENDING,
            'provisioning_status' => Tenant::PROVISIONING_PENDING,
        ]);
    }

    private function ownerEmail(Tenant $tenant): string
    {
        return "owner@{$tenant->slug}.test";
    }

    #[Test]
    public function pipeline_provisions_isolated_tenant_database_owner_and_routing(): void
    {
        $tenant = $this->pendingTenant();

        $this->provisioner->provisionIsolated($tenant, $this->dbm, $this->lifecycle);

        $this->assertFileExists($this->dbm->tenantDatabasePath($tenant));

        // Phase 13: no local `tenants` row and no `tenant_id` columns — the tenant
        // DB holds the domain schema only; the central Tenant row is authoritative.
        // (switch onto the tenant connection so the schema probe hits the tenant DB)
        $this->dbm->connect($tenant);
        $this->assertFalse(Schema::hasTable('tenants'));

        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertSame(Tenant::PROVISIONING_PROVISIONED, $tenant->provisioning_status);
        $this->assertNotNull($tenant->provisioned_at);

        // Owner admin user lives in the tenant DB with the admin role.
        $owner = $this->dbm->using(
            $tenant,
            fn () => User::where('email', $this->ownerEmail($tenant))->with('roles')->first()
        );
        $this->assertNotNull($owner);
        $this->assertSame('admin', $owner->roles->first()->slug);

        // Routing index mirrors the owner for login routing.
        $route = TenantUserRouting::where('tenant_id', $tenant->id)
            ->where('email', $this->ownerEmail($tenant))
            ->first();
        $this->assertNotNull($route);
        $this->assertSame((int) $owner->id, $route->user_id);
    }

    #[Test]
    public function pipeline_is_idempotent_and_resumable(): void
    {
        $tenant = $this->pendingTenant();

        $this->provisioner->provisionIsolated($tenant, $this->dbm, $this->lifecycle);
        $this->provisioner->provisionIsolated($tenant, $this->dbm, $this->lifecycle);

        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertSame(1, TenantUserRouting::where('tenant_id', $tenant->id)->count());
        $users = $this->dbm->using($tenant, fn () => DB::table('users')->count());
        $this->assertSame(1, $users);
    }

    #[Test]
    public function failed_pipeline_marks_tenant_and_run_and_is_retryable(): void
    {
        $tenant = $this->pendingTenant();

        // Invalid db_path (a file where a directory is needed) makes DB creation fail.
        Config::set('tenancy.tenant.db_path', $this->systemDb);

        ProvisionTenantJob::dispatch($tenant);

        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_PROVISIONING_FAILED, $tenant->status);
        $this->assertSame(Tenant::PROVISIONING_FAILED, $tenant->provisioning_status);
        $this->assertNotEmpty($tenant->provisioning_error);

        $run = ProvisioningRun::where('tenant_id', $tenant->id)->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(ProvisioningRun::STATUS_FAILED, $run->status);
        $this->assertNotEmpty($run->error);

        // Repair via tenants:provision once the path is fixed.
        Config::set('tenancy.tenant.db_path', $this->tenantDir);
        $this->provisioner->provisionIsolated($tenant, $this->dbm, $this->lifecycle);

        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertSame(Tenant::PROVISIONING_PROVISIONED, $tenant->provisioning_status);
        $this->assertFileExists($this->dbm->tenantDatabasePath($tenant));
    }

    #[Test]
    public function job_provisions_trial_subscription_when_plan_requested(): void
    {
        $this->dbm->connectSystem();
        (new SubscriptionPlanSeeder)->run();

        $starter = SubscriptionPlan::where('slug', 'starter')->firstOrFail();

        $tenant = $this->pendingTenant();
        $tenant->update(['trial_ends_at' => now()->addDays(30)]);

        // Phase 14: dispatch with plan + trial → the job provisions the DB, then
        // re-stamps the subscription as trialing + writes trial_started.
        ProvisionTenantJob::dispatch($tenant, $starter->id, 30);

        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_TRIAL, $tenant->status);
        $this->assertSame(Tenant::PROVISIONING_PROVISIONED, $tenant->provisioning_status);

        $subscription = Subscription::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(Subscription::STATUS_TRIALING, $subscription->status);
        $this->assertSame($starter->id, $subscription->plan_id);
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertSame($subscription->id, $tenant->subscription_id);

        $event = SubscriptionEvent::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(Subscription::EVENT_TRIAL_STARTED, $event->type);
        $this->assertSame(30, $event->data['days']);
    }

    #[Test]
    public function provisioning_without_plan_skips_subscription(): void
    {
        $this->dbm->connectSystem();
        (new SubscriptionPlanSeeder)->run();

        $tenant = $this->pendingTenant();

        ProvisionTenantJob::dispatch($tenant);

        $tenant->refresh();
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertNull($tenant->subscription_id);
        $this->assertSame(0, Subscription::where('tenant_id', $tenant->id)->count());
    }

    #[Test]
    public function tenant_login_routes_to_the_tenant_database_and_switches_the_connection(): void
    {
        $tenant = $this->pendingTenant();
        $this->provisioner->provisionIsolated($tenant, $this->dbm, $this->lifecycle);

        $this->postJson('/api/auth/login', [
            'email' => $this->ownerEmail($tenant),
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.email', $this->ownerEmail($tenant))
            ->assertJsonPath('user.impersonating', false)
            ->assertJsonPath('user.tenant.slug', $tenant->slug);

        // Subsequent requests resolve the user on the switched tenant connection.
        $this->getJson('/api/workspaces')->assertOk()->assertJsonCount(0, 'workspaces');

        // A tenant user is not a super admin.
        $this->getJson('/api/tenants')->assertForbidden();
    }

    #[Test]
    public function ambiguous_email_requires_tenant_slug(): void
    {
        $first = $this->pendingTenant('firstco', 'First Co');
        $second = $this->pendingTenant('secondco', 'Second Co');
        $this->provisioner->provisionIsolated($first, $this->dbm, $this->lifecycle);

        // Give both tenants the same email via the routing index (tenant-unique).
        TenantUserRouting::create([
            'tenant_id' => $second->id,
            'email' => $this->ownerEmail($first),
            'user_id' => 1,
            'name' => 'Shared',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $this->ownerEmail($first),
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant');

        $this->postJson('/api/auth/login', [
            'email' => $this->ownerEmail($first),
            'password' => 'password',
            'tenant' => 'firstco',
        ])->assertOk()->assertJsonPath('user.tenant.slug', 'firstco');
    }

    #[Test]
    public function super_admin_login_and_impersonation_work_across_database_boundaries(): void
    {
        $tenant = $this->pendingTenant();
        $this->provisioner->provisionIsolated($tenant, $this->dbm, $this->lifecycle);

        $ownerId = $this->dbm->using(
            $tenant,
            fn () => User::where('email', $this->ownerEmail($tenant))->value('id')
        );

        // Super admin lives in the system DB (SystemUser pins the `users` table).
        $this->dbm->connectSystem();
        SystemUser::create([
            'name' => 'Platform Admin',
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
            'is_super_admin' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.is_super_admin', true);

        $this->getJson('/api/tenants')->assertOk()
            ->assertJsonCount(1, 'tenants');

        // Tenant users surfaced via the routing index.
        $this->getJson("/api/tenants/{$tenant->id}/users")->assertOk()
            ->assertJsonPath('users.0.id', $ownerId)
            ->assertJsonPath('users.0.email', $this->ownerEmail($tenant));

        // Impersonate the tenant owner across the database boundary.
        $this->postJson('/api/impersonate', ['user_id' => $ownerId])
            ->assertOk()
            ->assertJsonPath('user.email', $this->ownerEmail($tenant))
            ->assertJsonPath('user.impersonating', true);

        $this->getJson('/api/tenants')->assertForbidden();

        $this->postJson('/api/impersonate/stop')->assertOk()
            ->assertJsonPath('user.email', 'superadmin@flowsync.test')
            ->assertJsonPath('user.impersonating', false);

        $this->getJson('/api/tenants')->assertOk();
    }
}
