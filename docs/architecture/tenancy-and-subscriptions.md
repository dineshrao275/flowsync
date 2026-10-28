# Multi-tenant architecture, subscriptions, module gates, onboarding

> Moved verbatim from `AGENTS.md`. Sections below keep their original headings.

## Multi-tenant SaaS architecture (Phase 11 → 13 shipped; P14+ in progress)
- **Read `docs/multi-tenancy-architecture.md` before any architectural work.** Direction: PostgreSQL
  **one database per tenant** + central system DB. **Phase 13 shipped: the app is isolated-only** —
  `TENANCY_DRIVER=shared` no longer exists (default `isolated`); tenant DBs are PostgreSQL in prod and
  sqlite **files** (fast-path) for local/dev/tests, plus a central `system` DB.
- **Phase 11 shipped (foundations):** `docker-compose.yml` (postgres:17, system DB `flowsync_system`);
  `config/database.php` gains `system` + `tenant` pg connections; `config/tenancy.php`
  (driver/connection/db-prefix). `app/Support/TenantDatabaseManager.php` (singleton, bound in
  `AppServiceProvider`) — `dsn()`, `connect()`, `connectSystem()`, `using()`, `centralConnectionName()`,
  `tenantDriver()`, `tenantDatabasePath()`, `createDatabase()`, `migrateTenant()`. Tenant model
  expanded by migration `000013` (status/provisioning_status/provisioning_error/provisioned_at,
  `db_name|host|port|user|password` encrypted via `encrypted` casts, `subscription_id` index only —
  FK lands with the subscriptions table in P14, billing/contact/trial, `limits_override|features_override
  |onboarding_meta|settings` JSON, soft deletes) with model defaults `status=active` +
  `provisioning_status=provisioned`. Migration `000014`: `platform_roles`/`platform_permissions` +
  pivots (`platform_role_permission`, `platform_user_role`) and `audit_logs` (polymorphic).
  `app/Services/TenantLifecycle.php` — status state machine (pending/provisioning/trial/active/
  suspended/expired/deactivated/provisioning_failed), `canTransition()/assertTransition()/transition()`
  writes `audit_logs` rows (`tenant.status_changed`). Models: `PlatformRole`, `PlatformPermission`,
  `AuditLog`. `is_super_admin` remains the master platform gate (platform RBAC is schema-only for now).
- **Phase 12 shipped (provisioning & routing):** migration `000015` adds central `tenant_users`
  (`TenantUserRouting`: normalized `email`, `tenant_id` FK, `user_id`-in-tenant-DB, `name`;
  unique `(tenant_id, email)`) + `provisioning_runs` (per-attempt run/status/step/error/timestamps).
  `app/Models/Concerns/CentralConnection.php` trait (`getConnectionName()` →
  `TenantDatabaseManager::centralConnectionName()`: shared → `database.default`, isolated →
  `tenancy.system.connection`) is applied to all central models (`Tenant`, `ImpersonationLog`,
  `AuditLog`, `PlatformRole`, `PlatformPermission`, + new `TenantUserRouting`, `ProvisioningRun`). (The old `TenantScoped` row-scoping was removed entirely in
  Phase 13 — tenant `tenant_id` columns no longer exist; the DB is the boundary.)
  `config/tenancy.php` adds `tenant.driver` (env `TENANT_DB_DRIVER`, default `pgsql`, `sqlite` =
  the test fast-path via `tenant.db_path` env `TENANT_DB_PATH` files `{slug}_{id}.sqlite`) plus
  **`tenant.pg_role` (env `TENANT_DB_PG_ROLE`)** — an existing login role that owns every tenant
  database. When set, `createPostgresDatabase()` skips the per-tenant `CREATE ROLE` and owns each
  `flowsync_tenant_{id}` with that shared role (password defaults to the `system` connection's);
  this host's `flowsync` role has `CREATEDB` but **not** `CREATEROLE`, and managed PG (RDS/Cloud SQL)
  never grants it, so the shared role is the only way tenant DBs can be created here. Leave it null
  and each tenant gets its own generated role (unchanged docker-compose/prod behaviour). `TenantDatabaseManager` has a
  safe `switchDefault()` that **only purges the target connection when it differs from the current
  default** (purging an in-memory sqlite `:memory:` connection wipes the test DB — never
  `connectSystem()` while default is `:memory:` sqlite; isolated tests use a file-backed custom
  `iso_system` connection). `TenantProvisioner::provisionIsolated()` is the idempotent pipeline
  (connect → `createDatabase` → `migrateTenant` → seed → owner local `createAdmin` → `syncRouting`
  tenant_users → trial/active; skipped re-transition when already serviceable+provisioned).
  `app/Jobs/ProvisionTenantJob.php` (`tries=1`, no auto-retry; repair via
  `tenants:provision`) records `provisioning_runs`, transitions to provisioning, and on failure marks
  tenant `provisioning_failed` + `provisioning_error`. `app/Http/Middleware/SwitchTenant.php`
  (`switch_tenant` alias in `bootstrap/app.php`) — both authenticated route groups now start
  `['switch_tenant', 'auth', 'tenant'…]`; it resolves the per-tenant connection using the session keys
  `login.tenant_id` / `impersonate.tenant_id` (carrying **central** ids) and calls `using()`.
  `SetTenantContext` gained an isolated branch (context from session keys, not `user->tenant_id`).
  `AuthController::loginIsolated()` routes emails via `TenantUserRouting` (optional `tenant` slug
  disambiguator — >1 route without slug ⇒ 422 `tenant`), super admins fall back to the system DB;
  **gotcha: strip `tenant` from the attempt credentials** (`Arr::except($credentials, ['tenant'])`)
  or `Auth::attempt` builds `where tenant = ?` against `users` and fails. `ImpersonationController`
  gained `startIsolated()` (routing-based resolve) + connects system on stop. `TenantController` store
  → isolated: pending + dispatch job (202); `users()`/`counts()`/`index()` read via routing.
  Super-admin tenant management: `index` now validates `q`/`status`/`plan_id`/`trashed`/`sort`(`name|slug|
  status|created_at|updated_at|users_count`)/`dir`/`per_page` and returns `{tenants, pagination}` —
  `users_count` via `Tenant::routingUsers()` withCount (when sorting by it) or a per-page routing pluck;
  `subscription.plan` eager-loaded and inlined as `plan_slug/plan_name/subscription_status`. Added
  `destroy` (soft) + `POST …/restore` (**route declared `->withTrashed()`** so a trashed tenant still binds,
  while `suspend`/`activate` on a trashed tenant 404s) + `suspend`/`activate` via `TenantLifecycle`
  transitions; each writes an `audit_logs` row (`tenant.deleted|restored|status_changed`). `GET
  /tenants/{tenant}/stats` counts `users/workspaces/projects/tasks` on the tenant DB through
  `TenantDatabaseManager::using()` and `Cache::remember(…, 60)`. Frontend `Tenants.jsx` is a filterable
  table (filters, sort, "Include deleted", per-tenant ⋯ menu: View/Edit/View-as-user/Enable-Disable/
  Delete-Restore, edit modal, pagination footer; row subscription fields inline — no per-row lazy fetch);
  `TenantDetail` shows a lazy Usage card from `/stats`.
  **Platform management (item 10):** `platform_settings` key-value table (system, migration `000018`) +
  `PlatformSetting` (`value()/bool()/set()`, seeded idempotently: app_name, public_registration,
  default_plan_id, maintenance_mode). `SystemSettingsController` GET/PUT (`platform.settings_updated`
  audit; `default_plan_id` must be an **active** plan). `public_registration` now gates the public
  `/register` endpoint (RegisterController reads the setting, onboarding config is seed-time fallback —
  RegisterTest's `enableRegistration()` must flip the setting too). `SystemUsersController`
  (list + create SA accounts, `system.user_created` audit). `AuditLogsController` feeds a merged,
  paginated feed of central `audit_logs` + `impersonation_logs` over the **full** trail (H-7): the two
  sources are UNION-ed and filtered/ordered/paginated **in SQL** (`event_at desc, src asc, id desc`,
  backed by a `(created_at,id)` index), `type`/`q`/`tenant_id` filters run server-side (`tenant_id`
  reads the JSON payload or the subject id), and `GET system/audit-logs/export` (throttled, cap 10000)
  streams the same filtered feed as CSV while writing an `audit.exported` row. `auth.login`/
  `auth.login_failed`/`auth.logout` rows are written by `PlatformAudit::auth()`. `SystemAnalyticsController` (tenant/subscription status
  by-status from central DB + cross-tenant resource totals via capped fan-out of the first 100
  serviceable tenants, `Cache::remember 300s`). `FeatureManagementController` (module catalog × plan
  toggle grid persisting `plans.limits.modules`, `plan.module_toggled` audit — drives P15 gates).
  Frontend: `/admin` (SA Overview, `/` redirects SA here — the old `/admin/analytics` page was merged into it, same `/system/analytics` payload; the route redirects), `/admin/users|audit-logs|features|
  settings` pages + expanded SA sidebar (Administration: Overview/Tenants/Plans/Features; Platform:
  Users/Support/Audit Logs/Settings).
  `ProvisionTenants` command repairs isolated tenants. Tenant DBs run the **full current migration set**
  (central-table clutter accepted pre-cutover).
- **P13 cutover shipped** (see the Tenant-isolation section above; full test suite now runs through
  `Tests\IsolatesDatabase` on per-tenant sqlite files).
- **Phase 14 (subscriptions) shipped code** — see the Subscriptions section below.
- **Not yet wired (later phases):** `switch_role`/guard refinements, usage + module gates (P15),
  Super Admin platform (P16).
- Phase plan + Jira-feature expansion map + security/testing/migration strategy: see the doc (§14, §10, §12, §13, §11).

## Subscriptions (Phase 14)
- **Central/system** (migration `2026_09_24_000016_create_subscription_tables.php`):
  `subscription_plans` (limits JSON), `subscriptions` (**unique `tenant_id`** — one re-stamped row per
  tenant; statuses trialing|active|past_due|canceled|expired|ended), `subscription_events`
  (type subscribed|plan_changed|renewed|trial_started|trial_expired|canceled|reactivated|paused|
  payment_failed|seats_changed, from/to_plan_id, data, actor_id → system users). `tenants.subscription_id`
  FK lands **only on PostgreSQL** — the sqlite fast-path can't ALTER ADD CONSTRAINT, so it gets a plain
  index (app-level FK via the model).
- `config/subscriptions.php` = machine-readable catalog (modules: time_tracking/reports/global_search/
  branding; numeric limits: users/seats/workspaces/projects/tasks/storage_bytes/
  attachments_per_task; default plans starter/pro/business/enterprise). **`api` and `audit_export` were delisted**
  (gap G-9/H-2): they gated no route, so plans stopped selling dead entitlements — re-add each module
  only together with the route gate that enforces it. Seeded idempotently by
  `SubscriptionPlanSeeder` (`updateOrCreate` by slug, `is_default` guarded), which runs inside
  `TenantSeeder` and `TenantController::store`.
- Models `SubscriptionPlan` (`limit($key)`/`hasModule($module)`/`periodEnd(?Carbon)`),
  `Subscription` (`isActive()`/`onTrial()`; `Subscription::EVENT_*` constants), `SubscriptionEvent` —
  all `CentralConnection`; `Tenant` gained `subscription()`/`subscriptions()`/`subscriptionEvents()`.
- `app/Services/SubscriptionService.php` — `assign` (first subscribe **or** re-stamp + `plan_changed`),
  `startTrial` (updates `tenant.trial_ends_at`, lifecycle → TRIAL), `switch`, `cancel`, `renew`
  (reactivated vs renewed), `suspend` (past_due + `paused`), `record()` event writer. Lifecycle-synced:
  active↔trial via `TenantLifecycle`.
- `app/Services/TenantLimits.php` — `effective(Tenant)` = plan.limits ⊕ `tenants.limits_override`
  (override wins); **no subscription ⇒ unlimited** (keeps seeded acme/globex and legacy tenants running);
  `assertQuota(resource, context)` counts on the tenant (default) connection and throws
  `ValidationException` → 422 `form`; **no-op when `TenantContext::currentId()` is null**
  (provisioning/seeders/direct-service tests). Consulted by `UserController::store` + every create in
  `WorkspaceService`/`ProjectService`/`TaskService`. `hasModule()` helpers ready for P15 gates.
- Onboarding: `TenantController::store` validates plan_id (exists:subscription_plans,id), trial_days,
  billing_email/contact_*; sets `trial_ends_at` **before** dispatching so `provisionIsolated` lands the
  lifecycle on `trial`. `ProvisionTenantJob` constructor gained optional `?int $planId` / `?int $trialDays`
  and, post-provisioning, creates/re-stamps the subscription + `trial_started|subscribed` event
  (falls back to default plan; no-op when no plans exist).
- **Refactor (item 13) conventions:** controller input validation lives in `app/Http/Requests/` FormRequests
  (Login/ForgotPassword/ResetPassword/Register/ImpersonationStart/ThemeUpdate/FeatureModuleToggle/
  SystemSettingsUpdate/SystemUser index+store/GlobalSearch/SearchTasks/DependencyStore/AttachmentStore/
  Status store+update/CmsPage) — controllers type-hint the request and use `$request->validated()`.
  `CmsPageRequest` does `Rule::unique('website_pages','slug')->ignore($this->route('websitePage'))`.
  **Pagination payloads are uniform** `{current_page,last_page,per_page,total}` (never `->toArray()`);
  frontend renders them via the shared `components/ui/Pagination.jsx`. Date/price formatting helpers live
  in `utils/format.js` (`formatDate`/`formatDateTime`/`formatPrice`); deep-link/notification/time helpers
  in `utils/deepLinks.js`/`utils/notifications.js`/`utils/time.js`; role labels in `utils/roles.js`
  (`projectRoleLabel`/`workspaceRoleLabel` — the one place role slugs become lowercased Badge text);
  fetch-on-mount state in `hooks/useResource.js` (`useResource(fetcher,deps)` → `{data,setData,loading,error,reload}`,
  `usePaginated(fetchPage,deps)` adds `{page,setPage}`; `fetcher(signal)` forwards the `AbortController` signal to
  the axios call — new list/detail pages should use it instead of hand-rolled `useState`/`useEffect` fetching).
- Routes (super_admin group, system scope): `GET|POST /api/plans`, `PUT|DELETE /api/plans/{plan}`;
  `GET|POST /api/tenants/{tenant}/subscription`, `POST …/subscription/trial|cancel|renew|suspend`,
  `GET …/subscription/events`. Controllers: `PlanController`, `TenantSubscriptionController`.
- Frontend: `pages/Plans.jsx` at `/plans` (sideber super-admin entry; CRUD modal incl. module toggles);
  `Tenants.jsx` create form gained a plan picker + trial days and tenant cards show a subscription pill
  (lazy `GET /tenants/{id}/subscription`).
- **Tenant-facing self-service** (Item 5): `GET api/my-subscription` (current subscription + plan +
  recent events + tenant), `GET api/my-usage` (TenantLimits counts per users/seats/workspaces/projects/
  tasks + effective limits + modules), `GET api/plans` (**role-aware** — one route now: super admin sees
  the full catalog, any tenant user the active plans only), `POST api/my-subscription/switch|cancel|renew`
  (all admin-gated via `hasRole('admin')` 403; a non-impersonating super admin gets 404 — no tenant
  context). These live in the plain `auth → tenant` group (OUTSIDE the onboarding gate so the wizard's
  subscription step can read plans). `MySubscriptionController` self-scopes via `TenantContext`.
  Frontend: `pages/Subscription.jsx` at `/subscription` (current plan card, usage meters, included/
  excluded modules, upgrade grid, cancel/renew only for admins) + a Billing sidebar section (items with
  no `permission` are always shown — sidebar filter now keeps `!item.permission`).
  **`actor_id` gotcha:** tenant admins are NOT central `users` rows, so passing `auth()->id()` as
  `SubscriptionEvent.actor_id` violates the FK → tenant-side actions omit the actor (the actor travels
  in `data.actor` for switches; null for cancel/renew).
  **`exists:` rule gotcha:** the `exists:subscription_plans,id` validation rule resolves against the
  DEFAULT connection — on a tenant request that's the tenant DB (no `subscription_plans`), so
  `MySubscriptionController` uses a `SubscriptionPlan::find()` + 422 instead of the rule (the SA
  controller keeps the rule safely because SA requests run on the system connection).
- Latent bugs fixed: `ProvisionTenantJob::handle()` **lacked the `TenantProvisioner $provisioner`
  parameter** (undefined-variable crash); `IsolatedProvisioningTest` asserted a local `tenants` row +
  `users.tenant_id` + a void return from `provisionIsolated` (rewritten for Phase 13 reality).
- Phase plan + Jira-feature expansion map + security/testing/migration strategy: see the doc (§14, §10, §12, §13, §11).

## Subscription module gates (plan-driven feature toggles)
- `app/Http/Middleware/EnsureModule.php` (alias **`ensure_module`**, registered in `bootstrap/app.php`
  priority before `SubstituteBindings`) 403s a route group when the tenant's effective plan lacks the
  module: **bypass when `TenantContext::currentId()` is null** (non-impersonating SA / provisioning /
  seeders — included in the ModuleGateTest bypass case); otherwise `TenantLimits::hasModule($tenant,$module)`.
  An **impersonating** super admin HAS a tenant context and is bound to the target tenant's plan.
- Route gates in `routes/web.php`: theme `GET|PUT /theme` → `ensure_module:branding`;
  `search/global` + `search/tasks` → `global_search`; `reports/overview` → `reports`; the whole
  work-log + project/workspace time-summary block → `time_tracking`. Modules catalog +
  per-plan lists in `config/subscriptions.php`; Feature Management (item 10) persists
  `plans.limits.modules` (audit `plan.module_toggled`). A tenant with **no subscription is unlimited**
  (`TenantLimits::hasModule` true) — keeps seeded/demo tenants working.
- **HRMS sub-module gates (P1.14):** `hrms.onboarding`, `hrms.offboarding` and `hrms.documents` now each gate their own route groups on top of `hrms.core` (document requests ride `hrms.documents`), so removing one from a plan really switches the feature off; every default HRMS plan already includes all three, so no existing plan-based tenant changed. **Phase 22.1 closed the §3.5 deviation:** `hrms.compensation` gates the pay-structure/salary groups and `hrms.payroll` gates reads+runs+my-payslips (SPA routes + `HRMS_NAV` carry the same modules; `ModuleGateTest` pins both). Intended consequence: Pro/Business/hrms-professional/hrms-starter tenants lose pay access they were never sold — `ModuleCatalogTest` pins payroll as the Enterprise-only payoff, so no plan lists changed. **Modules that still gate nothing:** `hrms.inbox` (self-scoped, lives in the plain auth group by design). `hrms.talent` shipped in Phase 24 (calibration grid/writes, distribution, 360 summary + `Talent.jsx`, `talent.review_finalized` nudge). `hrms.exemptions` was delisted in Phase 22.3 (catalog-only, never gated — G-9/H-2 precedent). `hrms.shifts` now gates the P5.1/P5.2 shift + roster/rotation surface. Pending list + Phases 22–26: `.agents/roadmap/hrms-completion-track.md`.
- `AuthController::payload` (`me()`) carries `user.modules` = effective plan modules, falling back to
  the full catalog for no-subscription tenants (SA non-tenant = full catalog).
- Frontend: `AuthContext.hasModule()` (SA non-impersonating → true; tenant users vs `user.modules`)
  + `ProtectedRoute` accepts `module` prop (redirects to `/403`). `/search` rides
  `module="global_search"`, `/reports` `module="reports"`; Sidebar Search/Reports items, the Topbar
  theme button, and the CommandPalette are gated; `time_tracking` hides the Time tab set in
  ProjectDetail/WorkspaceDetail, TaskDetail's Time sub-tab, and Reports' time-scope card.

## Tenant onboarding (Phase 14)
- Optional self-service onboarding for new tenants, behind `config/onboarding.php` `enabled` (env
  `ONBOARDING_ENABLED`, **default off** → public registration is 403). Toggling + `docker-compose up -d app`
  re-reads it (read at boot). Steps catalog: business → admin → subscription → configuration (optional) →
  verification (optional) → completion (required) — all persisted to `tenants.onboarding_meta` JSON.
- `app/Services/TenantOnboarding.php` (singleton-service): `status()` (per-step complete + overall
  pending/in_progress/complete), `start()` (self-registration entry), `markStep()` (**rejects the terminal
  `completion` step** — only `business/admin/subscription/configuration/verification` via `completableSteps()`
  with full-catalog `validate`; overview + `complete()` mark everything), `complete()`, `reset()` (SA repair),
  `isComplete()`. **Gating rule:** completed_at set → complete; **never started the wizard → complete**
  (admin/seed/SA-provisioned tenants bypass automatically — self-registration is the only entry point);
  otherwise all required steps done.
- `app/Http/Middleware/EnsureOnboardingComplete.php` (**alias `onboarding_complete`**, registered in
  `bootstrap/app.php` priority BEFORE `SubstituteBindings`) 403s the whole domain route group
  (`switch_tenant → auth → tenant → tenant_context → onboarding_complete` at `routes/web.php`) until the
  tenant completes. Non-impersonating SA bypasses (no tenant context). The wizard's own endpoints live in the
  plain `auth → tenant` group (next to `tenant/profile`): `GET onboarding`, `PUT onboarding/step`,
  `POST onboarding/complete`; SA: `GET|PUT tenants/{tenant}/onboarding` (`step` = step key | `complete` |
  `reset`).
- **Public registration:** `POST api/register` (`RegisterController::store`, `throttle:10,1`) creates the
  central tenant (pending, `trial_ends_at` set for the selected/default plan's trial) — **flag `onboarding_meta`
  started BEFORE provisioning** (same Phase-14 pattern as `trial_ends_at`) — then `Bus::dispatchSync(
  ProvisionTenantJob(...))` (sync so the registrant can log in immediately), re-`syncRouting` after claiming,
  and auto-logs in via `AuthController::establishTenantSession()` (**runs the payload build inside
  `TenantDatabaseManager::using($tenant)`** so `$user->load('roles')` hits THAT tenant's DB, not the restored default).
  The provisioned `owner@{slug}.test` account is claimed for the registrant (name/email/password swap) and its
  stale `tenant_users` routing row is deleted afterward. Emails are unique per register (checked against
  `tenant_users` routing + central `SystemUser`). Colliding slugs get a `-2/-3…` suffix.
- `AuthController::payload` now carries `onboarding_complete` (true for no-tenant SA + never-started tenants).
- Frontend: `pages/auth/Register.jsx` at `/register` (GuestRoute; link on Login footer) → on success navigates
  `/onboarding`; `pages/Onboarding.jsx` wizard at `/onboarding` (progress bar, per-step actions, business
  mini-profile form → PUT `/tenant/profile` then marks the step, `Finish` → `POST /onboarding/complete` →
  `refresh()` + `/dashboard`). `AdminLayout` redirects any tenant user with `onboarding_complete === false`
  (except on `/onboarding` itself) to the wizard. `AuthContext.register()` mirrors `login()`.
