# FlowSync Task Management — Implementation Tracker

Status legend: `[x]` done · `[ ]` pending · `[~]` in progress

Demanded verdict gates at each phase: `php artisan test` · `./vendor/bin/pint` · `npm run build` · AGENTS.md updated. Phases are shippable independently.

---

## Confirmed design decisions (scope lock)

- **Hierarchy:** Tenant → Workspace → Project → Task (subtasks via self-FK). All domain tables carry `tenant_id`.
- **Visibility:** membership-based. Visible = workspace member + project member; tenant admins manage all in their tenant. New `tenant_context` middleware blocks non-impersonating super admins from domain routes.
- **RBAC layers:** tenant-level permissions (platform, `config/permissions.php` in scope) + tenant-scoped **project roles** (`project_roles`, seeded lead/developer/viewer + custom) + workspace membership roles (owner/admin/member). Enforced via `permission:` middleware + Laravel Policies.
- **Priorities:** tenant-scoped `priorities` table (seeded from `config/priorities.php`, customizable). `tasks.priority_id` FK.
- **Statuses/workflow:** per-project `task_statuses`, seeded defaults, categories backlog/todo/in_progress/in_review/done drive board columns + `completed_at`.
- **Dependencies:** `blocks`/`related_to`; cycle-checked on create; **hard block** — server rejects move-to-Done while blocked by open tasks (422).
- **Work logs:** duration recomputed from `started_at`/`ended_at`; **overlaps rejected** (create + update, 422).
- **Realtime:** Laravel Reverb + Echo (pusher-js). Channels `private-project.{id}`, `private-user.{id}`, `private-workspace.{id}`. Channel auth = same membership checks. Eents queued; queue worker + reverb in `composer run dev`.
- **Task keys:** atomic per-project `last_task_sequence` (`lockForUpdate`) → `WEB-101`.
- **Audit:** append-only polymorphic `activities` table, actor + before/after JSON.
- **Search:** LIKE-based filters v1 (SQLite); FTS5 noted as upgrade.
- **Uploads:** validated mime/size, randomized names, `storage/app/public/tasks/{tenant}/{task}`, signed temp URLs, storage-disk abstraction (S3 later).

---

## Completed (pre-task-management, verified)

- [x] Multi-tenant core backend: tenants tables, models, TenantContext singleton, TenantScoped global scope
- [x] Middleware chain `auth → tenant → super_admin/permission`; Gate bypass for super admin (unless impersonating)
- [x] RBAC: `config/permissions.php` (9 perms), roles admin/editor/viewer, idempotent TenantProvisioner, TenantSeeder (superadmin + Acme + Globex)
- [x] Auth (session, no register), Tenant/Impersonation/User/Role controllers, routes
- [x] SPA multi-tenant: AuthContext (`can()`, `stopImpersonation`, `refresh`), Tenants page, ImpersonationBanner + offsets, Users/Roles UI, register removed
- [x] Tests: 21 tests / 81 assertions (Auth, Permission, Theme, Tenant) passing · pint clean · build clean
- [x] AGENTS.md reference file created

**Phase 0 gate verified:** 40 tests / 139 assertions passing (Auth, Permission, Theme, Tenant, + Domain Provisioning, TenantContextMiddleware, DomainModelScoping, DomainServices, BroadcastingChannelAuth) · `pint` clean · `npm run build` clean · `migrate:fresh --seed` + `tenants:provision` idempotent · Reverb up + queued broadcast verified end-to-end (`composer run dev` now includes `reverb:start`).

---

## Phase 0 — Foundation

- [x] Migrations: workspaces, workspace_members, project_roles, projects, project_members, task_statuses, priorities, tasks, labels, task_label, task_dependencies, comments, attachments, work_logs, activities, notifications
- [x] Models + relations + enums (status category); `TenantScoped` on domain models (defense-in-depth)
- [x] `config/project_roles.php` (lead/developer/viewer permission lists) + `config/priorities.php` (defaults)
- [x] `config/permissions.php`: add `workspaces.view|create|manage`; update editor/viewer role defs
- [x] Provisioner backfill: perms+priorities+project_roles for existing tenants; ensure admin `*` re-sync; `tenants:provision` command
- [x] `tenant_context` middleware (403 unless TenantContext::hasTenant()); register alias + apply to domain-routes group
- [x] Reverb/Echo base: composer deps, broadcast/services env, channels.php w/ policy-backed auth, bootstrap.js Echo client
- [x] `composer run dev` adds `reverb:start`; npm deps laravel-echo, pusher-js, @dnd-kit
- [x] Service shells: ActivityLogger, NotificationService, KeyGenerator
- [x] Gate: test + pint + build green; AGENTS.md updated

## Phase 1 — Workspaces

- [x] WorkspaceService CRUD; WorkspaceController + requests
- [x] WorkspacePolicy (membership owner/admin/member); WorkspaceMemberController (invite users, change role, remove)
- [x] Labels: LabelController + policy; unique per workspace
- [x] Routes wired into tenant_scope group
- [x] Tests: workspace CRUD, membership gating, cross-tenant isolation, labels
- [x] Frontend: `/workspaces` list + `/workspaces/:wid` detail (members, labels, settings); Sidebar "Workspaces" nav (permission)
- [x] Gate: tests + pint + build; AGENTS.md updated

**Phase 1 gate verified:** 78 tests / 252 assertions passing · `pint` clean · `npm run build` clean · workspace/label routes live under `auth → tenant → tenant_context → permission:workspaces.view` group · super-admin-unless-impersonating matrix covered on domain routes.

## Phase 2 — Projects

- [x] ProjectService (create seeds default statuses + lead membership); ProjectController + requests
- [x] Project key/sequence; ProjectPolicy; ProjectMemberController (assign project_role per member)
- [x] ProjectRoleController (list + custom roles CRUD); StatusController (rename/reorder/recolor/add; delete constrained)
- [x] Routes/tests: project CRUD, member roles, role gating (lead/developer/viewer), status lifecycle
- [x] Frontend: project list + create in WorkspaceDetail, project hub `/projects/:pid` (overview/members/workflow/settings tabs), member + workflow managers
- [x] Global `/projects` page + sidebar Projects tab (`GET /api/projects`); breadcrumbs (account → workspace → project)
- [x] Gate: tests + pint + build; AGENTS.md updated

**Phase 2 gate verified:** 119 tests / 408 assertions passing · `pint` clean · `npm run build` clean · project/member/status/project-role routes live in the `tenant_context` group (`workspaces.view` gate; role mutations under `roles.manage`) · policies gate per-project via project-role permissions, with cross-tenant 404s.

## Phase 3 — Tasks Core

- [x] TaskService: CRUD, atomic key generation (`[key, sequence]` tuple), subtasks (parent_id), position (decimal, renumbered on move), filters/sort/eager-loads
- [x] TaskController + TaskPolicy (project-role gating: view/edit/delete/assign/move); `ProjectPolicy::createTask` for project-scoped create; `update` requires `assign` when `assignee_id` present
- [x] TaskMoveController: status+index move, recompute source + destination positions, **hard-block on Done when blocked** (422 `form`), broadcast `TaskSynced('moved')`
- [x] Board/list API w/ filters (status_id, priority_id, assignee_id, label_id, q, due_from/to) + response `filters` payload + `my_role` (dashboard query deferred to Phase 7)
- [x] Tests: task CRUD, key uniqueness/atomicity, movement + positions, hard-block rejection, filters, permission matrix, isolation (25 tests in `tests/Feature/TaskTest.php`)
- [x] Frontend: KanbanBoard (dnd-kit) + TaskTable + FiltersBar, CreateTaskModal, TaskDetail property drawer (status/priority/assignee/labels/due/estimate/subtask)
- [x] Realtime board sync via `private-project.{id}` `.task.synced` (create/update/move/delete)
- [x] Gate: tests + pint + build; AGENTS.md updated

**Phase 3 gate verified:** 144 tests / 528 assertions passing · `pint` clean · `npm run build` clean · task routes live in the `tenant_context` group (`workspaces.view` gate) with per-task/project-role policy gating, cross-tenant 404s, and `Event::fake`-verified `TaskSynced` broadcasts.

## Phase 4 — Collaboration

- [x] Comments: CommentController (thread + replies), ownership/role policy (owner OR comments.edit/delete), edited_at/deleted_at; `CommentSynced` broadcast on `private-project.{id}` (`comment.synced`)
- [x] Dependencies: DependencyController, cycle check (BFS) + self/duplicate/cross-project 422s, hard-block integration, `blocked_by`/`blocks` payloads + `open_blockers_count` on task payloads
- [x] Attachments: upload (+ policy, `File::types` validation/max 10MB, signed temp download URL outside auth via `signed` middleware), list, delete (+ file removal)
- [x] Activity timeline: ActivityLogger wired on task create/update/delete/move + comment/dependency/attachment mutations; task + project activity endpoints; deterministic `orderByDesc('id')`
- [x] Frontend: CommentThread (realtime refresh on `comment.synced`), AttachmentList (upload/download/delete), DependencyPanel (add/remove), ActivityFeed; sub-tabs inside the TaskDetail drawer
- [x] Tests: comment CRUD/ownership/broadcast, dependency cycle + hard block + unblock, attachment upload/signed download/validation/policy/delete, activity recording (15 tests in `tests/Feature/CollaborationTest.php`)
- [x] Gate: tests + pint + build; AGENTS.md updated

**Phase 4 gate verified:** 159 tests / 619 assertions passing · `pint` clean · `npm run build` clean · collab endpoints live in the `tenant_context` group (`workspaces.view` gate) with per-task policy gating; signed attachment download is intentionally outside auth (signature = bearer token).

## Phase 5 — Notifications

- [x] Trigger wiring: assigned, comment (incl. `@` mentions), status change, dependency unblocked (work-log trigger deferred to Phase 6 — feature not built yet)
- [x] NotificationService: DB insert + `NotificationSent` → `private-user.{id}`; mark-read + read-all endpoints
- [x] Tests: trigger coverage (Event::fake/assertDispatched), read/unread flow (10 tests in `tests/Feature/NotificationTest.php`)
- [x] Frontend: NotificationContext + bell badge (realtime + poll fallback), toast on new notifications, `/notifications` page
- [x] Gate: tests + pint + build; AGENTS.md updated

**Phase 5 gate verified:** 169 tests / 672 assertions passing · `pint` clean · `npm run build` clean · notification endpoints live in the `auth → tenant` group (self-scoped by `user_id`; no `tenant_context` needed) · `NotificationSent` broadcasts include `actor {id,name}` for client rendering.

## Phase 6 — Time Tracking

- [x] WorkLogService: create/update/delete, duration recompute, **overlap rejection** (create + update), estimate remaining math
- [x] WorkLogController + policy (own logs; `work_logs.manage` project role; tenant admins)
- [x] Time summaries: task / project / workspace aggregation (from/to, group by user/status/date)
- [x] Work-log notification trigger (`task.work_logged` → assignee, skip self) — redeems Phase 5 deferral
- [x] Tests: duration math, overlap rejection, permission matrix, summary aggregation (11 tests in `tests/Feature/WorkLogTest.php`)
- [x] Frontend: WorkLogPanel (form + list), TimeSummary panels, Time tabs in TaskDetail/ProjectDetail/WorkspaceDetail, estimates on task card
- [x] Gate: tests + pint + build; AGENTS.md updated

**Phase 6 gate verified:** 180 tests / 741 assertions passing · `pint` clean · `npm run build` clean · work-log endpoints live in the `workspaces.view` tenant-context group (per-task `WorkLogPolicy` gating; every controller method declares `Project $project` + `Task $task` per the implicit-binding rule) · `task.work_logged` fires on log create only (not edit/delete); activity rows `task.work_logged|work_log_updated|work_log_deleted`.

## Phase 7 — Search & Reporting

- [x] SearchController (`/api/search/tasks`) w/ filters + pagination + tenant-wide `filters` payload; index pass (workspace_id, status_id, assignee_id, due_date, `(project_id, updated_at)`)
- [x] DashboardController `/api/dashboard`: my open/due/recent/in-progress + counts
- [x] Reports overview (`/api/reports/overview`): totals + status/priority/assignee/project distributions
- [x] Tests: search filters + scoping, dashboard counts/scoping, report distributions, permission gates, filter options (8 tests in `tests/Feature/DiscoveryTest.php`)
- [x] Frontend: Dashboard widgets + task lists, Reports (totals + distributions + Time scope picker), global Search page (`/search`) with filters + paginated table, Sidebar "Search" nav, `ProjectDetail` `?tab=` deep links
- [x] Gate: tests + pint + build; AGENTS.md updated

**Phase 7 gate verified:** 188 tests / 805 assertions passing · `pint` clean · `npm run build` clean · discovery endpoints live in `auth → tenant → tenant_context` with per-route permissions (`search/tasks`→`workspaces.view`, `dashboard`→`dashboard.view`, `reports/overview`→`reports.view`) · shared `ScopesVisibleTasks` trait is the canonical visible-tasks scoping for global reads.

## Phase 8 — Hardening

- [x] Performance indexes: task search+FK index pass (workspace_id, status_id, assignee_id, due_date, `(project_id, updated_at)` — Phase 7) + full FK-composite sweep (`000012`). No N+1 in list endpoints: `memberRole()` uses the loaded `members` relation; list services eager-load that relation constrained to the current user; board/list/show/dashboard/reports/search/comment/attachment/work-log payloads verified eager-loaded.
- [x] Soft-delete sweep: `tasks` + `comments` trashed rows 404 via implicit binding + excluded from board/list/search/dashboard/reports/subtasks/comment index by the global scope; locked in `tests/Feature/HardeningTest.php`
- [x] Test sweep: super-admin-unless-impersonating matrix extended to the Phase 7 global reads (403 when not impersonating; tenant-scoped when impersonating); channel-auth callback tests already present (`BroadcastingChannelAuthTest`)
- [x] Docs: AGENTS.md full refresh (architecture, permissions, realtime, deps, test counts) + tracker updated per phase
- [x] Final smoke: `migrate:fresh --seed` + `tenants:provision` idempotent ✓; `composer run dev` script verified (serve + queue + pail + vite + reverb); `npm run build` clean

**Phase 8 gate verified:** 195 tests / 839 assertions passing · `pint` clean · `npm run build` clean · fresh seed + provision idempotent · all 8 phases complete.

## Phase 9 — Global Search & UI/UX

- [x] UI foundation: CSS tokens (`--shadow-card/popover/drawer`), `.board-scroll`, reduced-motion guard; `components/ui/` primitives (`Select`, `Modal`, `Drawer`, `EmptyState`, `Avatar`, `Spinner`, `fieldStyles.js`) + enriched `Button`/`Input`/`Card`; per-page duplicate select/input class consts consolidated onto `fieldClass`/`fieldClassCompact`
- [x] Sidebar redesign: collapsible `w-64 ↔ w-16` (lg+) persisted via `flowsync.sidebar.collapsed`, sectioned nav (Main/Insights/Administration), active pill + accent bar, CSS hover, footer collapse toggle
- [x] Toast modernize: compact tinted card, no progress bar, `warning` type added, z-index/dismiss/stack fixes
- [x] Kanban fix: single-row horizontal scroll (`board-scroll`) so Done column no longer drops below Backlog
- [x] Drawer-based TaskDetail: shared `Drawer` (footer Delete/Save via `form=`), meta-rail layout (sm:grid-cols-3), preserved collab sub-tabs
- [x] Global search backend: `GET api/search/global` (`GlobalSearchController`) in `auth → tenant` group + `permission:workspaces.view`, outside `tenant_context` so non-impersonating super admins cross tenants (controller pins `TenantContext` null internally); 4 result groups (tasks w/ 8-cap + `userManagesAllTasks()` bypass, projects, workspaces, users gated `users.view`); 7 new tests in `tests/Feature/GlobalSearchTest.php`
- [x] Command palette frontend: `components/search/CommandPalette.jsx`, ⌘K/Ctrl+K + Topbar trigger gated `workspaces.view`, 250ms-debounced fetch (AbortController), grouped results + keyboard nav; task deep-link `?tab=tasks&task=KEY` auto-opens the drawer then strips the query
- [x] Docs: AGENTS.md "Global Search & UI/UX (Phase 9)" section + test-count bump

**Phase 9 gate verified:** 202 tests / 870 assertions passing · `npm run build` clean · full redesign live (sidebar, toasts, kanban, drawer, global search) · all 9 phases complete.

## Phase 10 — Scale Seed Data & Deep Links
- [x] `database/seeders/ScaleDataSeeder.php` — configurable-scale realistic test data: `run(tenants=100, usersPerTenant=10, workspacesPerTenant=5, projectsPerWorkspace=5, tasksPerProject=100, related=true)`. Creates 1 super admin, tenants `tenant-{NNN}`, owner `owner@{slug}.test` (admin, from provisioner) + extras, workspaces `w1..wN` with members, projects keyed `{last3}-{w}-{p}` with 5 default statuses + lead/developer/viewer members, exactly `tasksPerProject` tasks per project (bulk `DB::table` inserts w/ string timestamps; ids captured via `lastInsertId()` arithmetic) distributed `STATUS_WEIGHTS=[15,20,30,15,20]` with cycling priorities/assignees, due dates, estimates, `completed_at`; related comments, work logs, and `task.assigned` notifications (data payload incl. `task_id/key/title/project_id/project_name/workspace_id`).
- [x] `app/Console/Commands/SeedScaleData.php` — `php artisan tenants:seed-scale [--tenants=100] [--users=10] [--workspaces=5] [--projects=5] [--tasks=100] [--no-related]`.
- [x] `tests/Feature/ScaleDataSeederTest.php` — 6 tests (counts, relationship consistency, distribution, done semantics, tenant isolation, exact-100 default) · note: `$this->seed()` doesn't accept params → tests invoke `app(ScaleDataSeeder::class)->run(...)` directly.
- [x] Deep links — every workspace/project/task/notification has a unique SPA URL: workspace `/workspaces/{id}`, project `/projects/{id}`, task `/projects/{id}?tab=tasks&task={key}[&section=comments|attachments|dependencies|time|activity]`. `notificationHref(data, type)` is type-aware (`task.commented`→comments, `task.work_logged`→time); `ProjectDetail` keeps the query string (refresh/direct-tab safe), forces board view, auto-opens the drawer via `openedDeepTaskRef` guard, forces `TaskDetail`'s sub-tab via `openTask(task, section)`; `ProtectedRoute` preserves `pathname + search` through the login redirect.
- [x] Access denied: `ProjectController::show` returns 403 for non-members (frontend `ProjectDetail`/`WorkspaceDetail` catch 403 → navigate `/403`); cross-tenant deep links 404.
- [x] `tests/Feature/DeepLinkAccessTest.php` — 4 tests: project/workspace/task 403 for non-members, cross-tenant 404 + nothing leaked, notification payload carries deep-link fields.
- [x] **Phase 10 gate verified:** 212 tests / 1956 assertions passing · `pint` clean · `npm run build` clean · scale seeder smoke-tested on a scratch SQLite DB (counts verified) · all 10 phases complete.

## Phase 11+ — Multi-tenant SaaS architecture (planning)
- [x] `docs/multi-tenancy-architecture.md` written: current-architecture analysis, target design
  (PostgreSQL **one database per tenant** + central system DB), expanded tenant model + lifecycle
  state machine, subscription plans/limits/`subscription_events`, login routing (`tenant_users`),
  `TenantDatabaseManager`/`switch_tenant` middleware + guard, `ProvisionTenantJob` onboarding, Super
  Admin SaaS platform, Jira-like feature expansion map (workspace/project/task/issue), security,
  testing strategy (Postgres + `docker-compose`), phased plan P11–P17. **Decision: implement later.**
- [x] AGENTS.md "Multi-tenant SaaS architecture (Phase 11, proposed)" section added.
- [x] **Phase 11 gate verified:** 229 tests / 2035 assertions passing · `pint` clean · `npm run build`
  clean · foundations shipped (see AGENTS.md): `docker-compose.yml`, `system`/`tenant` pg connections +
  `config/tenancy.php` (`TENANCY_DRIVER=shared|isolated`), `TenantDatabaseManager`, tenants expansion
  `000013` (status/lifecycle/provisioning/encrypted DB creds/json + soft deletes), platform RBAC +
  `audit_logs` `000014`, `TenantLifecycle` state machine, models `PlatformRole`/`PlatformPermission`/
  `AuditLog`. New tests: `TenantLifecycleTest` (8) + `TenantDatabaseManagerTest` (9). Existing suite
  stays green on shared SQLite — no behavior change yet.

## Phase 12 — Multi-tenant provisioning & routing
- [x] Migration `2026_09_23_000015`: central `tenant_users` (`TenantUserRouting`: normalized `email`,
  `tenant_id` FK, `user_id`-in-tenant-DB, `name`; unique `(tenant_id, email)`) + `provisioning_runs`
  (per-attempt run/status/step/error/timestamps).
- [x] `CentralConnection` trait (`getConnectionName()` → `centralConnectionName()`) applied to `Tenant`,
  `ImpersonationLog`, `AuditLog`, `PlatformRole`, `PlatformPermission`, `TenantUserRouting`,
  `ProvisioningRun`; `TenantScoped` no-ops in isolated mode (tenant `tenant_id` cols hold local ids).
- [x] `config/tenancy.php` `tenant.driver` (env `TENANT_DB_DRIVER`, default `pgsql`; `sqlite` = local/
  dev/test fast-path via file paths under `tenant.db_path`), PG paths behind same code.
- [x] `TenantDatabaseManager`: `centralConnectionName/tenantDriver/tenantDatabasePath/createDatabase/
  migrateTenant` + safe `switchDefault()` (purges target only when ≠ current default).
- [x] `TenantProvisioner::provisionIsolated()` idempotent pipeline (connect → createDatabase → migrate →
  local `tenants` row → seed → owner `createAdmin` → `syncRouting` → trial/active; skips re-transition
  when already serviceable+provisioned).
- [x] `ProvisionTenantJob` (`tries=1`, no auto-retry, repair via `tenants:provision`): records
  `provisioning_runs`, transitions to provisioning, failure → `provisioning_failed` + error.
- [x] `SwitchTenant` middleware (`switch_tenant` alias) at the head of both authenticated route groups;
  resolves per-tenant connection from session `login.tenant_id`/`impersonate.tenant_id`. `SetTenantContext`
  isolated branch. `AuthController::loginIsolated()` via `TenantUserRouting` (+ `tenant` slug disambiguator
  → 422 when >1 route without slug; super admins fall back to system DB). **Gotcha fixed:** `tenant` slug
  leaked into `Auth::attempt` credentials → `where tenant = ?` → strip via `Arr::except` (see AGENTS.md).
- [x] `ImpersonationController::startIsolated` (routing-based resolve), stop → system connect (no
  `exists:users,id` rule on isolated). `TenantController` isolated branches: store → pending + job (202);
  `users()`/`counts()`/`index()` read via routing. `ProvisionTenants` repairs isolated tenants.
- [x] New tests: `TenantUserRoutingTest` (6) + `tests/Feature/Isolated/IsolatedProvisioningTest.php`
  (6, file-backed `iso_system` + sqlite tenant files: pipeline, idempotency, failed+retry, tenant login,
  ambiguous email slug, super-admin impersonation).
- [x] **Phase 12 gate verified:** 241 tests / 2101 assertions passing · `pint` clean · `npm run build`
  unchanged (no frontend work) · all 12 phases complete (shared suite untouched, PG primitives written
  but untested here — no Docker/PG superuser available; sqlite fast-path validated instead).

## Phase 13 — Isolation cutover (per §11, §13 of the architecture doc)
- [ ] Domain models: default connection (no tenant pinning); delete `TenantScoped` trait + all
      `withoutTenantScope()` calls + `tenant_id` references in models/services/seeders/policies/tests
- [ ] Rewrite migrations so the tenant DB schema is the domain schema **minus `tenant_id` columns**;
      restore global-within-DB uniqueness (`users.email`, `roles.slug`, `permissions.slug`,
      `workspaces.slug`, `projects.key`, `task_statuses(project_id,slug)`, `tasks(project_id,key|sequence)`,
      `labels(workspace_id,name)`); plain FKs restored (no `(tenant_id, x)` composites)
- [ ] Split migration set: central tables (`tenants`, `tenant_users`, `subscriptions…`, `impersonation_logs`,
      infra, platform RBAC) apply only to the `system` DB; domain tables apply only to tenant DBs
      (tenant DB no longer carries central-table clutter — pre-cutover note removed)
- [ ] Drop `TENANCY_DRIVER=shared` code path (row-scoping branch of `TenantScoped`, shared
      `TenantContext` mutation, shared login branch, shared `TenantProvisioner::provision()`), so the app
      is isolated-only; `TenantDatabaseManager` connects `system`/tenant unconditionally
- [ ] `AuthController`/`SetTenantContext`/`SwitchTenant`/`ImpersonationController`/`TenantController`
      drop their shared branches
- [ ] Rewrite `TenantSeeder` + `ScaleDataSeeder` to provision real tenants through the onboarding
      pipeline and seed **inside** the tenant DB on the tenant connection (same bulk-insert logic,
      no `tenant_id` columns)
- [ ] Port the full test suite to isolated mode (sqlite-file tenant fast-path for dev/CI; pgsql profile
      behind `docker-compose.yml` when a PG is reachable): all feature tests authenticated via
      `switch_tenant → auth → tenant…`; `TenantScoped`-dependent assertions removed
- [ ] `phpunit.xml`/CI: `TENANCY_DRIVER`/`TENANT_DB_DRIVER` envs drive isolated runs; dedicated
      `iso_system` file-backed connection pattern kept
- [ ] Gate: full suite green on isolated sqlite · `pint` · `npm run build` · AGENTS.md + tracker updated

## Phase 14 — Subscriptions (per §5.3, §7.2, §8)
- [ ] Migrations (system): `subscription_plans` (name/slug/description/is_active/is_default/billing_cycle/
      price_cents/currency/trial_duration_days/limits jsonb/sort_order), `subscriptions`
      (tenant_id/plan_id/status/current_period_*/trial_ends_at/canceled_at/auto_renew/seats/
      billing_provider/billing_reference — one active per tenant), `subscription_events`
      (type/from_plan_id/to_plan_id/data/actor_id); `tenants.subscription_id` FK lands here
- [ ] `config/subscriptions.php` machine-readable catalog (modules: time_tracking/reports/global_search/
      api/branding/audit_export; numeric limits: users/seats/workspaces/projects/tasks/storage_bytes/
      attachments_per_task)
- [ ] Models `SubscriptionPlan`/`Subscription`/`SubscriptionEvent` (`CentralConnection`/system);
      relationships + `Subscription::isActive()`, effective-limits accessors
- [ ] Plan seeding (default plans from the catalog; is_default)
- [ ] `PlanController` (super_admin, system scope) CRUD + `routes/web.php` platform group
- [ ] `TenantSubscriptionController`: assign plan / start trial / switch plan (writes `subscription_events`,
      re-stamps subscription) / cancel / renew / suspend
- [ ] `TenantLimits` service: `effective(Tenant)` = plan.limits ⊕ `tenants.limits_override` (cached by
      tenant+plan version); `assertQuota()` consulted by `UserController::store`, `WorkspaceService::create`,
      `ProjectService::create`, `TaskService::create`/bulk create → 422 with friendly message; feature-gate
      helper `hasModule(tenant, module)`
- [ ] Onboarding wiring: `TenantController::store` accepts plan_id/trial/billing/contact (validated);
      `ProvisionTenantJob` step 6 creates the trial subscription → `status=trial|active`
- [ ] Frontend: Plans page (`/plans`, super admin), subscription assignment surfaced in the Onboarding flow
- [ ] Tests: plan CRUD, limit enforcement (over-limit create → 422), subscription events on switch,
      trial activation in the isolated pipeline
- [ ] Gate: tests · pint · build

## Phase 15 — Usage & modules (per §8.3, §5.3)
- [ ] Storage limits: `AttachmentController::store` checks `storage_bytes` (tally existing attachment bytes
      via disk/DB) → 422 when over
- [ ] Module gates (`hasModule`): work-log/time routes require `time_tracking`; reports/dashboard rework to
      require `reports`; global search `api`/`global_search` gates as configured
- [ ] `usage_metrics` table (tenant_id/metric_key/period/value) + `tenants:collect-usage` scheduled command
      (fan-out lightweight per-tenant queries → aggregates; storage bytes tallied same pass)
- [ ] Downgrade grace: per-limit hard vs warn config; warn path recorded without blocking
- [ ] Tests: storage limit, module gate 403s, usage collector aggregates
- [ ] Gate: tests · pint · build

## Phase 16 — Super Admin platform (per §9)
- [ ] `GET /api/tenants` expand: q/status/plan filters + counts + subscription + provisioning
- [ ] `GET /api/tenants/{id}` details (counts, subscription, usage, provisioning)
- [ ] Lifecycle endpoints `POST /api/tenants/{id}/suspend|reactivate|deactivate` (`TenantLifecycle`)
- [ ] `GET /api/platform/usage` (users/workspaces/projects/tasks/storage by plan & tenant)
- [ ] `GET /api/platform/health` (system DB + sampled per-tenant DB connectivity)
- [ ] `GET /api/platform/audit-logs` (system audit + tenant_activity)
- [ ] `GET/PUT /api/platform/features` (global default toggles)
- [ ] Global search fan-out across tenant DBs concurrently (limit/merge top-N); super admin central pin kept
- [ ] Tenant drill-down: read-only fan-out inspection of workspace/project/task/activity on the target DB
- [ ] Frontend platform section: Tenants onboarding expansion (status polling, activity timeline), Plans,
      usage dashboard, health, audit, features, drill-down
- [ ] Tests: platform endpoints, fan-out search, drill-down; gate

## Phase 17 — Feature expansion (per §10; custom fields deferred to their own phase)
- [ ] Workspaces: add `description/icon/color/timezone/working_hours/default_assignee_id/settings jsonb`;
      workspace-level activity feed (`activities` subject=`workspace`)
- [ ] Projects: `description/icon/color/default_assignee/notification config jsonb`; `project_components`
      (name/lead/description); `project_versions` (name/released/release_date/description); `issue_types`
      (tenant or project catalog: story/task/bug/epic, `is_subtask`, icon, default status/priority;
      `tasks.issue_type_id` FK) seeded from `config/issue_types.php`
- [ ] Tasks: `start_date`, `story_points decimal`, `component_ids` pivot, watchers (`task_watchers`
      + `task.watched` notifications on comments/status), extend `task_dependencies.type` enum
      (`relates|duplicates|blocks→clones`)
- [ ] Per-feature tests + gate

---

## Dev commands / verification per phase

```bash
php artisan test                 # suite
./vendor/bin/pint                # PHP code style
npm run build                    # SPA build
composer run dev                 # serve + queue + pail + vite + reverb
php artisan migrate:fresh --seed # reset + seed (idempotent provisioner)
php artisan tenants:provision    # backfill perms/priorities/project_roles for existing tenants
php artisan tenants:seed-scale   # large-scale realistic seed (default 100 tenants / 100 tasks per project)
```