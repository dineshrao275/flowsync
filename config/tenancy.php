<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tenancy driver
    |--------------------------------------------------------------------------
    |
    | Phase 13 cutover: isolation is now the only mode. One database per tenant
    | (PostgreSQL in prod, sqlite files locally via `tenancy.tenant.driver`)
    | plus a central `system` database holding platform data, sessions, jobs,
    | cache, and the login-routing/provisioning tables.
    |
    */
    'driver' => env('TENANCY_DRIVER', 'isolated'),

    'system' => [
        'connection' => 'system',
    ],

    'tenant' => [
        'connection' => 'tenant',
        'db_prefix' => env('TENANT_DB_PREFIX', 'flowsync_tenant_'),
        // Production/dedicated deployments use PostgreSQL ('pgsql').
        // 'sqlite' is a first-class fast-path for local/dev/tests: each tenant
        // becomes a sqlite FILE (tenancy.tenant.db_path/{slug}_{id}.sqlite) so
        // the full isolated pipeline is exercisable without a Postgres server.
        'driver' => env('TENANT_DB_DRIVER', 'pgsql'),
        'db_path' => env('TENANT_DB_PATH') ?: database_path('tenants'),
        // Optional existing PostgreSQL role that owns every tenant database.
        // Leave null to give each tenant its own generated role (needs CREATEROLE);
        // set it when the server only grants the app a single login role (managed
        // PostgreSQL, most local devboxes), in which case tenant databases are
        // created with this role as owner instead of a per-tenant one.
        'pg_role' => env('TENANT_DB_PG_ROLE'),
    ],
];
