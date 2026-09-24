<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * Resolves and switches the active database connection in isolated mode (Phase 13:
 * one database per tenant). The default connection is pointed at the tenant's
 * database per request; central/system models pin to the 'system' connection via
 * the CentralConnection trait, and sessions/cache/jobs live in the system DB.
 */
class TenantDatabaseManager
{
    public const SYSTEM_CONNECTION = 'system';

    public const TENANT_CONNECTION = 'tenant';

    public function __construct(private readonly TenantContext $context) {}

    public function driver(): string
    {
        return (string) config('tenancy.driver', 'isolated');
    }

    /**
     * Connection name central/system models resolve to (CentralConnection): always
     * the dedicated 'system' connection in isolated mode.
     */
    public function centralConnectionName(): string
    {
        return (string) config('tenancy.system.connection', self::SYSTEM_CONNECTION);
    }

    /**
     * Driver used for tenant databases: 'pgsql' (production/dedicated) or 'sqlite'
     * (one file per tenant — local/dev/test fast-path).
     */
    public function tenantDriver(): string
    {
        return (string) config('tenancy.tenant.driver', 'pgsql');
    }

    /**
     * Build the tenant DSN from the tenant record (encrypted creds), falling back
     * to environment defaults and the deterministic database name. PostgreSQL only.
     *
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    public function dsn(Tenant $tenant): array
    {
        $prefix = config('tenancy.tenant.db_prefix', 'flowsync_tenant_');

        return [
            'host' => $tenant->db_host ?: (string) env('DB_HOST', '127.0.0.1'),
            'port' => $tenant->db_port ?: (string) env('DB_PORT', '5432'),
            'database' => $tenant->db_name ?: $prefix.$tenant->id,
            'username' => $tenant->db_user ?: (string) env('DB_USERNAME', 'flowsync'),
            'password' => $tenant->db_password ?: (string) env('DB_PASSWORD', ''),
        ];
    }

    /**
     * Filesystem path for a tenant's sqlite database (sqlite driver only).
     */
    public function tenantDatabasePath(Tenant $tenant): string
    {
        $dir = (string) config('tenancy.tenant.db_path', database_path('tenants'));

        return rtrim($dir, '/').'/'.$tenant->slug.'_'.$tenant->id.'.sqlite';
    }

    /**
     * Create the tenant's database (idempotent). SQLite: touch the file and create
     * its parent directory. PostgreSQL: CREATE DATABASE + a dedicated role that owns
     * only that database; credentials are generated and stored encrypted on Tenant.
     */
    public function createDatabase(Tenant $tenant): void
    {
        if ($this->tenantDriver() === 'sqlite') {
            $path = $this->tenantDatabasePath($tenant);

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }

            if (! file_exists($path)) {
                file_put_contents($path, '');
            }

            $tenant->update([
                'db_name' => basename($path),
                'provisioning_status' => Tenant::PROVISIONING_DB_CREATED,
            ]);

            return;
        }

        $this->createPostgresDatabase($tenant);
    }

    private function createPostgresDatabase(Tenant $tenant): void
    {
        $prefix = config('tenancy.tenant.db_prefix', 'flowsync_tenant_');
        $dbName = $tenant->db_name ?: $prefix.$tenant->id;
        $role = $tenant->db_user ?: $dbName;
        $password = $tenant->db_password ?: Str::random(32);

        $pdo = $this->postgresAdminConnection();

        // The tenant role must own its database to CREATE in the 'public' schema
        // (PostgreSQL 15+ revoked default CREATE/usage for non-owner roles).
        if (! $this->postgresRoleExists($role)) {
            $pdo->exec(sprintf('CREATE ROLE "%s" LOGIN PASSWORD %s', $role, $pdo->quote($password)));
        } elseif (! $tenant->db_password) {
            // The role predates the tenant row's stored password — align it so the
            // connection credentials match the DB we generated.
            $pdo->exec(sprintf('ALTER ROLE "%s" WITH LOGIN PASSWORD %s', $role, $pdo->quote($password)));
        }

        if ($this->postgresDatabaseExists($dbName)) {
            $pdo->exec(sprintf('ALTER DATABASE "%s" OWNER TO "%s"', $dbName, $role));
        } else {
            $pdo->exec(sprintf('CREATE DATABASE "%s" OWNER "%s"', $dbName, $role));
        }

        $tenant->update([
            'db_name' => $dbName,
            'db_host' => $this->postgresHost(),
            'db_port' => $this->postgresPort(),
            'db_user' => $role,
            'db_password' => $password,
            'provisioning_status' => Tenant::PROVISIONING_DB_CREATED,
        ]);
    }

    /**
     * Run the tenant migration set against the tenant's database.
     */
    public function migrateTenant(Tenant $tenant): void
    {
        $connection = config('tenancy.tenant.connection', self::TENANT_CONNECTION);

        $this->using($tenant, function () use ($connection, $tenant): void {
            Artisan::call('migrate', [
                '--database' => $connection,
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);
            $tenant->update(['provisioning_status' => Tenant::PROVISIONING_MIGRATED]);
        });
    }

    /**
     * Make the given tenant's database the active connection and run $callback
     * inside that context. The prior connection and tenant context are restored
     * afterwards.
     */
    public function using(Tenant $tenant, callable $callback): mixed
    {
        $previousConnection = DB::getDefaultConnection();
        $previousTenantId = $this->context->currentId();

        try {
            $this->connect($tenant);

            return $callback();
        } finally {
            $this->restore($previousConnection, $previousTenantId);
        }
    }

    public function connect(Tenant $tenant): void
    {
        $previousTenantId = $this->context->currentId();
        $this->context->setTenantId($tenant->id);

        $connection = config('tenancy.tenant.connection', self::TENANT_CONNECTION);
        Config::set("database.connections.{$connection}", $this->buildConnectionConfig($tenant));

        // The 'tenant' connection is cached by the connection manager under the
        // same name across ALL tenants, so switching from one tenant to another
        // must always purge it — otherwise the memoized PDO keeps pointing at the
        // PREVIOUS tenant's database. (The tenant connection is never an
        // in-memory sqlite, so purging is always safe here — the purge-protection
        // only matters for the central/system connection in connectSystem().)
        if ($previousTenantId !== $tenant->id) {
            DB::purge($connection);
        }
        DB::setDefaultConnection($connection);
    }

    public function connectSystem(): void
    {
        $this->context->setTenantId(null);

        $connection = $this->centralConnectionName();
        $this->switchDefault($connection);
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $dsn
     */
    private function postgresConnectionConfig(array $dsn): array
    {
        return [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => $dsn['host'],
            'port' => $dsn['port'],
            'database' => $dsn['database'],
            'username' => $dsn['username'],
            'password' => $dsn['password'],
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ];
    }

    private function sqliteConnectionConfig(string $path): array
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

    private function buildConnectionConfig(Tenant $tenant): array
    {
        if ($this->tenantDriver() === 'sqlite') {
            return $this->sqliteConnectionConfig($this->tenantDatabasePath($tenant));
        }

        return $this->postgresConnectionConfig($this->dsn($tenant));
    }

    private function restore(string $connection, ?int $tenantId): void
    {
        $this->context->setTenantId($tenantId);

        // If we're restoring the default to the tenant connection name, rebuild
        // the tenant config for the tenant that was active BEFORE the using()
        // block — otherwise the 'tenant' connection would keep pointing at the
        // tenant that the block just connected to.
        if ($connection === config('tenancy.tenant.connection', self::TENANT_CONNECTION) && $tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                $this->connect($tenant);

                return;
            }
        }

        $this->switchDefault($connection);
    }

    /**
     * Repoint the default connection without clobbering a connection that is
     * already current (important for in-memory/test system databases).
     */
    private function switchDefault(string $connection): void
    {
        if (DB::getDefaultConnection() !== $connection) {
            DB::purge($connection);
        }

        DB::setDefaultConnection($connection);
    }

    // --- PostgreSQL maintenance helpers (superuser) ---------------------------------

    private function postgresHost(): string
    {
        return (string) config('database.connections.system.host', env('DB_HOST', '127.0.0.1'));
    }

    private function postgresPort(): string
    {
        return (string) config('database.connections.system.port', env('DB_PORT', '5432'));
    }

    private function postgresAdminConnection(): PDO
    {
        $user = (string) config('database.connections.system.username', env('DB_USERNAME', 'flowsync'));
        $pass = (string) config('database.connections.system.password', env('DB_PASSWORD', ''));

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $this->postgresHost(), $this->postgresPort());

        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function postgresDatabaseExists(string $name): bool
    {
        try {
            $exists = $this->postgresAdminConnection()
                ->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $exists->execute([$name]);

            return $exists->fetchColumn() !== false;
        } catch (Throwable) {
            return true; // cannot verify — fail safe and let migration surface the error
        }
    }

    private function postgresRoleExists(string $name): bool
    {
        try {
            $exists = $this->postgresAdminConnection()
                ->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
            $exists->execute([$name]);

            return $exists->fetchColumn() !== false;
        } catch (Throwable) {
            return true;
        }
    }
}
