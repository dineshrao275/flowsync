<?php

/*
 * Phase 13 test infrastructure for isolated (one-database-per-tenant) mode.
 *
 * Replaces RefreshDatabase/DatabaseMigrations for every feature test. Hooks via
 * setUpTraits() (the same mechanism RefreshDatabase uses) so ported tests keep
 * their own setUp() bodies untouched:
 *
 *   1. Configures tenancy for isolated mode using the sqlite fast-path:
 *      - a file-backed `iso_system` connection holds the CENTRAL database
 *        (tenants, tenant_users, provisioning_runs, platform tables, sessions);
 *        file-backed because the shared-mode default (`:memory:`) would be
 *        destroyed when TenantDatabaseManager purges/switches the default.
 *      - one sqlite FILE per tenant under `tenancy.tenant.db_path`.
 *   2. Migrates the central/system schema from `database/migrations/system`
 *      (note: Laravel's Migrator globs `$path.'/*_*.php'` NON-recursively, so
 *      the `--path` is mandatory — a bare `migrate` no longer runs anything).
 *   3. Seeds via TenantSeeder (provisions acme + globex tenant DBs with the
 *      catalogs, demo users, owner, and the central tenant_users routing index).
 *   4. Points the default connection at the Acme tenant database, so direct
 *      model work in tests (Workspace::create, Task::find, assertDatabaseHas)
 *      resolves against that tenant's DB. HTTP requests then re-route per
 *      request via the `switch_tenant` middleware using session login.tenant_id
 *      (or impersonate.tenant_id).
 *
 * Helpers:
 *   loginAs($email)      — actingAs + session tenant id on the Acme tenant DB
 *   connectTenant($slug) — repoint the default connection to a tenant DB
 *   acme() / globex()    — central Tenant rows
 *   systemUser($email)   — platform super-admin rows from the system DB
 */

namespace Tests;

use App\Models\SystemUser;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantDatabaseManager;
use Database\Seeders\TenantSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait IsolatesDatabase
{
    protected string $isoTenantDir;

    protected string $isoSystemDb;

    protected TenantDatabaseManager $dbm;

    protected function setUpTraits(): void
    {
        $this->isolateDatabase();

        parent::setUpTraits();
    }

    protected function isolateDatabase(): void
    {
        $this->isoTenantDir = sys_get_temp_dir().'/flowsync-test-'.Str::random(8);
        $this->isoSystemDb = $this->isoTenantDir.'/system.sqlite';
        File::makeDirectory($this->isoTenantDir, 0775, true);

        Config::set('tenancy.driver', 'isolated');
        Config::set('tenancy.system.connection', 'iso_system');
        Config::set('tenancy.tenant.driver', 'sqlite');
        Config::set('tenancy.tenant.db_path', $this->isoTenantDir);
        Config::set('database.connections.iso_system', $this->sqliteConfig($this->isoSystemDb));

        Artisan::call('migrate', [
            '--database' => 'iso_system',
            '--path' => 'database/migrations/system',
            '--force' => true,
        ]);

        Artisan::call('db:seed', ['--class' => TenantSeeder::class, '--no-interaction' => true]);

        $this->dbm = app(TenantDatabaseManager::class);
        $this->connectTenant('acme');
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->isoTenantDir ?? '')) {
            File::deleteDirectory($this->isoTenantDir);
        }

        Config::set('tenancy.driver', 'isolated');
        Config::set('tenancy.system.connection', 'iso_system');
        Config::set('tenancy.tenant.driver', 'sqlite');
        Config::set('tenancy.tenant.db_path', database_path('tenants'));
        Config::offsetUnset('database.connections.iso_system');

        DB::purge('iso_system');
        DB::purge('tenant');
        DB::setDefaultConnection('sqlite');

        parent::tearDown();
    }

    protected function sqliteConfig(string $path): array
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

    protected function acme(): Tenant
    {
        return Tenant::where('slug', 'acme')->firstOrFail();
    }

    protected function globex(): Tenant
    {
        return Tenant::where('slug', 'globex')->firstOrFail();
    }

    protected function connectTenant(string $slug): void
    {
        $this->dbm->connect(Tenant::where('slug', $slug)->firstOrFail());
    }

    /**
     * Authenticate $email as the current user AND pin the session to Acme's
     * tenant, then leave the default connection on Acme's tenant DB so direct
     * model assertions after requests hit the right database. (The HTTP login
     * endpoint restores the system connection on completion, which would break
     * post-request direct queries.)
     */
    protected function loginAs(string $email): User
    {
        $this->connectTenant('acme');

        $user = User::where('email', $email)->firstOrFail();

        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);

        return $user;
    }

    protected function systemUser(string $email): SystemUser
    {
        return SystemUser::where('email', $email)->firstOrFail();
    }
}
