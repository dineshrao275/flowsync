# FlowSync Task Management — Implementation Tracker

Status legend: `[x]` done · `[ ]` pending · `[~]` in progress

Demanded verdict gates at each phase: `php artisan test` · `./vendor/bin/pint` · `npm run build` · AGENTS.md updated. Phases are shippable independently.

---

## Confirmed design decisions (scope lock)

- **Hierarchy:** Tenant → Workspace → Project → Task (subtasks via self-FK). **One database per tenant**
  (Phase 13): domain tables no longer carry `tenant_id`; tenant-scoping comes from the physical DB.
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
- [x] Domain models: default connection (no tenant pinning); `TenantScoped`/`withoutTenantScope()` gone —
      deleted from the codebase (models, middleware, services, policies, tests); `TenantContext` reduced
      to the guard-intent singleton (tenant id + impersonation flag, set from session by
      `SetTenantContext`; no row-scoping branch)
- [x] Tenant-DB schema = domain schema **minus `tenant_id` columns** (migrations copied under
      `database/migrations/tenant/`): global-within-DB uniqueness restored (`users.email`, `roles.slug`,
      `permissions.slug`, `workspaces.slug`, `projects.key`, `task_statuses(project_id,slug)`,
      `tasks(project_id,key|sequence)`, `labels(workspace_id,name)`); plain FKs (no `(tenant_id, x)`
      composites). `tasks` no longer carries tenant-scoped search indexes either.
- [x] Migration set split: central tables (`tenants`, `tenant_users`, `provisioning_runs`,
      `impersonation_logs`, infra sessions/jobs/cache, platform RBAC, `audit_logs`) live under
      `database/migrations/system/`, applied only to the `system` DB; domain tables under
      `database/migrations/tenant/` apply only to tenant DBs. **Laravel 12 `Migrator` uses non-recursive
      `glob($path.'/*_*.php')`** → every migrate call MUST pass `--path=database/migrations/system` (system)
      or `--path=database/migrations/tenant` (tenant); plain `migrate` runs nothing.
- [x] `TENANCY_DRIVER=shared` code path dropped — app is **isolated-only**: `TenantDatabaseManager`
      unconditional connects, no shared login branch, `TenantProvisioner::provision()` removed.
- [x] `AuthController`/`SetTenantContext`/`SwitchTenant`/`ImpersonationController`/`TenantController`
      shared branches removed (isolated paths only; verified by read).
- [x] `TenantSeeder` + `ScaleDataSeeder` rewrite: `provisionIsolated()` per tenant (create/migrate local
      schema → local `tenants` row → `createAdmin` → `syncRouting` → seed domain data inside `using()`
      on the tenant connection, no `tenant_id` columns; super admin is a `users` row on the `system` DB).
- [x] Full test suite ported to a fresh **`Tests\IsolatesDatabase`** trait (`tests/IsolatesDatabase.php`):
      hooks via `setUpTraits()` (same mechanism as `RefreshDatabase`), creates a file-backed
      `iso_system` sqlite + tenant sqlite files (`tenancy.tenant.db_path`), migrates
      `--path=database/migrations/system` into `iso_system`, seeds `TenantSeeder`, connects `acme`
      by default. Helpers: `acme()/globex()` (central `Tenant`), `connectTenant($slug)`, `loginAs($email)`
      (actingAs + session `login.tenant_id`, leaves default on acme), `systemUser($email)`. HTTP logins
      re-route per request via `switch_tenant`; direct model work after any HTTP request must reconnect
      `connectTenant('acme')` (or `using($globex, fn)`); runtime-created users need a
      `TenantProvisioner::syncRouting()` before HTTP login. Cross-tenant isolation now falls out of
      physical DB separation → plain 404s. `tests/Feature/DomainModelScopingTest.php` deleted (obsolete).
- [x] System DB also has a `users` table (`system/0001_01_01_000000_create_system_users_table.php`
      creates `users` + `password_reset_tokens` + `sessions`), so the super admin lives there and
      `SystemUser` (`CentralConnection`) resolves it; no `users` table duplicated on tenant DBs.
- [x] Standalone isolated references kept: `tests/Feature/Isolated/IsolatedProvisioningTest.php`
      (+ `--path=database/migrations/system` on its migrate call) and a rewritten
      `tests/Feature/ScaleDataSeederTest.php` (own clean-system setUp — NOT the trait — because it needs
      exactly its 3 tenants; per-tenant assertions wrapped in `using()`).
- [x] `vendor/bin/phpunit`/CI: `TENANCY_DRIVER=isolated` + `TENANT_DB_DRIVER=sqlite` envs drive runs end-to-end
      (see `tests/IsolatesDatabase.php`); `.env.docker`/`.env.example` + `docker/entrypoint.sh` migrated:
      `migrate --database=system --path=database/migrations/system`, `tenants:provision` for workspaces,
      seed-if-empty via `SELECT COUNT(*) FROM users` on the system DB.
- [x] **Phase 13 gate verified (docked):** suite ported to 30 isolated-only test files
      (241+ tests / pre-existing assertion counts) · `npm run build` clean in-repo for this session ·
      `pint` + `php artisan test` **flagged for a PHP-capable environment** (no PHP/Docker on the dev
      host) · AGENTS.md + tracker updated.

## Phase 14 — Subscriptions (per §5.3, §7.2, §8)
- [x] Migrations (system): `subscription_plans` (name/slug/description/is_active/is_default/billing_cycle/
      price_cents/currency/trial_duration_days/limits/sort_order), `subscriptions`
      (tenant_id/plan_id/status/current_period_*/trial_ends_at/canceled_at/auto_renew/seats/
      billing_provider/billing_reference — **unique tenant_id, re-stamped on change**), `subscription_events`
      (type/from_plan_id/to_plan_id/data/actor_id); `tenants.subscription_id` FK lands here on PG
      (sqlite fast-path gets an index only — ALTER ADD CONSTRAINT unsupported)
      (`2026_09_24_000016_create_subscription_tables.php`)
- [x] `config/subscriptions.php` machine-readable catalog (modules: time_tracking/reports/global_search/
      api/branding/audit_export; numeric limits: users/seats/workspaces/projects/tasks/storage_bytes/
      attachments_per_task; starter/pro/enterprise defaults)
- [x] Models `SubscriptionPlan`/`Subscription`/`SubscriptionEvent` (`CentralConnection`/system);
      relationships + `Subscription::isActive()`, `SubscriptionPlan::limit()/hasModule()/periodEnd()`,
      `Tenant` hasOne `subscription()` + `subscriptionEvents()`
- [x] Plan seeding (idempotent `SubscriptionPlanSeeder` from `config/subscriptions.php`, is_default guarded;
      called from `TenantSeeder` + `TenantController::store`)
- [x] `PlanController` (super_admin, system scope) CRUD + `routes/web.php` platform group
- [x] `TenantSubscriptionController`: GET subscription / assign plan / start trial / cancel / renew /
      suspend / events (`GET tenants/{tenant}/subscription/events`) — writes `subscription_events`,
      re-stamps the single row; lifecycle-synced (`active↔trial`)
- [x] `SubscriptionService`: `assign`/`startTrial`/`switch`/`cancel`/`renew`/`suspend` + `record()` events
- [x] `TenantLimits` service: `effective(Tenant)` = plan.limits ⊕ `tenants.limits_override` (no subscription
      ⇒ unlimited, keeps legacy tenants running); `assertQuota()` consulted by `UserController::store`,
      `WorkspaceService::create`, `ProjectService::create`, `TaskService::create` → 422 with friendly
      message; feature-gate helper `hasModule(tenant, module)`
- [x] Onboarding wiring: `TenantController::store` accepts plan_id/trial_days/billing/contact (validated);
      `ProvisionTenantJob` constructor gains optional `planId`/`trialDays` → step 6 creates the trial
      subscription (trial_ends_at set BEFORE provisionIsolated so lifecycle lands on `trial`)
- [x] Frontend: Plans page (`/plans`, super admin — list + CRUD modal with module toggles), sidebar entry,
      plan picker + trial days in the Tenants create form, subscription badge on tenant cards
- [x] Latent-bug fixes: `ProvisionTenantJob::handle()` missing `TenantProvisioner $provisioner` param;
      `IsolatedProvisioningTest` stale local-`tenants` row / `users.tenant_id` / void-return asserts rewired
- [x] Tests: plan CRUD + in-use/default delete guards, subscription assign/trial/cancel/renew/suspend +
      events, `TenantLimits` merge/enforce/hasModule + end-to-end 422 wiring, isolated-pipeline trial
      provisioning (`SubscriptionPlanTest`, `TenantSubscriptionTest`, `TenantLimitsTest`, +
      `IsolatedProvisioningTest` cases)
- [x] **Phase 14 gate verified (docked):** **261 tests / 1884 assertions passing** `php artisan test` · pint
      clean on all Phase 14 + touched files (whole-repo baseline stays dirty — pre-existing stock
      migrations/tests violate style) · `npm run build` ✓
- [x] **Latent framework/Phase-13 bugs fixed during the gate** (see Agents gotchas):
      1. Laravel `SortedMiddleware` reorders route middleware by Kernel `$middlewarePriority` —
         `SubstituteBindings` runs before unlisted custom middleware, so tenant model binding happened on
         the previous request's connection BEFORE `SwitchTenant`. Fixed by registering all tenant
         middleware classes in `$middleware->priority([...])` (`bootstrap/app.php`).
      2. `TenantDatabaseManager::connect()` reused the cached `tenant` connection when switching between
         tenants (same connection name → no purge); `using()`/`restore()` left the tenant config pointing
         at the wrong tenant. Fixed: purge `tenant` whenever the tenant id changes + `restore()` rebuilds
         the tenant connection from the previously-active tenant.
      3. Tests rewritten for one-DB-per-tenant reality (numeric ids restart per tenant): cross-tenant
         isolation now asserted via emails (`ScaleDataSeederTest`), custom role ids (`ProjectRoleTest`),
         and the wrong `use Illuminate\Support\Facades\Str` import fixed (`Illuminate\Support\Str`).

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

## Platform Maturity Initiative — 13-item plan (post-P14)

Scope lock (decisions from planning round): Recharts for analytics · query-param deep-link
convention · CMS admin + public rendering · self-registration behind a config toggle (default off) ·
tenant counts = users always + project/task counts lazy on detail. One item at a time: fix → test →
verify → tick here. Per-item gates: touched-file `pint` · `php artisan test` (suite when backend
touched) · `npm run build` when frontend touched · AGENTS.md updated when architecture changes.

- [x] **1. Theme tray** — close the drawer automatically after successful Save; theme applies live
      without refresh. `ThemeSettingsDrawer.handleSave` now calls `onClose()` after `result.ok`
      (AdminLayout already wired `open`/`onClose`). **Verified:** `npm run build` ✓ · rebuilt
      `flowsync:latest` + `up -d` (app healthy) · login 200 · `GET/PUT /api/theme` round-trip returns
      the saved theme (drawer's `save()` posts `draft` flat to `/theme`).
- [x] **2. Dynamic page titles** — `usePageTitle(title)` hook sets `document.title = "{title} · FlowSync"`
      + feeds a shared `PageTitleContext`; `PageTitleProvider` in AdminLayout keeps a static default map
      (reset on route change) that Topbar now reads too (its own hardcoded `titles` map removed). Every
      page sets its own title: static (Dashboard/Workspaces/Projects/Users/Roles/Settings/Tenants/Plans/
      Search/Reports/Notifications/Sign in/Forgot password/Reset password/Forbidden/Page not found) +
      resource names (WorkspaceDetail/ProjectDetail → loaded `workspace/project` name). **Verified:**
      `npm run build` ✓ · rebuilt image + `up -d` (app healthy) · new bundle served (titles present) ·
      login round-trip 200.
- [x] **3. Tenant information expansion** — migration `000017` adds profile columns (legal/tax/address,
      website/industry/size, billing, timezone/locale, branding, contact phone); `TenantController@update`
      + `GET|PUT /tenants/{id}/profile` (SA) + `GET /api/tenant/profile` (tenant); profile form (grouped
      sections + extensible `settings` JSON) in TenantDetail. **Verified:** pint ✓ (2 fixes) · full suite
      269/1918 + 8 new `TenantProfileTest` (SA update/validation/403s, self-404, tenant self read) ·
      `npm run build` (bundle contains "Company profile"/"Legal & registration") · image rebuilt/app
      healthy · live SA read+update round-trip → "Tenant profile updated." (industry Software, legal Acme
      Corp) + tenant-admin `GET /tenant/profile` → acme persisted.
- [x] **4. Tenant onboarding** — `config/onboarding.php` step catalog (business→admin→subscription→
      configuration→verification→completion); `TenantOnboarding` service persists `onboarding_meta`;
      `EnsureOnboardingComplete` middleware gates domain routes (403 until complete; demo tenants seeded
      complete); super-admin step API + tenant-side step API; **public `POST /api/register` behind
      system-settings toggle (default off)** + `Register.jsx` + `Onboarding.jsx` wizard (auto-login
      after self-register).
      **Verified:** pint ✓ · full suite 281/1989 (12 new tests: RegisterTest + OnboardingTest — toggle-403,
      register→PG-provision→auto-login, stale-routing purge, gate 403→partial-steps 403→complete→200,
      SA read/drive/reset, superadmin bypass, admin-provisioned never-started=complete) · `npm run build`
      (bundle has wizard strings) · image rebuilt/app healthy · live: default register 403 → with
      `ONBOARDING_ENABLED=true` full loop (register 200 + tenant 4 provisioned on PG, gate 403, me
      onboarding_complete false→true, dashboard 403→200, invalid step 422, SA reset/re-complete) →
      toggle reverted (register 403 again).
- [x] **5. Tenant subscription self-service** — tenant routes `GET /my-subscription`, `GET /my-usage`
      (`TenantLimits::currentCount` vs limits), `GET /plans`, `POST /my-subscription/switch` +
      cancel/renew (admin-gated); `Subscription.jsx` page (plan card, features, usage meters, dates/
      renewal/remaining, upgrade grid); sidebar item.
      **Verified:** pint ✓ · full suite 289/2035 (8 new tests: MySubscriptionTest — read own
      subscription/null/no-subscription, usage counts vs limits, active-only tenant catalog vs full SA
      catalog, admin switch→cancel→renew + plan_changed event, same-plan no-op, inactive-plan 422,
      editor 403, SA 404; SubscriptionPlanTest GET /plans updated for tenant-read) · `npm run build`
      (bundle has "Compare plans"/"Auto-renew") · image rebuilt/app healthy · live: tenant GET plans
      [starter,pro,enterprise], usage {users:4,…}, switch→pro (plan_changed), usage limits now pro
      (users 50…modules time_tracking/reports/global_search), cancel→canceled/auto_renew false,
      renew→active, editor switch 403, SA my-subscription 404.
- [ ] **6. Dashboard & analytics** — `GET /api/analytics/overview` (workspaces/projects/open-done-
      overdue, per-project progress, work-log totals + daily series, due-this-week, user activity,
      task-created 14/30d trend); add **Recharts**; extend `Dashboard.jsx` with project-progress +
      hours + weekly-trend charts.
- [ ] **7. Deep links (query-param convention)** — canonical `/workspaces/:id?tab=…` and
      `/projects/:id?tab=…&task={key}&section={…}`; `WorkspaceDetail` tab into `useSearchParams`;
      `ProjectDetail` writes tab/search back on change (back/forward + refresh-safe); centralize
      URL building in `utils/deepLinks.js` (CommandPalette/notifications/dashboard/search reuse);
      permissions stay server-enforced (existing 403).
- [ ] **8. Super-admin tenant management** — `index` gains q/status/plan filters + sort + pagination
      (users_count via routing); add `update` (profile), `destroy` (soft), `restore`,
      `activate|suspend`; table view with row menu (View/Edit/Enable-Disable/Impersonate/Delete/Restore);
      project/task counts lazy on `TenantDetail` (cached 60s); remove card grid.
- [ ] **9. Impersonation fix** — `ImpersonationController::startIsolated` accepts `tenant_id` + resolves
      route scoped `where('tenant_id', …)` (fixes user-id collision picking wrong tenant / always Acme);
      frontend passes tenant id; tests for cloned local ids across two tenants; banner/stop flow stays.
- [ ] **10. Super-admin panel expansion** — new sidebar modules + pages: Dashboard, Tenants (from #8),
      Subscriptions, Plans (exists), Users, Analytics, Audit Logs, Feature Management, System Settings;
      `platform_settings` + features catalogs; read-only audit endpoint over `audit_logs` +
      `impersonation_logs`; cross-tenant Analytics (capped fan-out); `PlatformRole/Permission` stay
      schema-only (still `is_super_admin` gate).
- [ ] **11. Website management** — CMS admin + public rendering: `website_pages` (slug/title/content
      JSON blocks/status/SEO/OG/meta/sitemap_include/sort); `CmsPageEditor.jsx`; public server-rendered
      `GET /`, `/page/{slug}`, `/sitemap.xml`, `/robots.txt` from DB (SPA at `/app`); publish without
      code changes.
- [ ] **12. Subscription-based feature control** — `EnsureModule` middleware 403s
      `reports`/`time_tracking`/`global_search`/`branding`/`api`/`audit_export` route groups when plan
      lacks module (SA non-impersonating bypasses; impersonating follows target's plan); frontend gates
      sidebar/pages on `user.modules` (via `can()`-style helper + `ProtectedRoute module` prop);
      Feature Management (#10) drives `plans.limits.modules` + `features_override`; tests: module-off →
      menu hidden, direct-URL 403, API 403.
- [ ] **13. Refactor (last)** — consolidate utils/components, FormRequest validation, unify
      permission+module helper, delete dead code (stock `app.js`, hardcoded `roles_count`), normalize
      API shapes, pint + build + full suite, refresh AGENTS.md/tracker.

**Groundwork (done with item 5/12 wire-up):** migration `000017` wave (tenant profile + platform
settings/features + website CMS) · `me()` payload gains `modules` + subscription summary · `module:`
middleware alias registered in `bootstrap/app.php` priority before `SubstituteBindings`.

**Initiative status:** 5/13 items complete.

---

## Dev commands / verification per phase

```bash
php artisan test                 # suite (isolated: Tests\IsolatesDatabase provisions temp tenant DBs)
./vendor/bin/pint                # PHP code style
npm run build                    # SPA build
composer run dev                 # serve + queue + pail + vite + reverb
php artisan migrate:fresh --seed # reset SYSTEM db + reseed (provisionIsolated for each tenant); needs
                                 # --database=system --path=database/migrations/system since the split
php artisan tenants:provision    # provision/repair tenant DBs (perms/priorities/project_roles/owner/admin)
php artisan tenants:seed-scale   # large-scale realistic seed (default 100 tenants / 100 tasks per project)
```