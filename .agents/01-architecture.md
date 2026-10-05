# 01 — Architecture (read before touching backend code)

## Tenancy model: one database per tenant + central system DB

- **System DB** (`system` connection; `iso_system` in tests): platform data — `tenants`,
  `tenant_users` routing, `provisioning_runs`, `impersonation_logs`, sessions/jobs/cache,
  platform RBAC, `audit_logs`, subscriptions/plans/events, `platform_settings`,
  `website_pages`, super admins (`users` table, model `SystemUser`).
- **Tenant DB** (`tenant` connection): the full domain schema **without `tenant_id`
  columns**. The physical database IS the isolation boundary.
- Drivers: PostgreSQL everywhere in prod/dev; sqlite **files** as the local/test
  fast-path (`config/tenancy.php`: `tenant.driver`, `TENANT_DB_DRIVER`,
  `TENANT_DB_PATH`, `TENANT_DB_PG_ROLE` shared-role fallback for hosts without `CREATEROLE`).

## Key singletons / support classes (`app/Support/`)

- `TenantDatabaseManager` — owns both connection names. `connect()` / `connectSystem()`
  switch the default in-app connection; `using($tenant, fn)` scopes a closure to a
  tenant DB (safe to nest across tenants — it purges the `tenant` connection on change;
  **never add a purge-skip for the `tenant` name**; purge-protection in `switchDefault()`
  guards only the central connection). `centralConnectionName()`.
- `TenantContext` — guard-intent singleton (`tenantId`, `impersonating`), set by
  `SetTenantContext` from session keys. Null = platform context (super admin,
  provisioning, seeders).
- `TenantProvisioner::provisionIsolated()` — idempotent provision pipeline
  (connect → createDatabase → migrateTenant → seed → owner admin → syncRouting →
  trial/active). Pins `TenantContext` to null internally. `tenants:provision` repairs.
- `CentralConnection` trait (`app/Models/Concerns/CentralConnection.php`) —
  `getConnectionName()` → central connection. Applied to ALL central models.

## Middleware chain (order is load-bearing)

Domain routes: `switch_tenant` → `auth` → `tenant` → `tenant_context` →
`onboarding_complete` → `permission:*` / `ensure_module:*`.

- `SwitchTenant` (`switch_tenant`) resolves the tenant connection from session
  `login.tenant_id` / `impersonate.tenant_id` (**central ids**) and calls `using()`.
- `SetTenantContext` (`tenant`) sets the singleton from session keys.
- `EnsureTenantContext` (`tenant_context`) 403s unless a tenant context exists —
  blocks non-impersonating super admins from domain routes.
- `EnsurePermission` (`permission:slug`) + `Gate::define('permission')` SA bypass
  (unless impersonating — then scoped to target tenant).
- `EnsureModule` (`ensure_module:key`) 403s when the tenant's effective plan lacks
  the module; bypasses when `TenantContext::currentId()` is null.
- `EnsureOnboardingComplete` (`onboarding_complete`) 403s incomplete self-registered tenants.
- `EnsureSuperAdmin` (`super_admin`) requires `is_super_admin`.

Registered in `bootstrap/app.php:41-78` — aliases AND the `$middleware->priority([...])`
list placing all DB-switching middleware **before `SubstituteBindings`**. Laravel's
`SortedMiddleware` reorders by that list; if a new DB-switching middleware is added to
a route group, it MUST be added to the priority list or route-model binding runs on
the previous request's connection ("no such table" 500s on the central DB).

Route groups in `routes/web.php` (verified 2026-10-05):
- `:113` — `['switch_tenant','auth','tenant']`: me/logout, theme, notifications,
  inbox, my-subscription/usage, `search/global`, super-admin platform, impersonation.
- `:233` — domain group `['switch_tenant','auth','tenant','tenant_context',
  'onboarding_complete']`: users, workspaces, projects, tasks, collab, time,
  search/tasks, dashboard, reports.
- `:363` — HRMS group `['tenant_context','onboarding_complete']` — **KNOWN GAP:
  missing `switch_tenant`/`auth`/`tenant`** (see `10-security.md` Critical A).
  Must be fixed to match the domain group.
- Signed downloads sit OUTSIDE auth (`signed` middleware, central `tenant` id inside
  the signature, lookup inside `using()`).

## Session / routing essentials

- Session driver `database` on the central DB (verified `.env`: `SESSION_DRIVER=database`).
  `SESSION_CONNECTION` should be pinned to `system` in prod.
- `tenant_users` (`TenantUserRouting`): normalized email + `tenant_id` + `user_id`
  (tenant-local id), unique `(tenant_id, email)`. Login resolves tenant here FIRST;
  a tenant `users` row without a routing row is un-loggable-in (see `05-auth-rbac.md`).
- `Auth::attempt` must strip the `tenant` slug key (`Arr::except`) or it builds
  `where tenant = ?` against `users` and fails.
- `exists:table,id` validation resolves against the DEFAULT connection — on tenant
  requests for central tables (e.g. `subscription_plans`) use `Model::find()` + 422.
- Queued jobs carry their tenant: `Queue::createPayloadUsing()` stamps `tenant_id`;
  `JobProcessing` listener reconnects BEFORE unserialization (`SerializesModels`
  re-queries at that point). Without this every queued broadcast fails with
  "relation tasks does not exist". See `09-realtime-jobs-mail.md`.
