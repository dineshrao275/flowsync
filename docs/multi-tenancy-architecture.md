# FlowSync Multi-Tenant Architecture

**Database isolation, subscriptions, tenant onboarding & SaaS platform — Phase 11+**
Status: **P11 + P12 shipped; P13 (isolation cutover) in progress** · Reference for the isolated-only build-out (see §16 progress log) · Supersedes: shared-DB tenancy (P0–P10)

---

## 1. Purpose & recorded decisions

This document analyzes the current FlowSync architecture (Laravel 12 + React 19, phases P0–P10) and
proposes the changes required to support:

```
Super Admin → Subscription → Tenant → Dedicated Tenant DB → Workspaces → Projects → Tasks → Users/Roles/Permissions
```

with **database isolation per tenant**, **subscription-based plan/limit control**, a **complete
onboarding lifecycle**, and a **centralized Super Admin SaaS platform**.

Recorded decisions (confirmed):
1. **Database isolation mechanism: PostgreSQL, one database per tenant.** A central *system* DB holds
   global management data; each tenant gets its own PostgreSQL database.
2. **Deliverable: this design doc in the repo** (+ AGENTS.md / IMPLEMENTATION_TRACKER.md pointers).
3. **Proposal first; implementation in later phases** after review.

Non-goals (deferred, designed-for but not built): real payment-provider billing, full custom-field
engine, cross-tenant analytics warehouse, dynamic per-tenant theme/marketplace.

---

## 2. Current architecture analysis (as-built, P0–P10)

### 2.1 Runtime & data
- Laravel 12 + React 19 SPA (`resources/js/main.jsx`), Vite 7, Tailwind v4, axios, Reverb/Echo realtime.
- **Single SQLite DB** (`database/database.sqlite`, `config/database.php` → `sqlite`, `.env` `DB_CONNECTION=sqlite`).
- `phpunit.xml` runs on `sqlite :memory:`.
- Migrations: `0001–0004` infrastructure (users/cache/jobs, roles, user_settings, tenants,
  tenant-columns), `0005–0012` domain (workspaces, project structures, projects, task core,
  task collab, activity/notifications, search indexes, hardening indexes).

### 2.2 Tenancy model (today: shared schema + row scoping)
- **`TenantContext`** singleton (`tenantId`, `impersonating`), bound in `AppServiceProvider`.
- **`TenantScoped`** global scope: applies `where(tenant_id = currentId())` to models
  **only when** `currentId()` is non-null; `withoutTenantScope()` escapes it. Every domain table
  carries `tenant_id`; uniqueness is per-tenant (`unique(tenant_id, slug)` etc.).
- Middleware (`bootstrap/app.php` aliases): `auth → tenant → tenant_context → permission` on domain
  routes; `super_admin` for platform routes.
  - `SetTenantContext` (`tenant`): super admin → context null; else resolves `impersonate.tenant_id`
    or `user->tenant_id` and sets context + impersonating flag.
  - `EnsureTenantContext` (`tenant_context`): 403 unless context exists (blocks non-impersonating
    super admins from tenant domain).
  - `EnsurePermission` (`permission:slug`) + `Gate::define('permission')`: super admin bypass unless
    impersonating.
- **Auth**: session `web` guard, Eloquent `users` provider on the default connection. `AuthController`
  `login|me|logout` set `TenantContext` from `user->tenant_id` / impersonation. `AuthController::payload`
  returns user + tenant + roles + permissions + theme.
- **Impersonation**: session key `impersonate {log_id, tenant_id, original_user_id, original_user_name}`;
  `ImpersonationController::start/stop` (super admin → tenant user); `impersonation_logs` table in the DB.
- **Provisioning**: `TenantProvisioner::provision()` pins context to null, idempotently clones
  permissions/roles/priorities/project_roles and creates `owner@{slug}.test` admin in the *shared* DB.
  `TenantController::store` = validate name/slug/description → create tenant → provision.
- **Models**: `Tenant` has relations to users/roles/permissions/priorities/project-roles.
- Catalog configs: `config/permissions.php` (12 perms), `config/project_roles.php`, `config/priorities.php`,
  `config/task_statuses.php`, `config/theme.php`.

### 2.3 Domain model (per-tenant, today)
Tenant → **Workspace** (`workspaces`, `workspace_members` roles owner/admin/member) → **Project**
(`projects`, `project_members` + `project_roles` catalog, per-project `task_statuses` /
configurable workflow, `priorities` tenant catalog) → **Task** (`tasks` w/ subtask `parent_id`,
`task_dependencies` blocks/related, `labels`/`task_label`, `comments`, `attachments`, `work_logs`,
`activities`, `notifications`). Already implemented (P0–P10): roles/permissions, workflows/statuses,
priorities, labels, comments/mentions, attachments (signed downloads), dependencies, work logs/time
tracking, activity timeline, notifications, search, dashboard/reports, global search, scale seed data.

### 2.4 What inherently changes under database isolation
| Seam | Today (shared DB) | Under per-tenant DBs |
|---|---|---|
| `TenantScoped` / `tenant_id` columns | Row-level scoping is **the** isolation | Not needed — the DB is the boundary; columns/trait removed |
| `TenantContext` | filters queries | selects the **connection** |
| `AuthController` / guard | single Eloquent provider | must route a user to their tenant DB before authenticating |
| `SetTenantContext` | context after `$request->user()` | must connect tenant DB **before** the guard resolves the user |
| `TenantProvisioner` | seeds catalogs into shared DB | runs inside the freshly created tenant DB, on its own connection |
| `TenantController::store` | `Tenant::create` + in-DB provision | full onboarding pipeline (DB create → migrate → seed → admin → status) |
| Global search (P9) / reports / super admin | single DB queries | fan-out queries across tenant connections |
| Realtime channel auth + queue | single DB | channel auth in tenant DB; per-tenant queue context |
| Scale seeding / tests | one shared schema | one schema per tenant; test infra gains Postgres |

---

## 3. Target architecture overview

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  SYSTEM DATABASE (Postgres: flowsync_system) — global/multi-tenant concerns   │
│                                                                              │
│  tenants ── subscription_plans / subscriptions                                │
│  tenant_users (email routing index)   system users (super admins)             │
│  platform_settings · feature_flags · audit_logs · tenant_activity_logs        │
│  usage_metrics (aggregates) · provisioning_runs · impersonation_logs          │
│  cache / jobs / sessions / migrations                                         │
└──────────────────────────────────────────────────────────────────────────────┘
        │ 1..N (tenant → its own DB, connection resolved per request)
        ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│  TENANT DATABASE (Postgres: flowsync_tenant_{id})  — one per tenant           │
│                                                                              │
│  users / roles / permissions / role_user / permission_role / user_settings    │
│  workspaces / workspace_members                                               │
│  project_roles / priorities / projects / project_members / task_statuses      │
│  tasks / labels / task_label / comments / attachments / work_logs             │
│  task_dependencies / activities / notifications                               │
│  (db-side FK constraints make cross-tenant data unreachable)                  │
└──────────────────────────────────────────────────────────────────────────────┘
```

- The **system DB** is the single source of truth for: tenant records + lifecycle, subscription plans
  and active subscription state, login routing (email → tenant), super-admin users, platform settings,
  aggregated usage/analytics, provisioning/audit, and infrastructure tables.
- The **tenant DB** holds all tenant application data (users, roles, workspaces, projects, tasks,
  collaboration, etc.). Its schema is the current domain schema **minus** `tenant_id` columns, with
  plain (non-composite) unique constraints restored (e.g. `users.email` unique, `roles.slug` unique).

---

## 4. Central system database design

### 4.1 `tenants` (expand existing table)
```
id                          (existing)
name, slug, description     (existing)
status                      enum: pending | provisioning | active | trial |
                                     suspended | expired | deactivated | provisioning_failed
     (see §7 lifecycle; 'trial|active' = serving state)
provisioning_status         enum: pending | db_created | migrated | seeded | provisioned | failed
provisioning_error          text nullable
provisioned_at              timestamp nullable
db_name, db_host, db_port   tenant DSN; db_name = flowsync_tenant_{id} (host/port default from env)
db_user, db_password        encrypted (tenant-scoped PG role)
subscription_id             FK → subscriptions (nullable; convenience)
billing_email               string nullable
contact_name/contact_email  nullable
trial_ends_at               timestamp nullable   (denormalized for the active trial)
limits_override             jsonb nullable   (per-tenant override of plan limits)
features_override           jsonb nullable   (per-tenant feature toggles)
onboarding_meta             jsonb nullable   (wizard state, locale, timezone, industry)
settings                    jsonb nullable   (tenant-level settings)
archived_at / deleted_at    nullable (soft)
timestamps
```

### 4.2 `subscription_plans`
```
id, name, slug (unique), description
is_active bool, is_default bool
billing_cycle enum: monthly | annual
price per cycle: price_cents, currency (default USD)
trial_duration_days int nullable
limits  jsonb  — machine-readable, keyed by the catalog §5.3  e.g.
                {"users": 10, "workspaces": 5, "projects": 50, "tasks": 5000,
                 "storage_bytes": 5368709120, "modules": ["time", "reports", "api"]}
sort_order, timestamps
```

### 4.3 `subscriptions`
```
id, tenant_id FK, plan_id FK, status enum:
   trialing | active | past_due | canceled | expired | ended
current_period_start / current_period_end timestamps
trial_ends_at timestamp nullable
canceled_at nullable, auto_renew bool default true
seats int default 0 (billable user count; collisions check user limit)
billing_provider / billing_reference nullable (future gateway boundary)
timestamps
```
One active subscription per tenant; plan changes create a history row in `subscription_events` and
re-stamp the subscription (see §6).

### 4.4 `subscription_events` (audit)
`id, tenant_id, subscription_id, type` (`subscribed|plan_changed|renewed|trial_started|trial_expired|
canceled|reactivated|paused|payment_failed|seats_changed`), `from_plan_id, to_plan_id, data jsonb, actor_id, created_at`.

### 4.5 `tenant_users` (login routing index)
Denormalized index kept in sync with each tenant DB's `users` (updated by the same services that write
users): `id, tenant_id FK, email (lowercase, unique per tenant), user_id (id inside tenant DB)`.
Purpose: resolve *"which tenant, which DB, which user row"* for login without scanning every tenant DB.
Email remains per-tenant-unique (`unique(tenant_id, email)`); the same address may exist in several
tenants → login UX clarifies the tenant when ambiguous (or tenant slug in the login form).

### 4.6 System users & platform admin
Super admins live in the system DB's `users` (`is_super_admin = true`, no tenant). Introduce
`platform_roles` / `platform_permissions` (many-to-many) now as a **schema**, so Super Admin can later
get granular platform permissions — `is_super_admin` stays the master gate and `EnsureSuperAdmin`
keeps working.

### 4.7 Audit & activity (system side)
- `audit_logs` (system-level: tenant created/suspended/plan changed, admin actions, provisioning runs).
- `tenant_activity_logs` (tenant→id, event type, data, actor — feed for the Super Admin platform).
- `usage_metrics` (`tenant_id, metric_key, period (date/cycle), value`) — **aggregates**, refreshed by a
  scheduler from tenant DBs (see §8); precedent for dashboards.

### 4.8 Existing tables that move to the system DB
`tenants`, `impersonation_logs` (super_admin_id now points to system `users`), `jobs`, `cache`,
`sessions`, `migrations`, `password_reset_tokens` (super admin resets). These are *system-wide*
infrastructure, one instance, central.

---

## 5. Per-tenant database design

### 5.1 Schema = current domain schema, minus tenancy
Tables (each in the tenant DB, **no `tenant_id` column**):
`users`(+`remember_token`, no `is_super_admin`/`tenant_id`), `password_reset_tokens`, `user_settings`,
`roles`, `permissions`, `role_user`, `permission_role`, `workspaces`, `workspace_members`
(`user_id` now local), `project_roles`, `priorities`, `projects`, `project_members`, `task_statuses`,
`tasks` (+ upcoming columns §10), `labels`, `task_label`, `comments`, `attachments`, `work_logs`,
`task_dependencies`, `activities`, `notifications`.
Uniqueness restored to global-within-DB form: `users.email` unique, `roles.slug` unique,
`permissions.slug` unique, `workspaces.slug` unique, `projects.key` unique, `task_statuses(project_id,slug)`
unique, `tasks(project_id,key|sequence)` unique, `labels(workspace_id,name)` unique.

### 5.2 Models
- Tenant-domain models: default connection; **delete `TenantScoped` trait** and all `tenant_id`
  references from queries/services/seeders. `withoutTenantScope()` calls removed.
- Central models (`Tenant`, `SubscriptionPlan`, `Subscription`, `TenantUserRouting`, platform models):
  `protected $connection = 'system'`.
- `User` is dual: a super admin model on `system` (no tenancy) and the tenant user model on the tenant
  connection. Keep one `App\Models\User` for tenant users + `App\Models\SystemUser` (or super-classed)
  for admins; auth payload/`me` adjusted.

### 5.3 Feature & limit catalog (`config/subscriptions.php`)
Machine-readable registry so plans/UI/limits share keys:
```
modules:        time_tracking, reports, global_search, api, branding, audit_export
numeric limits: users, seats, workspaces, projects, tasks, storage_bytes, attachments_per_task
```
Tenant effective limits = `plan.limits` merged with `tenants.limits_override` (override reserved for
sales/enterprise). Feature gate = `features[key]` boolean (plan module list) × `tenants.features_override`.

---

## 6. Connection & tenancy management

### 6.1 Connection wiring (`config/database.php`)
```
'default' => env('DB_CONNECTION', 'system'),
'connections' => [
  'system' => pgsql (flowsync_system; host/port from env),
  'tenant' => pgsql (database resolved from TenantContext; used to build/borrow pools),
],
```
A `TenantDatabaseManager` (singleton, `app/Services` or `app/Support`) provides:
- `dsn(Tenant $tenant): array` — returns mysqli-style config: db_name from record or `flowsync_tenant_{id}`,
  host/port from env or tenant record, **decrypted** db_user/db_password.
- `connect(Tenant $tenant): void` — purge + set `database.connections.tenant.*` + set default
  connection to a pool specific to that tenant. Implementation: `DB::purge($name)`,
  `DB::setDefaultConnection($name)` after `Config::set(...)`.
- `connectCentral(): void` — restore system connection.
- `using(Tenant $tenant, callable $fn)` — scoped connect/restore (queue jobs, provisioning, fan-out).

### 6.2 Request lifecycle
Middleware order becomes **`switch_tenant → auth → tenant → tenant_context → permission`**
(instead of `auth → tenant`):
1. `switch_tenant` (new): from session (`impersonate.tenant_id` else `login.tenant_id` stored at
   login) → `TenantDatabaseManager::connect($tenant)`; no tenant in session → `connectCentral()`.
2. `auth`: now resolves the user on the **already switched** connection (guard provider default
   connection), so `$request->user()` yields the tenant's user — or a super admin on central.
3. `tenant` (`SetTenantContext`): re-set `TenantContext` (id + impersonating) from the authenticated
   user/impersonation as today — now it merely records *which* tenant is active.
4. `tenant_context` / `permission`: unchanged semantics (block non-impersonating super admin from
   tenant routes; super admin Gate bypass unless impersonating).

### 6.3 Auth & session routing
- Custom guard/provider: a `TenantUserProvider` (Eloquent, reads default connection after switch) +
  session stores `login.tenant_id` on authenticate. `AuthController::login`:
  1. validate email/password
  2. resolve candidates via `tenant_users` routing index (email normalized)
  3. ambiguous → 422 "which tenant?" (or accept `tenant` slug from login form)
  4. `using($tenant)`: `Auth::attempt` against the tenant DB → success ⇒ `Auth::login`, regenerate,
     `AuthController::payload`.
  5. super admin path: attempt on `system`.
- Password resets: per-tenant (tokens live in the tenant DB; email routed like login).
- Channel auth (`routes/channels.php`) + Echo `user.{id}`/`project.{id}`: resolve on the switched
  connection; channel names carry `tenant_id` to prevent cross-tenant fakes (namespace/user ids).

### 6.4 Impersonation under isolation
`ImpersonationController::start` (super admin on system): resolve target via `tenant_users` routing →
`connectTenant` → `Auth::login($target)`; session already carries `impersonate.tenant_id`, which
`switch_tenant` uses. `stop`: restore `SystemUser::withoutTenantScope()` → `connectCentral()` →
`Auth::login(original)`. `impersonation_logs` written on the system connection.

---

## 7. Tenant lifecycle & onboarding workflow

### 7.1 Status model
```
pending ──► provisioning ──► trial ──► active ──► suspended ──► active
              │  ▲           │  ▲      ▲           (reactivate)
              ▼  │           ▼  │      │
        provisioning_failed  expired ◄─┘ (trial/period end)
              │ (re-run)
              ▼
          retry → provisioning        deactivated (terminal, export/delete later)
```
Valid transitions enforced in a `TenantLifecycle` service + controller guards (reject illegal jumps).

### 7.2 Onboarding pipeline (`ProvisionTenantJob`, idempotent + resumable)
`TenantController::store` (super admin) now takes: name, slug, description, `plan_id`, trial days,
billing email, contact info → create tenant (`status=pending`, `provisioning_status=pending`) →
dispatch `ProvisionTenantJob`:
1. `status=provisioning`
2. Create PostgreSQL database `flowsync_tenant_{id}` + dedicated PG role with password (owner of that
   DB only) — via a maintenance connection (superuser), **encrypted** creds stored on `tenants`.
3. Run migrations scoped to the tenant DB (`php artisan migrate --database=tenant` with the switcher;
   per-tenant migrations table).
4. Seed catalogs inside the tenant DB: permissions/roles/priorities/project-roles + **initial admin
   owner account** (replaces `TenantProvisioner` interacting with the shared DB).
5. Insert/refresh the `tenant_users` routing row for the owner.
6. Create/activate subscription (trial) → `status=trial|active`, `provisioning_status=provisioned`,
   `provisioned_at`, audit + platform notification.
Any failure → mark `provisioning_status=failed` + `provisioning_error`, retryable via
`php artisan tenants:provision --tenant=ID` (keeps the existing command name/semantics; it now also
repairs per-tenant schema/seed/owner).

### 7.3 Super Admin onboarding UX (frontend)
`pages/Tenants.jsx` grows into a full **Onboarding** flow: create → pick plan/trial → contact/billing
info → live provisioning status (poll `GET /api/tenants/{id}` for `provisioning_status`) → success w/
owner login. Also `GET /api/tenants/{id}/activity` (provision timeline).

---

## 8. Subscription, usage & limit enforcement

### 8.1 Plan management (Super Admin)
`PlanController` (system scope, `super_admin`): CRUD plans; activation defaults; a **Plans** page
(`/plans`) in the platform area. `TenantSubscriptionController`: assign plan / start trial / switch
plan / cancel / renew / suspend. On plan change: enforce downgrade (limit check with grace), write
`subscription_events`, re-cache effective limits.

### 8.2 Enforcement points
A `TenantLimits` service (`effective(Tenant)`: plan.limits ⊕ overrides, cached with tenant id + plan
version) consulted by the write paths (`UserController::store`, `WorkspaceService::create`,
`ProjectService::create`, `TaskService::create` / bulk create, `AttachmentController::store` for
storage) → **422/402** with a friendly "your plan allows N users…" message. Module gating:
production-level check in the relevant controllers (e.g. work-log routes require module `time_tracking`).
Grace behavior configurable per limit (hard vs warn) for downgrades.

### 8.3 Usage accounting
- Counts are *derived* live in the tenant DB for enforcement (already cheap: `count` on scoped queries).
- Aggregates land in system `usage_metrics` via a scheduled command (`tenants:collect-usage`) that
  fan-outs a lightweight per-tenant query — powers Super Admin dashboards without cross-DB joins.
- Attachment storage bytes tallied in the same pass.

---

## 9. Super Admin platform (system scope)

All under `Route::middleware(['auth','super_admin'])`, models on `system` connection, UI mirrored in a
platform section of `AdminLayout` (sidebar gated `is_super_admin`), fully separate from tenant
permissions.

| Area | Endpoint(s) / feature | Notes |
|---|---|---|
| Onboarding | `POST /api/tenants` (expanded) + status polling | §7 |
| List/search | `GET /api/tenants` (+ q, status, plan filters) | expand `TenantController::index` |
| Details | `GET /api/tenants/{id}` (counts, subscription, usage, provisioning) | |
| Lifecycle | `POST /api/tenants/{id}/suspend`, `/reactivate`, `/deactivate` | `TenantLifecycle` |
| Plans | plans CRUD + `POST /api/tenants/{id}/subscription` (plan/trial/cancel/renew) | §8.1 |
| Usage/stats | `GET /api/platform/usage` (users/workspaces/projects/tasks/storage by plan & tenant) | from `usage_metrics` + live fan-out |
| Activity | `GET /api/tenants/{id}/activity`, `GET /api/platform/activity` | system side |
| Health | `GET /api/platform/health` (system DB ≥, per-tenant DB connectivity sampled) | |
| Audit | `GET /api/platform/audit-logs` | |
| Feature mgmt | `GET/PUT /api/platform/features` (global defaults) | config-driven |
| Impersonate | existing `POST /api/impersonate` (cross-DB now) | §6.4 |
| Global search | `GET /api/search/global` reworked: **fan-out** across tenant DBs concurrently (limit/merge top-N); super admin path pins central | upgrade P9 controller |
| Drill-down | Tenant → workspace → project → task → activity read-only inspection: fan-out queries on the target tenant DB via temporary connection; **acting** = impersonation | |

---

## 10. Feature expansion map (Jira-like reference)

Concepts mapped to current state + proposals. **= already shipped (P0–P10), ◆ = schema currently exists
(with gaps), ○ = new schema needed, ≈ = adapted, deferred = future phase.**

### 10.1 Tenants
**=** create, list, users, impersonate, global search · ◆ provisioning status (new) · ○ status/lifecycle
(§7) · ○ subscription/plan/limits (§5/§8) · ○ platform dashboard (§9).

### 10.2 Workspaces
**=** name/slug, members (owner/admin/member), labels, archive/restore, time summary, policy-scoped
permissions · ◆ expand model: add `description`, `icon`, `color`, `timezone`, `working_hours`,
`default_assignee_id`, `settings jsonb` (notifications, default priority/status, language) · ○
workspace-level activity feed (`activities` subject=`workspace`) · ○ workspace notifications/config ·
≈ reports per workspace (P7 dashboard scopes to tenant) · respects **subscription limits** (workspace
create) via `TenantLimits`.
### 10.3 Projects
**=** detail/key/lead/members/roles/permissions/settings/workflow (per-project statuses, category →
`backlog|todo|in_progress|in_review|done`)/priorities/labels/activity/reports/archive · ◆ add
`description`, `icon/color`, `default_assignee`, `notification config jsonb` · ○ **components/modules**
(`project_components`: name, lead, description; component match rule on tasks) · ○ **versions/releases**
(`project_versions`: name, released, release_date, description; optional version target on tasks) · ○
**issue types** (`issue_types` tenant or project catalog: story/task/bug/epic, `is_subtask`, icon, defaults
for status/priority; `tasks.issue_type_id` FK), default-seeded from `config/issue_types.php` · ○ notify
on project-level events (test: default) · ≈ distinct workflows per project (already per-project statuses);
future: shared workflow schemes.
### 10.4 Tasks / Issues
**=** summary/description, assignee, reporter, priority, status (configurable workflow), labels, due
date, estimates (estimate_minutes), time tracking (work logs), subtasks (`parent_id`), dependencies
(blocks/related), comments + mentions, attachments (signed), activity, notifications, audit ·
◆ add columns: `start_date`, `story_points decimal`, `issue_type_id`, `component_ids` (pivot) ·
○ **watchers** (`task_watchers task_id,user_id`) + `task.watched` notifications on comments/status ·
○ **linked issues** (reuse `task_dependencies` types; extend enum with `relates|duplicates|blocks…
clones`) · ○ **custom fields** (deferred to a dedicated phase): `custom_field_definitions` (tenant/project
scope) + `custom_field_values` (polymorphic or jsonb); UI renders per issue-type layout · ≈ auditable
changelog (existing `activities`), notifications (existing). Mention delivery already exists.
### 10.5 Cross-cutting
**=** roles/permissions (tenant + project), Reverb realtime, theme, global search, scale seed data ·
◆ open-ended `activities.data jsonb` supports new actions · ○ subscription-aware everything (§8).

---

## 11. Data migration & seeding strategy

- The current dev DB is the **P0–P10 shared SQLite** (with 250k-scale seeded data). Under isolation it
  no longer represents the model: **no automatic shared→multi-DB migration** is proposed. Dev/CI use
  fresh provisioning:
  - `php artisan migrate` (system) + `php artisan tenants:provision`-style bootstrap creating tenant DBs.
  - `TenantSeeder` rewritten to ranch a small set of tenants (acme/globex + scale) through the real
    onboarding pipeline; `ScaleDataSeeder` reworked to seed **inside** a tenant DB on the tenant
    connection (same bulk-insert logic, no `tenant_id` columns).
  - Optional `php artisan tenancy:export-tenant-data` (sql dumps) documented but not built.
- **Dual-mode build-out**: during implementation, `TENANCY_DRIVER=shared|isolated` kept the existing
  shared-DB path runnable (tests + `composer run dev`) until Phase 13 cut it over; the shared mode has
  now been **dropped** (isolation is the only driver — see §16).

## 12. Security model

- **Per-tenant PG roles**: each tenant DB is created (by a maintenance superuser) *owned by a dedicated
  role* with a generated encrypted password; tenant connections use only that role → a compromised
  tenant credential cannot read other databases.
- Credential handling: `db_password`/`db_user` encrypted at rest (`encrypted` cast); never returned by
  any API; DSNs assembled server-side only.
- Connection hygiene: `DB::purge` + cache clearing on switch so the same worker/request never spills a
  previous tenant's pool/connection into the next tenant's request; queue jobs re-switch explicitly.
- Cross-tenant defense in depth: DB boundary + route binding (tenant-scoped queries) + policies +
  `tenant_context` middleware remain the same checks as today, now on isolated storage.
- Realtime: channel names + auth resolve on the switched connection; verify membership per request
  (no cached cross-tenant channel grants).
- Encryption keys, env-based DB host/port/creds, rate limiting (`throttle:10,1` on login stays).

## 13. Testing strategy

- Existing 212 tests (sqlite :memory:) keep running during shared-mode build-out.
- Isolation phases introduce a **feature test matrix on real PostgreSQL** (CI service, local
  `docker-compose.yml` added): create tenant DB → migrate → seed → login → CRUD → assert
  cross-tenant DB isolation (tenant B cannot query tenant A's DB), subscription limit enforcement
  (over-limit create returns 422), lifecycle transitions, plan CRUD, impersonation across connections,
  global search fan-out.
- `ScaleDataSeederTest`/`DeepLinkAccessTest` re-targeted to tenant connections; `phpunit.xml` gains a
  pgsql profile. Draft a `docker-compose.yml` (postgres:17) for dev + CI parity.

## 14. Phased implementation plan (gates in repo style)

| Phase | Scope | Gate |
|---|---|---|
| **11 — Platform foundations** | `docker-compose.yml`; system connection; `TenantDatabaseManager`; `TENANCY_DRIVER=shared` default; expand `tenants` (status/provisioning/db fields, encrypted casts); platform_roles/permissions schema; `TenantLifecycle` state machine skeleton | pint · tests (212 still green) · build |
| **12 — Provisioning & routing** | `ProvisionTenantJob` (create DB+role → migrate → seed → owner); `tenant_users` routing; `switch_tenant` middleware + tenant guard; multi-DB AuthController + impersonation; `tenants:provision` now repairs tenant DBs | new feature tests (provision/login/impersonation) |
| **13 — Isolation cutover** | domain models on tenant DB; drop `TenantScoped` + all `tenant_id` cols/migrations; drop `TENANCY_DRIVER=shared`; rewrite TenantSeeder + ScaleDataSeeder on tenant connections; port the 212 tests to isolated mode | full suite on pgsql · pint · build |
| **14 — Subscriptions** | plans/subscriptions/subscription_events schema + PlanController + Plans UI; tenant subscription assignment UI; trial/status management; `TenantLimits` service (users/workspaces/projects/tasks for now) | limit-enforcement tests · UI gate |
| **15 — Usage & modules** | storage limits (attachments), module gates (time_tracking/reports/api), `usage_metrics` collector, downgrade grace | tests · dashboards read real aggregates |
| **16 — Super Admin platform** | tenant list/search/details, status mgmt, platform dashboard w/ usage + activity + health + audit, feature mgmt, global search fan-out, tenant→…→activity drill-down | platform UI/API tests · scale-validation |
| **17 — Feature expansion** | workspace model expansion, project components/versions/issue-types, task start date/story points/watchers/linked-issues real enum; custom fields (deferred to its own phase) | per-feature tests · build |

Each phase ends gated (pint / `php artisan test` / `npm run build`) and documented in AGENTS.md +
IMPLEMENTATION_TRACKER.md, matching P0–P10 convention.

## 15. Open questions to confirm before Phase 11
1. Postgres server source for dev/CI (Docker compose vs managed instance) — docker-compose proposed.
2. Ambiguous-email login: tenant slug field in login form vs email-routing disambiguation list.
3. Downgrade/over-limit behavior: hard-block vs grace window (default: hard-block new creates, grace for existing).
4. Trial default: apply plan trial_duration_days automatically on onboarding (proposed) vs manual.
5. Custom fields (task/issue): confirm deferral beyond Phase 17.
6. Whether the P0–P10 shared SQLite dev DB should be discarded wholesale vs archived at cutover.

---

## 16. Phase progress log (cutover tracking)

> Kept current while Phase 13 (isolation cutover) is in flight. **Resume point:** the working tree has
> been re-based onto isolated-only code; the remaining gate is wiring the test suite + seeders, then
> gates + docker validation. Detailed live checklist: `IMPLEMENTATION_TRACKER.md`.

### Shipped before this log
- **Phase 11 — Platform foundations**: `docker-compose.yml` (postgres:17, system DB `flowsync_system`);
  `system`/`tenant` connections; `TenantDatabaseManager` singleton (dsn/connect/using/switching);
  `TENANCY_DRIVER=shared` default; `tenants` expansion (`000013`: status/provisioning/db creds encrypted/
  billing/limits); `platform_roles`/`platform_permissions`/`audit_logs` (`000014`); `TenantLifecycle`.
- **Phase 12 — Provisioning & routing**: `tenant_users` + `provisioning_runs` (`000015`);
  `CentralConnection` trait; `TenantProvisioner::provisionIsolated` (create DB → migrate → local tenants
  row → seed → owner → syncRouting); `ProvisionTenantJob`; `switch_tenant` middleware; isolated
  login/impersonation; `ProvisionTenants` repair command.
- External refactor (done outside this doc): `TenantScoped` trait file deleted; all domain model
  fillables stripped of `tenant_id`; `user_settings`/membership tables already keyed locally.

### Phase 13 cutover — completed so far (current session)
1. **Migration split** — files moved into `database/migrations/system/` and `database/migrations/tenant/`;
   root `000004_add_tenant_columns` deleted. System set: users (**with `is_super_admin`**) +
   password_reset_tokens + sessions (central, `user_id` unconstrained index), cache, jobs, tenants +
   impersonation_logs (`000003`), `000013`, `000014`, `000015`. Tenant set: users (**no `is_super_admin`**)
   + password_reset_tokens (no sessions), roles, user_settings, `000005`–`000012` with **no `tenant_id`**;
   uniques now global (`workspaces.slug`, `project_roles.slug`, `priorities.slug`, `projects.key`,
   `labels(workspace_id,name)`, `tasks(project_id,key|sequence)`, `task_statuses(project_id,slug)`,
   `users.email`, `roles.slug`, `permissions.slug`); `000012` dropped the `activities_tenant_subject_index`.
2. **Config/env** — `config/tenancy.php` driver default → `'isolated'`; `config/database.php` default →
   `env('DB_CONNECTION', 'system')`; `.env` sets `DB_CONNECTION=system`, `TENANCY_DRIVER=isolated`,
   `TENANT_DB_DRIVER=sqlite`, `TENANT_DB_PATH=database/tenants`, and central infra routing
   `SESSION_CONNECTION=system` / `DB_CACHE_CONNECTION=system` / `DB_QUEUE_CONNECTION=system` (sessions/
   cache/jobs stay in the system DB even while the default connection is a tenant DB).
3. **Models** — `App\Models\SystemUser` (new; extends User, `CentralConnection`, own fillable with
   `is_super_admin`); `User::$fillable` stripped of `is_super_admin` (**cast kept** — payload needs a
   strict boolean, tenant-DB rows just read null); `Tenant` lost the users/roles/permissions/priorities/
   projectRoles hasMany (tables live in tenant DBs now), kept `impersonationLogs`.
4. **TenantDatabaseManager** — isolated only (`isShared()`→false); `centralConnectionName()` always
   `system`; `migrateTenant()` now passes `--path=database/migrations/tenant`.
5. **TenantProvisioner** — `provision()` (shared) and `insertLocalTenantRow()` deleted; `provisionIsolated`
   seeds directly into the tenant DB (no `tenant_id`, no local `tenants` row), returns void; owner admin
   `firstOrCreate` by email.
6. **Auth stack** — `AuthController`: login = `loginIsolated` only; `payload()` resolves `tenant` from
   **session** (`impersonate.tenant_id` ?? `login.tenant_id`) via the central `Tenant` model (no `tenant()`
   relation), skips `roles`/`settings` loads for super admins (those tables don't exist in the system DB);
   `applyTenantContext` session-based. `SwitchTenant`/`SetTenantContext` shared branches removed.
   `ImpersonationController`: shared `start`/`validateUserExists`/`withoutTenantScope` removed; `stop`
   always `connectSystem()`. `TenantController`: isolated-only (routing counts, async provisioning,
   pending status). `UserController`: `tenant_id` validation/create stripped (plain `unique:users,email`).

### Phase 13 cutover — IN PROGRESS / next steps
- [ ] `ProvisionTenantJob`: delete the `isShared()` no-op branch (isolated pipeline only).
- [ ] `ProvisionTenants` command: drop shared branch.
- [ ] Services strip `tenant_id`/`TenantContext`/`withoutTenantScope`: `ActivityLogger`, `NotificationService`
  (drop tenant_id write), `TaskService` (drop `$project->tenant_id` at create + `withoutTenantScope`),
  `WorkspaceService`, `ProjectService`, `WorkLogService` (same-tenant checks become null-checks only).
- [ ] Domain controllers: `RoleController` (`unique:roles,slug` + drop tenant_id), `ProjectRoleController`
  (drop tenant_id), `LabelController`, `CommentController`, `AttachmentController` (storage path
  `tasks/{project_id}/{task_id}` + drop tenant_id), `TaskController`.
- [ ] `GlobalSearchController` + `ScopesVisibleTasks`: replace `TenantContext::setTenantId(null)` /
  `user->tenant` / `with(['tenant'])`; add **super-admin cross-tenant fan-out** (iterate serviceable
  tenants → `dbm->using` → merge top results) for workspaces/projects/tasks; `searchUsers` scoped to the
  current tenant DB (no `users.view` global across DBs).
- [ ] Seeders: rewrite `TenantSeeder` (system super admin via `SystemUser`; acme/globex via
  `provisionIsolated`) + `ScaleDataSeeder` (no `tenant_id`, no `withoutTenantScope`).
- [ ] Docker: `docker/entrypoint.sh` `migrate --database=system --path=database/migrations/system` +
  `tenants:provision`; `.env.docker` system-connection flags (keep tenant driver `pgsql` for the compose
  PG stack); rebuild `flowsync:latest`.
- [ ] Tests: replace `RefreshDatabase` with a `Tests\IsolatesDatabase` trait (file-backed `iso_system`
  sqlite + tenant sqlite files; `config session.connection=iso_system`; migrate system; provision acme;
  `session(['login.tenant_id' => ...])`) across the ~27 Feature files; delete `database/database.sqlite`
  usage in `phpunit.xml`; fix `TenantController::index` `DB::raw('COUNT(*) as `count`')` (sqlite backticks).
- [ ] Gates: `./vendor/bin/pint` (whole repo), `php artisan test` (expect 241 + isolated port), `npm run build`.
- [ ] Docker validation: login (tenant + superadmin), `/api/workspaces`, `/api/tenants`, Reverb 8080.

### Not yet wired (later phases)
`switch_role`/guard refinements, `TenantLimits`/subscriptions (P14), usage/modules (P15), Super Admin
platform + global-search fan-out hardening (P16), feature expansion (P17).