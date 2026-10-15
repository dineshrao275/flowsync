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

    /*
    |--------------------------------------------------------------------------
    | Super admin impersonation
    |--------------------------------------------------------------------------
    |
    | Hard server-side time box for "view as user". The session is ended on the
    | first request after expiry (and by `tenants:close-impersonations` for
    | sessions nobody touches again), so an abandoned tab cannot keep a super
    | admin inside a tenant.
    |
    */
    'impersonation' => [
        'ttl_minutes' => (int) env('IMPERSONATION_TTL_MINUTES', 30),
    ],

    // Tenant-granted support access windows (P8.4): ceilings a tenant admin cannot exceed.
    'support_access' => [
        'max_hours' => (int) env('SUPPORT_ACCESS_MAX_HOURS', 72),
        'max_session_minutes' => (int) env('SUPPORT_ACCESS_MAX_SESSION_MINUTES', 120),
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
        // Optional pg_trgm GIN indexes for LIKE '%q%' search (Phase 2). Off by
        // default: CREATE EXTENSION needs a role that can install extensions,
        // and sqlite tests have no trigram ops. Enable on PostgreSQL tenants
        // with ENABLE_TRGM=true then `tenants:provision`.
        'enable_trgm' => filter_var(env('ENABLE_TRGM', false), FILTER_VALIDATE_BOOLEAN),
    ],
];
