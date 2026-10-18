# FlowSync — Multi-Tenant Admin Panel

Laravel 12 + React 19 SPA. Session-based auth **without** Breeze/Fortify/Sanctum. **Phase 13: one database per tenant** (PostgreSQL everywhere incl. this dev host; sqlite files remain the test fast-path) + a central `system` DB. **Phase 14 (subscriptions) shipped code:** plans/subscriptions/events (central), onboarding trials, and plan-limit enforcement — gates pending a PHP-capable env. Vite 7 + Tailwind v4 + axios. See `docs/multi-tenancy-architecture.md`.

**Keep this file current** — update the relevant section whenever changes touch architecture, migrations, middleware, routes, key components, npm/Composer deps, or test counts.

**Next-work roadmap:** `.agents/roadmap/master-roadmap.md` (audit at HEAD, gap register G-58+, tech-debt register, Phase 0-11 task plan). **Phase 0 is done** (route-wrapper fix, open-route authz, role-escalation `GrantCeiling`, env untracked, export route path, app error boundary, stacked-gate test; +18 tests, totals re-verified at the next full run). Continue at Phase 1 / 1A.

## Commands
- **Local run script:** `./scripts/dev.sh docker|local …` (see README "Run locally"). The Docker compose file is a **dev setup**: the repo is bind-mounted into `app`/`queue`/`reverb`/`vite` (`vendor/` and `node_modules/` in named volumes, host `.env` hidden with `/dev/null`, `artisan serve --no-reload` because it otherwise strips the container env), Vite HMR on :5173 via `public/hot`, `DEV_MODE=true` skips config/route caching in `docker/entrypoint.sh`. A `perms` one-shot chowns the named volumes to `DOCKER_UID/GID`.
- **Canonical way to run locally: `./scripts/dev.sh docker up` or `./scripts/dev.sh local setup && ./scripts/dev.sh local start` (see README "Run locally").** The bullets below describe the older host-Apache/systemd setup, still valid on the original dev host.
- **Native host (original dev host, no Docker):** Apache `mod_php` vhost `flowsync.conf` serves
  `public/` on `http://localhost` (`http://localhost/app` = SPA, `/` = public marketing site); PHP 8.3 CLI
  + PostgreSQL 16 (`flowsync`/`secret` @ `flowsync_system`) on the host. Long-running services are
  **systemd user units**: `systemctl --user status flowsync-reverb flowsync-queue` plus the
  `flowsync-scheduler.timer` that runs `php artisan schedule:run` every minute
  (`~/.config/systemd/user/flowsync-{reverb,queue}.service` + `flowsync-scheduler.{service,timer}`,
  logs in `storage/logs/{reverb,queue-worker,scheduler}.log`; `loginctl enable-linger drao` makes
  them survive logout/reboot). The timer is what fires everything in `routes/console.php` — usage
  collection, backups, `tenants:expire-trials`, and the nightly `hrms:* --all` fleet sweeps
  (`php artisan schedule:list` prints the cadence; runbook §2.5). First-time bootstrap:
  `php artisan migrate --database=system --path=database/migrations/system` then
  `php artisan db:seed --database=system --class='Database\Seeders\DatabaseSeeder'` (TenantSeeder provisions
  acme + globex tenant sqlite files under `database/tenants/`). `public/.htaccess` (front controller) must exist
  and `storage` + `bootstrap/cache` must be writable by `www-data`.
- Docker (legacy alternative, removed on this host): `docker-compose build && docker-compose up -d`
  — first boot auto-runs `migrate --database=system --path=database/migrations/system` +
  `tenants:provision` + seeds demo data (superadmin + acme + globex). Reset from scratch:
  `docker-compose down -v` then `up -d` (app entrypoint re-initializes; `RUN_INIT=true` only for `app`).
  No PHP/composer needed on the host — envs in `.env.docker` (gitignored; copy `.env.docker.example` and fill in `APP_KEY`/`REVERB_*`/`DB_PASSWORD`).
- **Run PHP tests with ParaTest: `php artisan test --parallel` (add `--processes=N`; installed via `brianium/paratest`, if `vendor/brianium` is missing run `composer install`). Never run the suite serially and never pass a long single-process run — it exceeds tool time limits.** `php artisan test` (serial) — run test suite (Phase 13: **isolated, per-tenant file DBs** via `Tests\IsolatesDatabase`; current gate: **2226 tests / 13410 assertions on `feat/f0-baseline` (2026-10-18): the full ParaTest run had 8 problems — 7 in the newest slices' own tests/code (SSO fixture ordering, a missing `SavedReport::user()`, SSO settings echoing the encrypted secret, the signature verify page not loading the file path, 6 public/self-scoped routes missing from the authorization audit list), fixed and re-run green; the 8th is `HrmsShellTest::test_every_commit_is_pushed`, which passes once the branch is pushed** — previous gate 2143 tests / 12785 assertions (2026-10-17) — the older figures that follow are historical: **1720 tests / 8815 assertions (full run 2026-10-09; the only failure is the unpushed-commit guard)** — verified by focused feature runs on 2026-10-09 and the P4.10 focused suite (`TaskBoardWipSwimlaneTest`, 9 tests; +9 tests / +53 assertions over the 2026-10-09 full run); TMS expansion completion (H-4/H-5/H-6) adds issue types CRUD (`IssueTypeController` store/update/destroy gated by `workspaces.manage`), task watchers notification fan-out (`NotificationService` status change, comment, and unblock fan-out to watchers + `TaskService::present()` `watchers` emission), and SPA surfacing in `CreateTaskModal`, `TaskDetail`, `FiltersBar`, `TaskCard`, and `TaskTable` with issue types management in `ProjectDetail.jsx` workflow tab (`TmsExpansionTest`); the TMS/HRMS product separation adds `Unit/ProductCatalogTest` (permission→product map, 4 tests), `TenantProductRbacTest` (catalog scoping + grant refusal + product labels, 7 tests) and `ProductAccessTest` (whole-product 403s + `X-Product-Denied`, 5 tests); the audit/security slice adds `AuthAuditTest` (`auth.login`/`auth.login_failed`/`auth.logout` rows across the SA, tenant and registration paths), `PlatformAuditTest` (`PlatformAudit::diff()` before/after key reduction + shared `AuditMask` secret masking), `ExitedUserAuthTest` (`loginIsolated` refuses `exited`/`terminated` employees after credential match — `reason=employee_exited` + 422 — while suspended/rehired/no-record users still sign in) and `AuditFeedTest` (SQL-union pagination of the full trail, `tenant_id` JSON/subject filter, tie ordering, CSV export + `audit.exported`, `(created_at,id)` index); the scheduler phase (§F) adds `ScheduleRegistrationTest` (every `routes/console.php` entry incl. the 10 hrms sweeps + trial expiry, exclusions pinned) and `TrialExpiryTest` (tenant+subscription `expired` transition, audit row, scheduling), and `TenantSubscriptionTest` gained the `seats_changed` from/to case; Phase 7 (TMS expansion) adds `TmsExpansionTest` (workspace/project metadata expansion, project components CRUD with uniqueness, project versions/releases CRUD with uniqueness, issue types catalog & seeding, task start dates, story points, issue type & version associations, component tagging, task watchers lifecycle, and filtering); Phase 6 (HRMS residual behavior & UX) adds `HrmsErrorBoundary` wrapping HRMS hub tabs in `HrmsLayout.jsx` with automatic route-keyed reset, onboarding redirection (`EnsureOnboardingComplete.php` web 302 redirect & API 403 with `X-Onboarding-Redirect` header + `api.js` interceptor), and work-log attendance settings (`attendance.auto_derive_from_work_logs` in `AttendanceSettingsRequest`, `GET /api/hrms/attendance/settings` in `AttendanceController`, and admin settings modal in `Attendance.jsx`); Phase 5 follow-ups (performance & scale) adds `PerformanceFollowupsTest` (board column limits & single-pass status scans, notification incremental fetch via `?after=id` cursor with slower Echo fallback polling, configurable category search caps in `GlobalSearchController`, and scale seeder activity backfill in `ScaleDataSeeder`); Phase 4 (production readiness & operations) adds `PlatformHealthTest` (public health ping `/api/health` + super admin detailed inspection `/api/platform/health` with central DB and sampled tenant DB latencies), `TenantBackupTest` (`tenants:backup` system & tenant DB snapshots with `manifest.json`, SHA-256 verification, and non-destructive restore drill), and live gateway credentials verification in `BillingTest`; Phase 3 (subscription lifecycle) adds `TenantUsageCollectionTest` (system usage_metrics table & collector command `tenants:collect-usage`), failed-payment webhook past_due subscription suspension, `EnsureModule` structured reasons (`X-Module-Reason`), and user/tenant profile attrs in `/api/auth/me`; Phase 1 residuals adds fail-closed `EnsureModule` (A3), tenant-aware password reset (A2), impersonation guards (A5, A6), and serviceable tenant channel auth (A7) across `BroadcastingChannelAuthTest`, `ModuleGateTest`, `SecurityRegressionTest`, and `SystemAdminTest`; Phase E step 17 adds `MemberAccessMatrixTest` (cross-domain matrix over TMS tasks and HRMS leave/expense/document/performance with {own, report, stranger} x {none, own, assigned, all, manage} + sidebar/capabilities) and extends `MemberIsolationTest` with assigned reporting line strict isolation; Phase D step 14 adds button/denial audits with `test_a_permission_denial_names_the_missing_grant`; Phase C step 9 adds `TaskScopeAccessTest` (project-role `tasks.*_own/_assigned/_all` row enforcement on TaskPolicy + board/list), step 12's `ReportsToTest`, step 10's `HrmsScopeAccessTest` (`App\Services\Hrms\HrmsScope` — own/assigned/all row scoping for the leave/expense/document/comp-off lists and policies, `_assigned` via `ReportsTo`), and step 11's `HrmsScopeEmployeeIdTest` (attendance/leave-balance/comp-off-credit per-record policies and the performance goal/check-in/1:1/feedback lists+show flip to `HrmsScope`, so a client-supplied `employee_id` on the My-page reads intersects the caller's real scope instead of widening it — `_assigned` reads reports, `_all`/legacy/manage read everyone); Phase 7 adds `FactoryParityTest` (~12 central + tenant + HRMS factories maintaining relational consistency) and `BrandSweepTest` (branding verified as FlowSync, config-driven storage prefix, zero legacy boilerplate); Phase 6 adds `BillingTest` (checkout initiation, idempotency, verification, history, refunds, locale routing) and `PaymentWebhookTest` (Stripe & Razorpay signature verification, replay guard, payment failure); Phase 5 adds `SubscriptionStateTest` (explicit subscription states, export.full gate, attachment storage quota) and `TenantExportTest` (queued full export, ZIP generation, signed download, tenant isolation); Phase 4-1 adds `NotificationPreferenceTest` (api/notification-preferences), Phase 4-2 adds `MentionEmailTest` (task notification emails + mention cap), Phase 4-3 adds `MentionAutocompleteTest` (api/projects/{project}/members/autocomplete); the Phase 3 HRMS IA gate was 1389/6886 (verified by a single `php artisan test` run at the Phase 3 end-gate: all 145+ test files, zero omitted; the Phase 2 database & performance gate was 1384/6862, the Phase 1 security gate 1372/6829)). The 2026-10-09 full-run totals predate the 2026-10-10 track merges — P2.7 (`ApiTokenTest`), P4.1 (`IssueHierarchyTest`), P4.10 (`TaskBoardWipSwimlaneTest`), P8.x (`SecurityHeadersTest`, `FeatureFlagTest`, `TwoFactorTest` + `Unit/TotpTest`, `PlatformAccessTest`, `SupportAccessTest`, `AuditChainTest`), and HRMS P5 (`HrmsShiftApiTest`, `HrmsRosterApiTest`, `HrmsEmployeeBulkTest`, `HrmsLeaveCompletionTest`, `HrmsDocumentVersionTest`, `HrmsPunchPolicyTest`, `HrmsHolidayImportTest`, `HrmsAssetReplacementTest`) all pass via focused sandboxed runs; Phase 22 (HRMS completion) adds `ModuleGateTest` compensation/payroll cases (+2 tests) and the `HrmsNavTest` Outlet-import guard (+1 assertion), delists `hrms.exemptions` (`ModuleCatalogTest` counts 22→21), and tiles the 8 shipped tile-less modules (`HRMS_MODULE_ROUTES` 10→18 entries); Phase 23 wires the orphaned `ApprovalChains.jsx` (`/hrms/approvals/chains` + Records NAV entry, `hrms.approvals.manage`-gated like its API policy); Phase 24 (talent) adds `HrmsTalentCalibrationTest` (6 tests), `HrmsTalentApiTest` (7 tests) and `HrmsTalentGuardrailTest` (3 tests) — calibration service + policy, `hrms/talent/*` HTTP, `Talent.jsx` + NAV + tile, anonymity/no-score pins, and the `talent.review_finalized` nudge (+16 tests / +134 assertions over the quoted totals); the Tenant Core/TMS/HRMS schema split adds `TenantModuleSchemaTest` (TMS-only / HRMS-only / suite / upgrade + catalog gating + 403s — 7 tests / 92 assertions, focused run green 2026-10-14); the i18n work adds `PublicSiteLocaleTest` (site catalog source of truth, default `en`, `?locale=es` `Content-Language` w/ English fallback, `#site-i18n` island — 4 tests / 15 assertions) and the earlier phases' `LocaleResolutionTest` (9 tests), and the i18n email slice adds `EmailLocaleTest` (task-mail subject/line + rendered body follow the recipient's language, unknown-actor fallback, dispatcher threads per-recipient locale, request-locale fallback — 5 tests / 13 assertions); the full-suite totals are re-verified at the next end-gate run

- `npm run build` / `npm run dev` — frontend build / Vite dev server
- `npm test` — Vitest + Testing Library (jsdom; `vitest.config.js`, setup in `resources/js/test/setup.js`, specs are `resources/js/**/*.test.{js,jsx}`; `ProtectedRoute.test.jsx` (the P0.1 regression), `tenant/IntakeWizard.test.jsx`, `ui/ConfirmDialog.test.jsx`, `ui/Tabs.test.jsx`, `utils/roles.test.js`, `utils/product.test.js` (`productForPath`/`homeForProduct`), `utils/deepLinks.test.js`, `utils/logger.test.js`, `utils/nav.test.js` (sidebar icon components), `ui/Timestamp.test.jsx`, `hooks/useErrorFocus.test.jsx`, `components/Topbar.test.jsx` (switcher), `i18n.test.js` (fallback + tags), `hooks/useLocale.test.jsx`, `ui/RichText.test.jsx`, `ui/RichTextEditor.test.jsx`, `cms/MediaPicker.test.jsx`, `ui/primitives.test.jsx` (Button/Card/Table/Skeleton/EmptyState/Alert), `tasks/FiltersBar.test.jsx` (label pairing + clear), and `hooks/useResource.test.jsx` — 20 files / 86 tests today). `npm run lint` — ESLint 9 flat config (`eslint.config.js`: `no-unused-vars`, `react/jsx-uses-vars`, `react-hooks/rules-of-hooks`); the SPA is at 0 errors — keep it there.
- `./vendor/bin/pint` — PHP code style (run over whole repo; `--dirty` only works in git)
- `php artisan migrate:fresh --seed` — reset the **system** DB (migrations now live under `database/migrations/system`; run it as `migrate:fresh --database=system --path=database/migrations/system --seed` — plain `migrate` runs nothing, see Pitfalls) + seed via `Database\Seeders\TenantSeeder` (provisions acme + globex tenant DBs)
- `php artisan tenants:provision` — idempotently provision/repair tenant DBs (`provisionIsolated` pipeline) + backfill permissions/roles/priorities/project-roles (`--tenant=ID` for one)
- `php artisan tenants:seed-scale` — large realistic scale seed (defaults 100 tenants / 10 users each / **5-10** workspaces / **5-10** projects / 100 tasks per project; `--tenants --users --workspaces --projects --tasks --no-related --seed --dry-run`); `--workspaces`/`--projects` accept a count **or a min-max range drawn per tenant**; provisions real tenants through the onboarding pipeline and prints a totals table + elapsed time (see P10)
- `php artisan tenants:scope-grants [--tenant=ID] [--force]` — Phase B2 migration: for every tenant role holding a legacy unscoped slug (`hrms.leave.view`), grant the scope variant(s) that slug has always meant — `X.view_all` + `X.view_own`, payroll maps to `view_own` only (never widened, 1:1). Idempotent (`syncWithoutDetaching`); report-only without `--force` (prints every grant it would add + warns). The `ScopeGrantBackfill` service maps from `config('permissions.scopes')` + `PermissionScope::legacyScope()`.
- `composer run dev` — concurrently runs serve + queue + pail(logs) + Vite **+ Reverb websockets**
- **Delivery: commit and push every task yourself — do not wait to be asked.** The HRMS plan
  (Phase 15) is executed as one reviewed, verified commit per task on `new/hrms-development`. Before
  committing: read the full `git diff` (new files included), run the **focused feature tests** and
  `./vendor/bin/pint --test` (plus `npm run build` when JS changed), then `git add` the intended paths
  only and `git commit`, then `git push` immediately.
  - **Test policy (updated): always use ParaTest (`php artisan test --parallel`) — for focused files as well as the full suite, e.g. `php artisan test --parallel tests/Feature/FooTest.php`.** Feature-level per task, full suite at the end. While building features, test only
    the feature under construction plus the existing functionality it directly affects — the focused
    test file for the phase, run in full. The application-wide suite is a *final* gate at the end of
    development, not a per-commit one; running it on every task costs ~14 minutes and buries a real
    regression in noise. Re-run `php artisan test` before a commit only when the task touched something
    genuinely cross-cutting (`routes/web.php`, `bootstrap/app.php`, `TenantLimits`, a shared `config/*`,
    a middleware) — and say so in the commit body. The `## Commands` test-count line still has to be
    updated on every change that moves it, and the counts it quotes are then verified by the final run. `HrmsShellTest::test_every_commit_is_pushed`
  fails the suite if a commit is ever left local-only, and
  `HrmsShellTest::test_the_hrms_tree_is_free_of_debug_leftovers` fails it on leftover
  `dd()`/`dump()`/`console.log`/`TODO` in the HRMS tree. Never commit `.env*`, credentials, or
  `storage/`; never use `--force`.
- Entry: `resources/js/main.jsx` (imports `./bootstrap`, React StrictMode).

## Realistic demo dataset (dev only)
- `php artisan tenants:seed-realistic [--from --to --category --slug --reseed --dry-run]` seeds 150 named tenants (50 HRMS-only / 50 TMS-only / 50 both) with every plan × lifecycle state, 25–200 users each, 20–30 projects × 150–160 tasks per TMS tenant and module-gated HRMS data; `tenants:verify-realistic [--report=path]` checks it. Code in `database/seeders/Realistic/*`, sizes in `config/demo_seed.php` (`DEMO_SEED_*`), plan and final report in `.agents/roadmap/realistic-seed-plan.md`. Never run two workers over the same tenant range (a killed `docker exec` client keeps running inside the container); tenants are identified by `settings.demo_seed` and nothing else is touched. Logins: `{first}.{last}@{slug}.test`, password `password`. Tests: `RealisticSeedTest`.

## Demo logins (password `password`)
- `superadmin@flowsync.test` — Super Admin (no tenant)
- `admin@flowsync.test` (admin), `editor@flowsync.test` (editor), `viewer@flowsync.test` (viewer) — Acme tenant
- `owner@globex.test` (admin) — Globex tenant

## Tenant isolation (core design)
**One database per tenant** (Phase 13 shipped): tenant DBs hold the full domain schema **without `tenant_id`
columns** (uniqueness is global-within-DB), and the central **system** DB holds platform data (`tenants`,
`tenant_users`, `provisioning_runs`, `impersonation_logs`, infra sessions/jobs/cache, platform RBAC,
`audit_logs`) + the super admin (`users` table with `is_super_admin`; model `SystemUser`).
- `app/Support/TenantDatabaseManager.php` — singleton managing the two connection names: `system`
  (central, alias `iso_system` in tests) and `tenant` (the current tenant's DB). `connect()/connectSystem()`
  switch the default in-app connection; `using($tenant, fn)` scopes a closure to a tenant DB; central models
  use the `CentralConnection` trait (`getConnectionName()` → `centralConnectionName()`).
- `app/Support/TenantContext.php` — guard-intent singleton (`tenantId`, `impersonating`) set by
  `SetTenantContext` from session keys; no row-scoping branch anymore.
- Middleware order on routes: `switch_tenant` → `auth` → `tenant` → `permission`/`super_admin`; domain routes
  (P1+) use `switch_tenant` → `auth` → `tenant` → `tenant_context` → `permission`.
  - `SwitchTenant` resolves the per-tenant connection from session `login.tenant_id` / `impersonate.tenant_id`
    (**central** ids) and calls `TenantDatabaseManager::using()`.
  - `SetTenantContext` (`tenant`) sets the singleton from session keys (isolated branch only).
  - `EnsureSuperAdmin` (`super_admin`) requires `is_super_admin`.
  - `EnsureTenantContext` (`tenant_context`) aborts 403 unless a tenant context exists — blocks non-impersonating super admins from domain routes.
  - `EnsurePermission` (`permission:slug`) + `Gate::define('permission')` bypass for super admin — **unless impersonating** (then scoped to target tenant).
- Login routes to a tenant via central `tenant_users` routing (`AuthController::loginIsolated`, optional
  `tenant` slug disambiguator); super admins fall back to the system DB. Impersonation resolves the target
  user's tenant-LOCAL id through routing; `stop` returns to the system connection.

## Task-management domain (Phase 0 foundation + P1+)
Hierarchy: **Tenant → Workspace → Project → Task** (subtask `tasks.parent_id` self-FK). Tenant DBs hold the full domain schema **without `tenant_id`** — tenant isolation is the physical DB (no `TenantScoped`).
- New tables (migrations `2026_09_23_000005` → `000010`): `workspaces`, `workspace_members` (role owner/admin/member), `project_roles` (tenant catalog, `permissions` JSON), `priorities` (tenant catalog), `projects` (+ `last_task_sequence`, unique `key`), `project_members` (+ `project_role_id`), `task_statuses` (per-project, `category` enum drives board columns, `is_done`), `tasks` (SoftDeletes, `position`, `status_id`, `priority_id`, unique `(project_id, key|sequence)`), `labels` + `task_label`, `comments` (SoftDeletes), `attachments`, `work_logs`, `task_dependencies` (blocks/related_to), `activities` (polymorphic audit), `notifications` (custom; NOT Laravel's `Notifiable` method table).
- Index optimization (migration `2026_09_23_000011_add_task_search_indexes`): B-tree indexes on `tasks.workspace_id`, `tasks.status_id`, `tasks.assignee_id`, `tasks.due_date`, and `(tasks.project_id, tasks.updated_at)` — until Phase 7 the `tasks` table only carried its two unique composites and no plain FK indexes.
- FK-composite hardening (migration `2026_09_23_000012_add_hardening_indexes`): indexes on `projects.workspace_id`, `tasks.parent_id`, `task_label.label_id`, `comments(task_id,parent_id)`+`user_id`, `attachments.task_id|user_id`, `work_logs(task_id,started_at)`+`user_id`, `task_dependencies.depends_on_task_id`, `activities(subject_type,subject_id)`, `role_user.user_id`, `permission_role.role_id`.
- N+1 guard: `Workspace::memberRole()` / `Project::memberRole()` short-circuit to the **loaded** `members` relation (no per-row pivot query); the list services (`WorkspaceService::listFor`, `ProjectService::listFor|listAll`) eager-load `members` constrained to the current user so role resolution never re-queries. New global/read queries should follow the same pattern (see `ScopesVisibleTasks`).
- Soft deletes: `tasks` + `comments` use `SoftDeletes`; trashed rows 404 via implicit route binding and are excluded from board/list/search/dashboard/reports/subtasks by the global scope (no `withTrashed`-based restore surfaces; deletions broadcast `TaskSynced`) — `tests/Feature/HardeningTest.php` locks all of this in.
- Enums in `app/Enums`: `WorkspaceMemberRole`, `TaskStatusCategory`, `TaskDependencyType`.
- Default catalogs: `config/permissions.php` (tenant perms incl. `workspaces.view|create|manage`), `config/project_roles.php` (project-role perms, seeded lead=`*`/developer/viewer), `config/priorities.php` (highest→lowest, default medium), `config/task_statuses.php` (per-project default statuses, seeded on project create).
- `TenantProvisioner::provisionIsolated()` provisions/clones perms+roles+priorities+project_roles **idempotently** (pins `TenantContext` to null internally — safe to call anytime). `php artisan tenants:provision` provisions/repairs existing tenants.
- PostgreSQL provisioning (exercised by the docker compose stack): `createPostgresDatabase()` creates the
  tenant role FIRST then `CREATE DATABASE … OWNER role` (or `ALTER DATABASE … OWNER` when repairing an
  existing DB) — PG15+ revokes CREATE on the `public` schema for non-owners, so without ownership the
  tenant role gets 42501 on tenant migrations; `Tenant::$hidden` includes `db_password` (never expose the
  per-tenant DB credential in API JSON), and `provisionIsolated` only lifecycle-transitions to
  `provisioning` when `TenantLifecycle::canTransition()` permits (a serviceable-but-half-provisioned
  tenant repairs in place instead of throwing active→provisioning).
- Task keys generated by `app/Services/KeyGenerator::nextTaskKey()` — returns `[key, sequence]` tuple (atomic `last_task_sequence` bump in a transaction → `KEY-N`).
- Policies in `app/Policies/` gate membership + project-role (P1+ fills these in; policy scaffolding per model).

## Workspaces (Phase 1)
- Access: routes in `routes/web.php` sit in the domain group `auth → tenant → tenant_context → permission:workspaces.view`; only `POST /workspaces` also needs `permission:workspaces.create`. Per-object auth is delegated to **policies**, not route-level permission — so an editor can manage a workspace they own/admin even without tenant-level `workspaces.manage`.
- `app/Support`-adjacent `app/Services/WorkspaceService.php`: `listFor()` (tenant-admin `workspaces.manage` → all tenant workspaces; otherwise only where a member), `create` (creator auto-owner; slug auto-suffixed on collision), `update`, `archive`/`restore`, `delete` (**blocked 422 if projects exist** — suggest archive first), `addMember`/`changeMemberRole`/`removeMember`.
- Membership invariants (enforced in the service): members must be **same tenant** (422 `user_id`); no duplicate members; **cannot demote or remove the last owner** (422 `form`).
- Policies: `WorkspacePolicy` — `view` (tenant admin OR member), `update`/`manageMembers` (tenant admin OR owner/admin), `archive`/`restore`/`delete` (tenant admin OR owner). `LabelPolicy` — `view` (tenant admin OR member), `create`/`update`/`delete` (tenant admin OR owner/admin).
- Labels: `labels` scoped to workspace; `name` unique per workspace (validated + DB `unique(workspace_id, name)`); `LabelController` `update`/`delete` at `/api/labels/{label}` (route-model bound, tenant-scoped).
- Response shapes: lists return `{workspaces}`, `{members}`, `{labels}`; workspace objects include `my_role` (owner/admin/member) and `*_count`; member write endpoints return only `{message}` (client refetches).
- Frontend: `pages/Workspaces.jsx` (list + create card), `pages/WorkspaceDetail.jsx` (tabs: projects/members/labels/settings), Sidebar nav item gated `workspaces.view`, routes `/workspaces` + `/workspaces/:workspaceId`. UI decides manageability via `can('workspaces.manage') || my_role ∈ {owner,admin}`.

## Projects (Phase 2)
- Access: routes in `routes/web.php` sit in the domain group `auth → tenant → tenant_context → permission:workspaces.view`. Project roles (`GET /api/project-roles`) ride the same group; role **mutations** (`POST/PUT/DELETE /api/project-roles`) also require `permission:roles.manage` at route level. Per-project auth (view/edit/delete/members/workflow) is delegated to **ProjectPolicy** using project-role permissions. Global project list at `GET /api/projects` (tenant admin → all tenant projects; otherwise only where a project member).
- `app/Services/ProjectService.php`: `listFor()` (tenant-admin `workspaces.manage` → all workspace projects; otherwise only where a project member), `listAll()` (global, same rule across all workspaces), `create` (key auto-derived from initials — "Website Redesign" → `WR` — unique per tenant; seeds 5 default statuses from `config/task_statuses.php`; creator auto-attached as **lead** member + `lead_user_id`), `update`, `archive`/`restore`, `delete` (**blocked 422 `form` if tasks exist**), `addMember`/`changeMemberRole`/`removeMember`, `addStatus`/`updateStatus`/`deleteStatus` (+ `reposition`/`normalizePositions`).
- Project membership invariants (enforced in the service): members must be **same tenant** (422 `user_id`) and already **workspace members** (422 `user_id`); no duplicate members; removal keeps ≥1 member.
- Status invariants: can't delete a status **in use by tasks** or the **last remaining** status (422 `form`); positions are 1-based and re-normalized after add/delete/reorder; `category` (todo/in_progress/done) drives `is_done`.
- Policies: `ProjectPolicy` — `view` (tenant admin OR member), `edit`/`settings`/`delete`/`manageMembers`/`manageWorkflow` via project-role (`projects.edit|settings|delete`, `members.manage`, `projects.settings`), `archive`/`restore` = `edit`. `WorkspacePolicy::createProject` (tenant admin OR workspace owner/admin).
- Project roles: `config/project_roles.php` catalogs permissions; system roles lead=`*`/developer/viewer **cannot be modified or deleted** (422 `form`); custom roles can be deleted only while unused (422 `form` if assigned); slug auto-suffixed on collision.
- Response shapes: project objects include `my_role` (slug), `*_count`; members return `{role: {id, slug, name}}`; member/status write endpoints return `{message}` only (client refetches).
- Frontend: `pages/ProjectDetail.jsx` hub at `/projects/:projectId` (tabs: overview/tasks/members/workflow/settings — settings only for managers); WorkspaceDetail's Projects tab lists + creates projects. UI decides manageability via `can('workspaces.manage') || my_role === 'lead'`; workflow/member controls are hidden for non-managers (backend `403`s regardless).

## Tasks (Phase 3)
- Access: routes in `routes/web.php` sit in the domain group `auth → tenant → tenant_context → permission:workspaces.view`. Endpoints (all project-scoped): `GET|POST projects/{project}/tasks` (board/list index), `GET|PUT|DELETE projects/{project}/tasks/{task}`, `POST projects/{project}/tasks/{task}/move`. **Create** gated by `ProjectPolicy::createTask` (`tasks.create`); **view/edit/delete/assign/move** gated by `TaskPolicy` (project-role `tasks.*` perms, tenant admin via `workspaces.manage` bypass). `update` requires `assign` too when `assignee_id` is present. **Board/list index** additionally requires the caller's project-role grants `tasks.view` (or tenant admin) — the same 403 a per-row show answers, so a project-sight role without the task read gets no rows either way.
- **Row scoping (member-access Phase C).** `TaskPolicy` per-row checks and the board/list query resolve the caller's project-role `tasks.*` grant through `app/Support/TaskScope.php`: `_all` (or the legacy unsuffixed `tasks.view`, which means all) reads every project task; `_assigned` reads tasks whose assignee/reporter is the caller or one of their direct reports (`ReportsTo::idsFor()`); `_own` reads only the caller's rows. The board/index gate asks `grants('tasks.view_own')` and `TaskService::board/list` then constrain the query by the same rule (tenant admins bypass). A project role with no `tasks.view*` grant still 403s the board. `TaskScope` is the single definition shared by the policy (rows) and the service (query). **Global task reads (`search/tasks`, `dashboard`, `reports`, `analytics`, global search, HRMS task links) go through `ScopesVisibleTasks::visibleTaskQuery`, which now applies the same row scope per project (R10):** projects are grouped by the widest `tasks.view*` scope the caller's project role holds, each group is constrained by `TaskScope::constrainQuery`, and a role with no task-read grant contributes no projects (tenant-wide `workspaces.manage` still bypasses).
- `app/Services/TaskService.php`: `create` (atomic key via `KeyGenerator::nextTaskKey()` tuple → `key`/`sequence`; default status/priority; position at end of column; `completed_at` when status `is_done`; labels attached), `update` (labels `sync()` when `labels` present), `delete` (soft), `move` (hard-blocks `is_done` when `hasOpenBlockers()` → 422 `form`; renumbers **both** destination and source columns 1..N), `list` (paginated + `sort_by`/`sort_dir`/`per_page`), `show` (loads subtasks/labels/counts), `filteredQuery` (status_id/priority_id/assignee_id/label_id/q/due_from/due_to). The board read lives in `app/Services/Tasks/TaskReader.php`: `board()` (top-level tasks `whereNull(parent_id)` grouped by status; `totals.open/done` respect active filters) + `buildColumn()`/`boardByAssignee()`.
- **WIP limits + swimlanes (P4.10).** `task_statuses.wip_limit` (nullable positive int) caps a column's **open top-level** cards (`completed_at IS NULL`, `parent_id IS NULL`) — matching the board's `open_count`, so subtasks/completed cards never trip it. `TaskService::assertWip()` enforces it on arrivals into a column (`create`, a status-moving `update`, `move` — 422 `form`, message `"<name>" is at its WIP limit (N). Move a card out or raise the limit.`); a reorder inside the same column is allowed because the mover keeps its own occupancy. `GET .../tasks?swimlane=assignee` returns `{board: {swimlanes: [{assignee:{id,name}|null, statuses:[...], totals:{open,done}}], totals}}` (lanes by lowercased assignee name, unassigned last) instead of the flat `{statuses:[...]}`; `swimlane` is validated `in:none,assignee`. Every column carries `open_count` alongside `tasks_count`.
- Resolvers throw 422: `status_id` must belong to the project; `priority_id` must belong to the tenant; `assignee_id` must be a same-tenant **project member**; `parent_id` must belong to the project; `labels` must belong to the workspace.
- Response shapes: board → `{board: {statuses: [...presentStatus + tasks_count/open_count/tasks], totals}, filters: {statuses, priorities, assignees, labels}, my_role}` (swimlanes replace `statuses` when `?swimlane=assignee`); list → `{tasks, filters, my_role, pagination}`; write → `{message, task}`.
- Realtime: `app/Events/TaskSynced` (action `created|updated|deleted|moved`) broadcasts on `PrivateChannel('project.'.$project_id)` with `broadcastAs('task.synced')` — fires on store/update/destroy/move.
- Frontend: Tasks tab in `pages/ProjectDetail.jsx` (board/list toggle, filter bar, new-task modal, task drawer) built from `resources/js/components/tasks/`: `KanbanBoard` (dnd-kit `DndContext` + per-column `SortableContext`, columns are droppable `col-{id}`; P4.10 adds a swimlane picker, per-lane rows with their own `DndContext`, and a WIP badge showing `tasks_count / wip_limit` that reddens when the column is full), `TaskCard` (sortable), `TaskTable` (list), `FiltersBar`, `CreateTaskModal`, `TaskDetail` (slide-over edit drawer). Long-form descriptions edit through the shared TipTap `ui/RichTextEditor.jsx` (bold/italic/strike/underline, H1–H3, bullet/ordered/checklist lists, quote/code/HR, undo/redo; stores HTML in the `text` column, empty normalizes to `''`) and render read-only via `ui/RichText.jsx` (DOMPurify-sanitized; pre-editor plain text passes through) — tasks, projects (create/settings/header, excerpt in cards), workspaces (create/settings/header, excerpt in tables), departments (modal + org page), support ticket bodies and replies (thread renders `RichText`). Truncated cells use the `stripRichText()` excerpt helper, never raw HTML. Short labels stay plain inputs (plans, policies, structures, cycles, calendars, issue types, webhooks, roles, tenants, flags, claim lines, work logs, survey answers, comments — the last two would break mention parsing and exports). Workspace/project/department maxes are 20000 (HTML overhead); support body min/max measure stripped visible text via `SupportTicketController::bodyLength()`. List view paginates through the shared `ui/Pagination.jsx` (`page` param, reset on view/filter change, clamp past last page). UI permission mirror: `can('workspaces.manage') || my_role ∈ {lead,developer}` for create/edit/assign/move; delete only for tenant admin or lead. Workflow tab edits each status's WIP limit inline. Subscribes `window.Echo.private('project.{id}')` `.task.synced` → refetch board/list.

## Collaboration (Phase 4)
- Access: all endpoints in `routes/web.php` sit in the domain group `auth → tenant → tenant_context → permission:workspaces.view`. Controllers MUST declare `Project $project` alongside `Task $task` in every method signature — Laravel binds `{project}`/`{task}` from the **controller signature** (ImplicitRouteBinding); omitting `Project` leaves it a raw string spliced positionally → TypeError.
- Comments: `GET|POST projects/{project}/tasks/{task}/comments`, `PUT|DELETE …/comments/{comment}`. `index` returns a nested tree `{comments:[{…, replies:[…]}]}` (top-level `parent_id IS NULL`, one level deep). `store` validates `parent_id` is a same-task **top-level** comment (else 422 `parent_id`). Mutations gated by `CommentPolicy` (auto-discovered): create via `authorize('create', [Comment::class, $task])` (note the array form so the policy resolves to CommentPolicy); update/delete by owner OR project-role `comments.edit|delete` OR tenant admin; route-model-bound `{comment}` verified `task_id === $task->id` else 404; soft-deleted/trashed comments 404. `present()` emits `id/comment/edited_at/deleted_at/created_at/user{id,name,email}` (no `parent_id` — tree nesting carries it). Broadcasts `App\Events\CommentSynced` on `private('project.'.$comment->task->project_id)` with `broadcastAs('comment.synced')` (action created/updated/deleted, queued).
- Dependencies: `GET|POST projects/{project}/tasks/{task}/dependencies`, `DELETE …/dependencies/{dependency}`. `index` returns `{blocked_by:[…], blocks:[…]}` — **blocked_by** = tasks this task depends on (`task_id === $task->id`, exposing `dependsOn`), **blocks** = tasks that depend on it (`depends_on_task_id === $task->id`, exposing `task`); entries carry `{id, type, task_id, depends_on_task_id, task:{id,key,title,status_id,completed_at}}`. `store` (gated `authorize('edit', $task)` = `tasks.edit`) rejects self-dep (422 `depends_on_task_id`), cross-project blocker (422 `depends_on_task_id` — `$task->project->tasks()->find()`), duplicates (422 `form`), and BFS-detected cycles (422 `form`). Hard-block integration: `open_blockers_count` (`withCount('openBlockers')`) on task show payload; `TaskMoveController::move` rejects Done when `hasOpenBlockers()`. `TaskDependency` has **no tenant_id** (only `task_id`/`depends_on_task_id`/`type` enum).
- Attachments: `GET|POST projects/{project}/tasks/{task}/attachments`, `DELETE …/attachments/{attachment}`, plus **signed download** `GET api/tasks/{task}/attachments/{attachment}/download` → `attachments.download`, middleware `signed` (alias registered in `bootstrap/app.php`), intentionally **outside** the auth/tenant groups so a fresh-browser-tab GET works — the signature is the bearer token. Validation: `File::types([jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,md,csv,zip,json])->max(10 * 1024)`; stored on `local` disk (root `storage/app/private`) at `tasks/{project_id}/{task_id}/{uuid}.{ext}`. `AttachmentPolicy`: view=`tasks.view`, create (`[Attachment::class, $task]`) = `attachments.create`, delete = owner OR `attachments.delete` OR tenant admin. `present()` includes `download_url` = `url()->temporarySignedRoute('attachments.download', now()->addHours(1), ['task' => $attachment->task_id, 'attachment' => $attachment->id, 'tenant' => $this->tenantContext->currentId()])` — **the central tenant id must ride inside the signature**: the route sits outside `switch_tenant`, so task/attachment ids (tenant-local) resolve to nothing on the central default connection (`relation "tasks" does not exist`). `download()` therefore takes `(Request $request, int $task, int $attachment)` — no model binding — resolves `Tenant::find($request->query('tenant'))` on the central connection and does the lookup + stream inside `TenantDatabaseManager::using($tenant, …)`. Editing the signed `tenant` param invalidates the signature (403). `CollaborationTest::test_signed_download_works_without_a_session_on_the_central_connection` is the regression guard: it `flushSession()`s, sets the default connection to `iso_system`, and fetches a hand-signed URL. Delete also removes the file via `Storage::disk($attachment->disk)->delete($path)`. Model `url()` uses `Storage::temporaryUrl` (local-disk has no temp URLs — prefer the signed route).
- Activity timeline: `GET projects/{project}/tasks/{task}/activities` (Task subject) and `GET projects/{project}/activities` (Project subject + its task ids). Both `orderByDesc('id')` (second-precision `created_at` ties are non-deterministic — never order by `created_at` alone), limit 100/200, `with('actor')`; `present()` = `{id, action, data, created_at, actor:{id,name}, subject_type}`. `ActivityLogger::log(subjectType, subjectId, action, data?, actor?, ipAddress?)` writes a polymorphic `activities` row (tenant from `TenantContext`). Wired on: task create/update/delete (`task.created|updated|deleted`, data `{key,title}`/`{key,fields}`), move (`task.moved`, data `{from_status:{id,name}, to_status:{id,name}}`), comment (`task.commented`, `{comment_id, snippet}`), dependency create/delete (`task.dependency_created|deleted`, `{depends_on_task_id,type}`), attachment create/delete (`task.attachment_created|deleted`, `{attachment_id,name,size}`).
- Frontend: sub-tabs inside the `TaskDetail` drawer (`details` = edit form kept as-is; plus `comments`/`attachments`/`dependencies`/`time`/`activity`). `CommentThread` subscribes `window.Echo.private('project.{id}')` `.comment.synced` → refetch (only while mounted). `AttachmentList` uses `multipart/form-data` `{headers:{'Content-Type':'multipart/form-data'}}` + `fieldErrors`, download links open `download_url` in a new tab. `DependencyPanel` picker uses `topLevelTasks` (board tasks) for `depends_on_task_id`. Activities rendered via `describe(action, data)` label map (moved shows `from/to` status names, commented shows snippet).
- **Binding gotcha:** because `ImplicitRouteBinding` substitutes only the controller signature's model params, every collab method takes `(Request $request, Project $project, Task $task, …)` even when `$project` is unused — the extra bound param is what keeps the raw `{project}` string out of positional argument splicing. (See also the `ResolvesRouteDependencies` value-matching behavior.)

- **Checklists (P4.9a):** checklist items live inside a task via `task_checklist_items` (no `tenant_id`; tenant DB is the isolation boundary). Routes: `GET|POST projects/{project}/tasks/{task}/checklist`, `PUT|DELETE …/checklist/{item}` in the `auth → tenant → tenant_context → permission:workspaces.view` group. Controller signatures must declare `(Request $request, Project $project, Task $task, TaskChecklistItem $item)` — the extra `Project $project` prevents `ImplicitRouteBinding` from splicing a raw `{project}` string. Authorization via `TaskPolicy` (`view` for index, `edit` for store/update/destroy). Store appends at `max(position)+1`; update toggles `is_done` (sets/clears `completed_at`/`completed_by`); position change renormalizes siblings to `0..N-1`; destroy renumbers. Cap of 100 items per task (422 `form` on excess). Activity rows: `task.checklist_item_added|checked|unchecked|removed` via `ActivityLogger`. Task show payload carries `checklist_done_count` and `checklist_total`. An item belonging to a different task returns 404; cross-tenant requests are isolated by the per-tenant DB. Viewers without `tasks.edit` get 403 on writes; non-members get 403/404 per `TaskPolicy`.

## Notifications (Phase 5)
- Access: `GET|POST api/notifications`-family routes live in the plain `auth → tenant` group in `routes/web.php` (not `tenant_context`) — personal notifications are self-scoped by `user_id`, so no tenant-only gate is needed. Routes: `GET notifications` (paginated, with `actor`), `GET notifications/unread`, `POST notifications/{notification}/read`, `POST notifications/mark-all-read`.
- `NotificationController` verifies `$notification->user_id === auth()->id()` in `markRead` (else 404), so users can't mark others' notifications read. `index` returns `{notifications, unread_count, pagination}`; each item = `{id, type, data, read_at, created_at, actor:{id,name}}`. `markRead` takes `int $notification` (NOT a route-model-bound `UserNotification`) so the platform guard can run **before** any query.
- **Platform super admin:** the `notifications` table is tenant-only, so the whole family short-circuits via the `DetectsPlatformUsers` concern (`isPlatformSuperAdmin()` = `user.is_super_admin && !TenantContext::hasTenant()`): empty list / `count: 0` / 404 on mark-read. Impersonating super admins are the impersonated tenant user (`is_super_admin` false) and read their tenant DB as usual. `ThemeController` uses the same concern — a platform SA gets `config('theme.defaults')` and its `PUT` is not persisted (`user_settings` is a tenant table), matching `AuthController::payload`.
- **Email delivery (Phase 4):** `NotificationService::notify()` also `Mail::queue()`s a kind-driven `App\Mail\TaskNotificationMail` for the four emailable TMS events (`EMAIL_EVENTS`: task.assigned/status_changed/commented/unblocked — HRMS nudges never email), gated by `NotificationPreference::wants($recipient, $type)`. The mailable **snapshots scalars at construction** (no models inside the queued job ⇒ no cross-tenant leak), renders the text view `emails/task-notification.blade.php`, and ships a `/app` deep link. Queued mail rides the same `Queue::createPayloadUsing` tenant stamping as every job. **Copy is localized (i18n Phase 6)** — subject/line come from `notifications.*` in the recipient's language (see the i18n section).
- **Per-event preferences (Phase 4):** `notification_preferences` = one row per user, `preferences` JSON (event→bool, keys are the dotted event names — read via literal `$preferences[$type] ?? true`, never `data_get`/`assertJsonPath`, which treat the dot as a path). `GET|PUT api/notification-preferences` self-scoped in the plain `auth → tenant` group (`DetectsPlatformUsers` short-circuit, SA PUT 404s, PUT is a partial merge + 422 on unknown events). Preferences gate **email only** — in-app rows are always delivered. `config/notifications.php` is the event catalog for the Settings toggles.
- **Mention cap + autocomplete (Phase 4):** `taskCommented()` caps mentioned users at `TaskNotificationMail::MAX_MENTIONS_PER_COMMENT` (20; assignee/reporter always included, actor excluded) and returns `{notifications, truncated}` — excess mentions are dropped, and `CommentController@store` echoes `truncated_mentions` for a UI warning. `mentionUsers()` regex is `@([A-Za-z0-9._-]+(?:@[A-Za-z0-9._-]+)?)` — a full-mailbox token (`@viewer@flowsync.test`) matches the exact email only, so a foreign tenant's mailbox never resolves to a same-tenant user sharing its local part (owner@globex.test must not ping owner@acme.test). `GET api/projects/{project}/members/autocomplete?q=` (domain group, `authorize('view', $project)`) returns ≤10 member `{id,name,email}` rows matching a name prefix or email local part — the `CommentThread` mention typeahead's data source.
- Types + payloads (data always includes `task_id/key/title/project_id/project_name/workspace_id`): `task.assigned` (assignee only, skips self-assign), `task.status_changed` (+`from_status`/`to_status` names), `task.commented` (+`comment_id`/`snippet`; recipients = assignee + reporter + `@`-mentioned users, excluding the author), `task.unblocked` (+`blocked_by {id,key,title}`, only when the last open blocker is removed), `task.work_logged` (+`work_log_id`/`duration_minutes`, fires on work-log **create** only — assignee, skips the logger).
- Mention resolution (`NotificationService::mentionUsers`): tokens matched **case-insensitively** against a same-tenant user's full email (`LOWER(email) = ?`), email local part (`LOWER(email) LIKE 'token@%'`), or name (`LOWER(name) = ?`). Dedupes users matched more than once.
- Wiring: notifications are a controller-side effect (like ActivityLogger) — `TaskController@store`/`@update` (assignee change → assigned; status change → status_changed), `TaskMoveController@move` (status change), `CommentController@store` (comment/mentions), `DependencyController@destroy` (was blocked → now unblocked), `WorkLogController@store` (work logged → `task.work_logged` to assignee).
- `NotificationSent::broadcastWith` includes `actor {id,name}` so clients render toast/messages without another fetch.
- Frontend: `NotificationContext` provider (nested in `ToastProvider`) — 30s poll of `/notifications/unread` + Echo `user.{id}` `.notification.sent` listener (increments badge count + shows a toast via `describeNotification`); `NotificationBell` dropdown in the Topbar (fetches latest 8, mark-read on click → navigates via `notificationHref`, mark-all-read); `/notifications` page (paginated, mark-read/mark-all-read, breadcrumb). Helpers in `resources/js/utils/notifications.js`.

## Time Tracking (Phase 6)
- Access: work-log + time-summary routes in `routes/web.php` sit in the domain group `auth → tenant → tenant_context → permission:workspaces.view`. `GET|POST projects/{project}/tasks/{task}/work-logs`, `PUT|DELETE …/work-logs/{workLog}`, `GET projects/{project}/time-summary`, `GET workspaces/{workspace}/time-summary`. Controllers MUST declare `(Request $request, Project $project, Task $task, WorkLog $workLog, …)` per the `{project}`/`{task}` binding gotcha; `{workLog}` verified `task_id === $task->id` else 404.
- `app/Services/WorkLogService.php`: `taskLogs` (paginated, `orderByDesc('started_at')`), `create`/`update` (duration recomputed as `max(1, round(diffMinutes))` **negated diff gotcha**: Carbon 2 `diffInSeconds` is signed — bound with `abs()`; **overlap rejection** on the same task, excluding self, boundary-touch units are allowed, open-ended logs conflict; 422 `form`), `delete` (hard), `effectiveMinutes` (running logs count elapsed-to-now), `taskAggregate` (`total_minutes`/`estimate_minutes`/`remaining_minutes`/`logs_count`; remaining null when no estimate), `projectSummary`/`workspaceSummary` (group by `user`|`status`|`date`; `from`/`to` date filters on `started_at`; `user_id` filter).
- `WorkLogPolicy`: `create` (`WorkLog::class, $task` array form = `work_logs.create` or tenant admin), `update`/`delete` (own log OR `work_logs.edit`/`work_logs.delete`/`work_logs.manage` project role OR tenant admin). Tenant admin = `workspaces.manage` bypass via `WorkspacePolicy`-style `hasPermission`.
- Validation: `started_at` required date; `ended_at` nullable date `after:started_at`; `description` ≤1000. Summaries validate `group_by ∈ {user,status,date}`, `from`/`to` dates, `user_id` integer.
- Response shapes: `{work_logs:[{id,started_at,ended_at,duration_minutes,effective_minutes,description,user{id,name,email}}], pagination, totals, my_role}`. Logs store a `duration_minutes` snapshot; `effective_minutes` recomputes running entries. Summaries → `{summary:{total_minutes, logs_count, group_by, groups:[{key,label,minutes,logs_count}]}}`.
- Side effects: `ActivityLogger` rows `task.work_logged|work_log_updated|work_log_deleted` (data `{work_log_id, duration_minutes, started_at}`); `NotificationService::workLogAdded` on create → `task.work_logged` to assignee (skip self). No dedicated broadcast event (work-log changes surface via the task's activity feed / list refetch).
- Frontend: `WorkLogPanel` (new **Time** sub-tab in `TaskDetail`; form = datetime-local started/ended + description, list rows with duration pill + edit/delete gated by `canLog` && own OR `canManage`); `TimeSummary` (`components/time/TimeSummary.jsx`; group_by + from/to filters; total + progress bars) in a **Time** tab on `ProjectDetail` (`/projects/{id}/time-summary`) and `WorkspaceDetail` (`/workspaces/{id}/time-summary`). `utils/time.js` `formatMinutes()` renders `Xh Ym`. UI permission mirror: `canLog = can('workspaces.manage') || my_role ∈ {lead,developer}`; `canManageLogs = can('workspaces.manage') || my_role === 'lead'`. Estimates also render on `TaskCard` (`est …` chip). Project roles catalog already includes `work_logs.create/edit/delete/manage`.

## Search & Reporting (Phase 7)
- Access: three read-only endpoints in `routes/web.php` in the `auth → tenant → tenant_context` group but **outside** the `workspaces.view` group — each has its own route-level permission: `GET search/tasks` → `permission:workspaces.view`, `GET dashboard` → `permission:dashboard.view`, `GET reports/overview` → `permission:reports.view` (a custom role could hold one without the other, so never blanket-gate with `workspaces.view`). No new permissions were needed — `dashboard.view`/`reports.view` already exist in `config/permissions.php`.
- `app/Http/Controllers/Concerns/ScopesVisibleTasks.php` — shared trait for all three: `visibleTaskQuery(User)` = `Task::query()` tenant-scoped via the model + `whereHas('project.members' → user_id)` **unless** the user holds tenant-wide `workspaces.manage`; eager `project, workspace, status, priority, assignee, labels`. `presentTask(Task)` = `TaskService::present()` + `updated_at` (ISO) + nested `project {id,name,key}` / `workspace {id,name}`. **This is the canonical "visible tasks" scoping for global/unscoped queries** — reuse it for any new global read.
- `SearchController::tasks`: validated filters `q` (LIKE title/key/description), `status_id`, `priority_id`, `assignee_id`, `project_id`, `workspace_id`, `label_id` (`whereHas('labels')`), `due_from`/`due_to`, `assignee=me`, `per_page` (1–100). `withCount` subtasks/comments/attachments/openBlockers; `orderByDesc('tasks.updated_at')` then `id`; pagination `{current_page,last_page,per_page,total}`. Response also carries `filters` (like the board's payload, tenant-wide): distinct statuses/priorities/assignees/projects/workspaces/labels **computed from the same scoped base** (`distinct()->pluck` FKs; label ids via `task_label` pivot subquery) — **note statuses are per-project rows, so cross-project page shows one status option per project**.
- `DashboardController` (`__invoke`): `counts {my_open, my_overdue, my_due_soon, open, done, in_progress}` (my-sets scoped `assignee_id = me`, non-archived; overdue `due_date < today`; due_soon next 7 days; `in_progress` via status `category = in_progress`; `open`/`done` by `completed_at` null/not) + lists `my_overdue`/`my_due_soon`/`in_progress`/`my_open` (≤6, due-date ordered) and `recent` (≤8 by `updated_at`).
- `ReportsController::overview`: `totals {total, open, done, overdue}` + `by_status`/`by_priority`/`by_assignee`/`by_project` distributions — each `[{key, label, count, open, done, color}]` (assignee label "Unassigned" for null), sorted by count desc.
- `AnalyticsController` (`GET analytics/overview`, `permission:dashboard.view`) — `counts {workspaces, projects, open, done, overdue, due_this_week, created_30d}` scoped like dashboard (managers → all, else membership; tasks via `visibleTaskQuery`), `projects_progress` (≤12 projects: open/done/total/percent), `work_logs {total_minutes, today_minutes, week_minutes, daily[14d]}` for **visible task ids** only, `tasks_created.daily[14d]` (per-day created counts), `top_contributors` (top 5 by 30d logged minutes). Route sits in the domain `auth → tenant → tenant_context → dashboard.view` group.
- Frontend: `Dashboard.jsx` (6 stat widgets + task lists with `{key} · title`, priority dot, links to `/projects/{id}?tab=tasks`; analytics charts via **Recharts** — project-progress stacked bars, 14-day hours-logged bars, 14-day task-creation line + top-contributors list; analytics fetch is best-effort, hidden on failure), `Reports.jsx` (totals + four distribution bars + a Time-logged scope picker driving `TimeSummary` from `/workspaces/{id}/time-summary` or `/projects/{id}/time-summary`), and new `pages/Search.jsx` at `/search` (workspaces.view-grouped route; filter bar for q/workspace/project/status/priority/assignee/label/due-from/to; results table; pagination like Notifications). "Search" nav item added in `Sidebar.jsx`. `ProjectDetail` now initializes its tab from `?tab=…` for deep links.

## Global Search & UI/UX (Phase 9)
- Endpoint: `GET api/search/global` (`GlobalSearchController`, `SearchController`-adjacent) lives in **`routes/web.php`** in the plain `auth → tenant` group with `permission:workspaces.view` — deliberately **outside** `tenant_context` so a non-impersonating super admin can cross tenants. A non-impersonating super admin gets a **per-tenant fan-out**: the controller loops every provisioned `active`/`trial` tenant through `TenantDatabaseManager::using()`, merges the per-tenant hits, then slices each group to its limit (first tenants in the loop win — sequential, not parallel). Everyone else is scoped to the session's tenant DB. `q` must be ≥2 chars (422). Returns `{query, results:{workspaces,projects,tasks,users,employees}, total}`:
  - `workspaces`: visible scope (tenant-manager → all; else member), carries `tenant` name, `projects_count`.
  - `projects`: visible scope (tenant-manager → all; else project member), carries `workspace` name, `tasks_count`, `tenant`.
  - `tasks`: via `visibleTaskQuery()` (see Phase 7) **plus** the `ScopesVisibleTasks::userManagesAllTasks()` bypass that treats a **non-impersonating super admin** as a tenant-wide manager of each visited tenant; per-tenant cap 8 (global slice), `orderByDesc('tasks.updated_at')`.
  - `users`: gated `users.view` (or super admin), scoped to the visited tenant DB, matches name/email, sorted by name; **no navigation action** client-side.
  - `employees`: HRMS directory rows (name + `employee_code` only), only when `maySeeEmployees()` (super admin, or `hrms.employees.view`) **and** the tenant's plan holds `hrms.core`.
  - **Audited (R13):** a non-impersonating super admin's fan-out that returns any tenant data writes one `search.cross_tenant` audit row (`q` ≤100 chars, `tenants_searched`, per-type result counts), deduplicated per (admin, case-insensitive query) for 5 minutes via `Cache::add` because the palette fires on every pause in typing.
- Frontend: `resources/js/components/search/CommandPalette.jsx` — Jira-style quick search. Triggered via global `⌘K`/`Ctrl+K` (Topbar keydown effect) and a Topbar "Search… ⌘K" button/icon; state owned by `AdminLayout`, gated on `can('workspaces.view')`. Debounced 250ms `GET /search/global` (AbortController), grouped results (Tasks/Projects/Workspaces/People) with entity icons, ↑/↓/↵/esc keyboard nav + mouse, min 2 chars. Navigates: task → `/projects/{id}?tab=tasks&task=KEY`; project → `/projects/{id}`; workspace → `/workspaces/{id}`.
- Task deep-link: `ProjectDetail` reads `?task=KEY` — forces the Tasks tab via tab-init (`?tab` wins) and an effect auto-opens the task drawer once the board/list pool contains it (ref-guarded so board refetches don't re-open). The query string is **kept in the URL** (no `replaceState` strip) so the link survives refresh/direct-tab — see Phase 10 for the section-aware form.
- UI primitives now live in `resources/js/components/ui/` (`Select`, `Modal`, `Drawer`, `EmptyState`, `Avatar`, `Spinner`, `fieldStyles.js`, `Pagination`, `ConfirmDialog.jsx`, `Tabs.jsx`; enriched `Button`, `Input`, `Card`). **`Modal` + `Drawer` render through `createPortal(…, document.body)`** — the page-content wrapper in `AdminLayout` carries `animate-fade-in-up` (a transform animation with `fill-mode: both`), which makes it a containing block/stacking context, so a nested `fixed` overlay is trapped behind the sidebar/topbar instead of the viewport. Any new full-screen overlay must be portalled too (`CommandPalette` z-[80] and `ThemeSettingsDrawer` are safe only because `AdminLayout` renders them outside the animated wrapper). **Confirmations go through the shared promise-based `ConfirmDialog`/`useConfirm()`** (`ConfirmDialog.jsx`: `ConfirmProvider` is mounted in `App.jsx` inside `NotificationProvider`; call `const confirm = useConfirm()` then `if (!(await confirm({ title, message, variant: 'danger' }))) return;` — it resolves `true`/`false`). Raw `window.confirm` must not be reintroduced (roadmap P1.12 / DoD). Sidebar is collapsible (`w-64 ↔ w-16`, persisted via localStorage key `flowsync.sidebar.collapsed`, sectioned nav + active pill + accent bar). Toasts were modernized — compact tinted card, no progress bar, `warning` type added. Kanban board scrolls horizontally in one row (`board-scroll`) instead of a wrapping grid (fixes Done column dropping below Backlog). `TaskDetail` uses the shared `Drawer` (footer actions, meta-rail layout). Remaining grid/flex selects use `fieldClass`/`fieldClassCompact` directly (`Select` is label-wrapping and unfit for inline cells).

## Scale Seed Data & Deep Links (Phase 10)
- `database/seeders/ScaleDataSeeder.php` — `run(tenants=100, usersPerTenant=10, workspacesPerTenant=5, projectsPerWorkspace=5, tasksPerProject=100, related=true, seed=null)` where `workspacesPerTenant`/`projectsPerWorkspace` are `int|array` (a fixed count, or a `[min, max]` **range resolved per tenant** via `resolveCount()`+`mt_rand`; pass `seed` to make the draws reproducible). Re-running is additive/idempotent (`firstOrCreate` on slug/key + a `tasks()->count() === 0` guard), and it finishes with a totals table + elapsed seconds builds realistic same-tenant-isolated scale data: 1 super admin, tenants `tenant-{NNN}` (provisioned: default permissions/roles/priorities/project-roles + `owner@{slug}.test` admin), `usersPerTenant-1` extra users (roles cycle admin/editor/viewer), workspaces `w1..wN` with owner/admin/member pivots, projects keyed `{last-3-of-slug}-{w}-{p}` + 5 default statuses + lead/developer/viewer members, and **exactly `tasksPerProject` tasks** (bulk `DB::table` inserts — Carbon must be string-cast; ids captured via `DB::getPdo()->lastInsertId() - (tasksPerProject - 1)`) distributed `STATUS_WEIGHTS=[15,20,30,15,20]` (sums exactly to N), cycling priorities/assignees (`i%8===0 → null`), `due_date` on open tasks, `estimate_minutes` null on `i%3===0`, `completed_at` for done statuses, per-status column positions. Related data: comments `offset%7===0`, work logs `(offset+1)%5===0`, `task.assigned` notifications per worker at `offset=(u*4)%tasksPerProject` (data carries `task_id/key/title/project_id/project_name/workspace_id`). Enabled by `php artisan tenants:seed-scale [--tenants --users --workspaces --projects --tasks --no-related --seed --dry-run]` (`--dry-run` prints the projected 250k-1M task volume without writing). **Seeded on this dev host: 100 tenants (`tenant-001`..`tenant-100`) / 1,000 users / 500 workspaces / 2,500 projects / 250,000 tasks + 37.5k comments / 50k work logs / 20k notifications**, each tenant in its own Postgres database.
- Seeder test gotcha: `$this->seed(DatabaseSeeder::class)` runs via `db:seed --class` and accepts **no params** — tests invoke the seeder directly: `app(ScaleDataSeeder::class)->run(...)`.
- Deep links (SPA): workspace `/workspaces/{id}?tab=…`; project `/projects/{id}?tab=…`; task `/projects/{id}?tab=tasks&task={key}[&section=comments|attachments|dependencies|time|activity]`. **All** `?tab=`/`&task=`/`&section=` URL building lives in `resources/js/utils/deepLinks.js` (`taskUrl(projectId,key,section)`/`projectUrl(id,tab)`/`workspaceUrl(id,tab)`) — reused by `CommandPalette` `hrefFor`, `utils/notifications.js` `notificationHref(data,type)` (**type-aware** — `task.commented` → `section=comments`, `task.work_logged` → `section=time`; falls back to `/workspaces/{id}` when only `workspace_id` is present), Dashboard `TaskRow`, and Search results. `ProjectDetail` keeps the query **in the URL** (refresh/direct-tab safe), initializes/keeps `tab` in lockstep with `useSearchParams` (sync effect → back/forward safe), and `changeTab` writes `?tab=` back while pruning `task`/`section` when leaving Tasks; forces board view for deep links, auto-opens the drawer via `openedDeepTaskRef` (reads `searchParams`), and `openTask(task, section)` passes the section down; `TaskDetail` accepts an `initialSection` prop and forces that drawer sub-tab on `[task]` change (validated against details/comments/attachments/dependencies/time/activity). `WorkspaceDetail` tabs are fully URL-driven (derived `activeTab` validated against the computed tab set; `changeTab` pushes `?tab=` and clears it for projects). `ProtectedRoute` preserves `pathname + search` through the login redirect so an unauthenticated deep link resumes after login.
- Access denied: `ProjectController::show` 403s non-members (tenant admins bypass; cross-tenant 404 via tenant scope). Frontend `ProjectDetail`/`WorkspaceDetail` catch `err.response?.status === 403` → `navigate('/403', {replace:true})` (existing Forbidden page); other load failures keep the generic error state.

## Website management / CMS + public site (Phase 14, item 11)
- **SPA moved to `/app`** — `BrowserRouter basename="/app"` in `resources/js/App.jsx`; the root
  `/` path now belongs to the public marketing site. `services/api.js` 401 redirect targets
  `/app/login`. `tests/Feature/ExampleTest.php` asserts `GET /app` (the old `GET /` needed the system DB).
- **`website_pages`** (system DB, migration `2026_09_24_000019`) = DB-backed CMS pages:
  `slug` unique, `title`, `content` JSON blocks (ordered array of `hero|features|text|cta`), `status`
  (`draft|published`), `seo_title`, `meta_description`, `og_image`, `sitemap_include`, `sort_order`,
  `published_at`. Model `App\Models\WebsitePage` (CentralConnection; `content` array cast;
  `isPublished()/publish()/unpublish()`). Seeded idempotently in `TenantSeeder::seedWebsitePages()`
  (home published with hero/features/cta; about draft; privacy/terms published but `sitemap_include=false`).
- **Admin CRUD** (super_admin group at `api/system/pages*`): `CmsController` index/show/store/update/
  destroy + `POST …/{page}/publish|unpublish`; writes central `audit_logs` rows
  `cms.page_created|updated|published|unpublished|deleted` (data `{slug,title,status}`); saving as
  `published` stamps `published_at` (preserved on later published edits); the `home` page can't be
  deleted (422). Frontend: `pages/CmsPages.jsx` at `/admin/pages` (sidebar "Website" under
  Administration) — table + `PageFormModal` with a typed block editor (add/reorder/remove blocks,
  inline CTA fields, per-block fields). Seeded idempotently in `TenantSeeder::seedWebsitePages()`
  (17 pages: home/features/pricing/solutions/integrations/customers/security/about/careers/contact/
  faq/changelog published + in sitemap; privacy/terms/dpa/cookies/refunds published but
  `sitemap_include=false`), `seedWebsiteConfig()` (nav/footer/social/announcement/contact via
  `firstOrCreate` — admin owns after), and `seedBlogContent()` (Guides/Company categories + 2 posts).
- **Public server-rendered site** (`PublicSiteController`, no auth): `GET /` renders the published
  `home` page (404-ish text if unseeded), `GET /page/{slug}` (published only, drafts 404),
  `GET /sitemap.xml` (published pages with `sitemap_include=true`, home always first; `lastmod` from
  `updated_at`), `GET /robots.txt` (Allow all + Sitemap line). Blade: `resources/views/layouts/site.blade.php`
  (sticky glassy header, mobile menu, skip-link, dark footer; links to `/app/login` + `/app/register`) +
  `resources/views/public/page.blade.php` (block switch: hero/features/cta/text; text paragraphs split on
  double newlines) + shared `resources/views/components/site/` (`button` variants, `eyebrow`,
  `section-heading`) + designed `resources/views/errors/404.blade.php`. The public site ships its own
  Tailwind v4 entry `resources/css/site.css` (`@source` scoped to the site views) and a framework-free
  `resources/js/site.js` (mobile menu + Escape/resize handling), both registered in `vite.config.js`.
  Blocks: `hero|features|text|richtext|cta` + `logo_cloud|stats|steps|split|testimonials|team|video`
  (media via `components/site/img.blade.php`, bulk-loaded by `App\Support\SiteMedia`, YouTube/Vimeo-only
  embeds) + `pricing` (live active `SubscriptionPlan` cards with `module_meta` labels, optional product
  filter) + `faq` (`<details>` accordion feeding the FAQPage schema) + `comparison` (yes→✓/no→— table).
  SEO ships in `components/site/seo-head.blade.php` (canonical, robots, og:*/twitter:*, favicon
  `public/favicon.svg`, theme-color) + `components/site/json-ld.blade.php` (Organization + WebSite on
  every page, BreadcrumbList on non-home pages via `$crumbs`, FAQPage from answered items). Pages live at top-level `/{slug}`
  (`site.page` route, registered last; `config/website.php` `reserved_slugs` can never become pages and
  404 in the controller regardless); legacy `/page/{slug}` 301s to the canonical URL, and the route
  fallback resolves `website_redirects` (`WebsiteRedirect`, auto-created on slug rename, `hits` counted)
  before the designed 404 — single-segment redirects resolve in `topPage()` because `/{slug}` matches
  before the fallback. The fallback lets `api/*`, `storage/*`, `build/*`, `up` keep their own 404 shape.
  Redirect CRUD at `api/system/redirects*` (`content.view`/`content.manage`). The `media` library
  (system table, public disk; raster-only uploads ≤5MB, SVGs refused, dimensions + alt recorded) backs
  public-site images via `api/system/media*` (`MediaController`: searchable list, alt update, delete removes
  the file); SPA `pages/MediaLibrary.jsx` at `/admin/media` + reusable `components/cms/MediaPicker.jsx`
  (browser mounts only while open, so no request fires until needed). Global site config lives in
  `website.*` platform settings via `App\Support\SiteConfig` (one `whereIn` query per render, no static
  memo): logo (Media row), tagline, header nav, footer columns, social profiles, GA4 + Search Console
  IDs, contact/sales emails, announcement bar, and raw custom head/body — all consumed by the layout
  (which self-loads `SiteConfig` so error pages render too) and edited on `pages/WebsiteSettings.jsx`
  at `/admin/site-settings` (`api/system/website-settings`, `SiteHref` rule rejects `javascript:`-style
  targets). `website_pages` also carries per-page `canonical_url`, `noindex` (excluded from sitemap),
  `og_title`, `og_description` (all editable in the page form's SEO section); drafts preview through a
  signed `preview/{page}` URL (`site.preview`, 24h, `X-Robots-Tag: noindex` — unsigned is 403); pages
  duplicate as drafts via `POST system/pages/{page}/duplicate` (`-copy` slug walk). The block editor
  gained a `richtext` type (shared TipTap `RichTextEditor`, HTML rendered server-side), and the page
  edit form always loads the full row via `show` — the list payload has no blocks, so editing from it
  would save back an empty list. The blog (`blog_categories` + `blog_posts`, cover via media FK, TipTap
  body, computed reading time, SEO parity columns) ships CRUD + publish lifecycle at
  `api/system/blog*` (SPA `pages/BlogPosts.jsx` at `/admin/blog` with inline category manager) and a
  public surface (`BlogController`: `/blog`, `/blog/category/{slug}`, `/blog/{post}`, `/blog/rss.xml`,
  signed `blog-preview/{post}`) with `BlogPosting` schema, related posts, and sitemap integration
  (posts + populated categories; noindex excluded). Contact/demo requests land in `marketing_leads`
  via unauthenticated `POST api/contact` (throttle + honeypot + queued `LeadReceivedMail` to the
  sales inbox — the mailable snapshots scalars and never names a prop `$message` (reserved in mail
  views for the message under construction; rendering it crashes the send)) and are worked in the
  SA inbox at `/admin/leads` (`api/system/leads*`,
  `leads.view`/`leads.manage` + `platform:sync-access` additive repair); the `contact_form` block
  posts via `site.js` fetch with the layout's CSRF meta and swaps to a thanks state inline. Content editing
  publishes live without code changes.

## Tenant intake (FB-3)
- A tenant is a **`draft`** row (no database, `provisioning_status=pending`) until every required field is in. `App\Services\Tenancy\TenantIntake` is the one definition (rules per step `business|admin|plan`, `state()` lists what is missing); both the Super Admin wizard and `POST /api/register` use it, so required data is identical. `TenantActivation::activate()` is the only draft → `pending` → provisioning door: it 422s listing missing fields, then queues `ProvisionTenantJob` with plan, trial (14 days = `onboarding.trial_days`; `0` = no trial) and the **default user** (`DefaultUserClaim` swaps the seeded `owner@{slug}.test` for the real account; the password travels only as a hash and is never stored in the draft).
- SA routes: `POST tenants` (creates the draft from name+slug), `GET|PUT tenants/{t}/intake` (draft only), `POST tenants/{t}/intake/submit` (needs `admin_password` + confirmation). `GET tenants/{t}` also returns `default_user` and `subscription`. SPA: `/tenants/new`, `/tenants/:id/setup` (`TenantIntake.jsx`) and public `/register` share `components/tenant/IntakeWizard.jsx`; `TenantDetail` is the edit page (profile, limits override, default user, subscription).
- **Card on file for trials (self-service only):** `onboarding.require_card_for_trial` (env `ONBOARDING_REQUIRE_CARD`, default off — set it to `true` with live Stripe keys). When on, plain `POST register` refuses a trial (422); the flow is `POST register/card` (validates the whole sign-up, parks a `draft` with the password as a **hash**, returns the hosted Stripe setup page — no database yet) → Stripe saves the card → `/app/register/complete?session_id&t` → `POST register/complete` (verifies the session is *this* draft's, the card is saved, then provisions, `ProvisionTenantJob` starts a Stripe subscription with `trial_period_days` against the card, signs the user in, wipes the hash/token). `CardCaptureGateway` (Stripe + Fake) owns the provider calls; `tenants:prune-drafts` (daily 03:30) removes drafts older than 7 days. Super-Admin-made tenants are exempt (they cannot enter someone else's card). `GET api/register/options` reports the effective `require_card_for_trial`.

- SA overview: `GET api/tenants/summary` (declared before `tenants/{tenant}`), `tenants?subscription_status=`; the Tenants page shows summary cards, a needs-attention list and a renews/trial-ends column.

## Domain events & webhooks (P2.1, P2.8)
- **Bus:** `DomainEvents::record($type, $subjectType, $subjectId, $data, $actorId)` writes a `domain_events` row (tenant DB, outbox) and queues `ProcessDomainEvent`, which hands it to every consumer in `config/domain_events.php` whose pattern matches (a failing consumer is logged, never thrown). `ActivityLogger::log()` is the funnel: every activity is also an event of the same name, and a move/edit into a done status additionally emits `task.completed`. `events:prune --all` (daily 03:45) removes processed events past 30 days.
- **Webhooks** (module `webhooks`, permission `webhooks.manage`, routes `api/webhooks*`): `webhook_endpoints` (secret encrypted, shown once, rotatable) + `webhook_deliveries` (one row per attempt state). `WebhookConsumer` creates a delivery per subscribed endpoint; `DeliverWebhook` posts the JSON envelope with `X-FlowSync-Signature: t=<unix>,v1=<hmac_sha256(secret, "t.body")>`, retries on `config/webhooks.php` backoff (1,5,30,120 min) as visible rows, and switches an endpoint off after 20 given-up deliveries. `WebhookUrlGuard` (https only, no credentials, ports 80/443/8080/8443, no private/loopback/reserved address — checked at save and at send; tests override DNS via `config('webhooks.resolver')`). SPA `/webhooks`.
- **Fixed a cross-tenant hazard on the way:** `TenantDatabaseManager::restore()` rebuilt the tenant connection config without purging the PDO, so leaving a `using($other)` block while the default connection was the tenant connection kept serving the other tenant's database (`TenantConnectionRestoreTest`).

## Sprints, backlog & agile reports (P4.2, P4.5)
- Module `sprints`. Migration `2026_10_27_000046`: `sprints` (planned → active → completed; one active per project), `sprint_task_events` (added/removed with the points at that moment — the scope log), `tasks.sprint_id` (raw `ALTER … REFERENCES`, SQLite-safe). `SprintService`: `start` records `committed_points`, `complete` records `completed_points` and sends unfinished top-level work to the backlog or a chosen planned sprint, a completed sprint is closed (a record), only top-level tasks of the project can be planned. API `projects/{p}/sprints…` (read = project member, plan/start/complete = `ProjectPolicy::settings`), `projects/{p}/agile-reports[?sprint=]`; the task list/board takes `sprint=active|none|<id>`.
- `AgileReports` computes everything from recorded data (no typed-in numbers): **burndown** from scope events × `task_status_history` per day against a linear ideal line, **velocity** from completed sprints, **lead/cycle time** (created→done; first in-progress→done, avg + median, 90 days) and weekly **throughput**. SPA: project **Sprints** tab (`SprintsPanel`: planning + reports with Recharts), board filter "Active sprint / Backlog".

## Workflow rules & status history (P3.1, P3.2)
- Migration `2026_10_26_000045`: `projects.enforce_workflow`, `task_statuses.entry_rules` (JSON list from `WorkflowGuard::ENTRY_RULES`: `assignee`, `due_date`, `estimate`, `story_points`, `subtasks_done`), `status_transitions` (`from_status_id` null = from any), `task_status_history`. Default behaviour is unchanged — any status to any status — until a project turns enforcement on (refused with an empty allow-list).
- `WorkflowGuard` is called by `TaskService::move()` and `update()` (so the board, the edit form and automation actions are held to the same rules); the edit form is judged on the task *as it will be saved*, so one edit can satisfy the rule it triggers. Open-blocker rules stay in `TaskService`. Every status change (and creation) writes a `TaskStatusHistory` row — the basis for cycle/lead-time reports. API: `GET|PUT projects/{p}/workflow` (write = `manageWorkflow`), `GET projects/{p}/tasks/{t}/transitions` (allowed targets + the reason each closed one is closed). SPA: `WorkflowRules` in the project Workflow tab.

## Automation (P3.4, P3.5)
- Module `automation` (project settings permission decides who manages rules; `ProjectPolicy::settings`). Tables `automation_rules` (project, trigger, conditions JSON, actions JSON) + `automation_runs` (unique `(rule_id, event_uuid)` = idempotent). `AutomationCatalog` is the single definition of triggers (`task.created|updated|moved|completed|assigned|commented|overdue|due_soon`), condition fields/ops and actions (`set_assignee`, `set_priority`, `move_to_status`, `add_label`, `add_comment`, `notify`), used by both validation and the builder UI (`GET automation/catalog`); `AutomationRuleController` also rejects references outside the project (foreign label, unknown status/priority/user).
- `AutomationConsumer` (on `task.*`) → `AutomationEngine`: conditions are AND; actions run as the rule's author (else the project lead) through `TaskService` so blockers/membership rules still apply, a failing action stops the rule and records a `failed` run; each change is logged via `ActivityLogger` tagged `automation_rule_id` + `automation_depth`, so a rule never re-runs on its own change and chains stop at depth 3. `automation:time-triggers --all` (hourly) emits `task.overdue`/`task.due_soon` only for projects with a listening rule, once per task per window. SPA: project **Automation** tab (`AutomationPanel`).

## Issue hierarchy (P4.1)
- `issue_types.hierarchy_level` (0 initiative, 1 epic, 2 standard, 3 sub-task; default 2, backfilled from `is_subtask`; `config/issue_types.php` seeds an `initiative` type) + `tasks.epic_id` (the epic link, `on delete set null`). The link is its **own column** rather than `parent_id` so the board (top-level = no parent) keeps showing the stories of an epic. Migration `2026_11_01_000050` adds `epic_id` with raw `ALTER … REFERENCES` (the `constrained()`-inside-`Schema::table()` SQLite rebuild would drop CHECK constraints — P5.1's lesson again).
- `app/Services/Tasks/TaskHierarchy.php`: `treeFor(Project)` over `topLevel()` + `HierarchyTree` (breadth-first nesting per level); `epicOptions()` = open epics of the project. Row scoping: `TaskScope` constrains the epic column too (`constrainQuery` merges `epic_id` into the visible-task rule per project), so an `_own`/`_assigned` view never leaks a sibling's epic.
- `TaskService::show` includes `epic {id,key,title,status_id}` and `canSetEpic`; create/update honour a validated `epic_id` (must belong to the same project and not be the task itself). API: `GET projects/{project}/hierarchy` (`ProjectHierarchyController`, `permission:workspaces.view` group, project-member policy). SPA: `HierarchyPanel` in the project **Overview** tab (grouped Initiative→Epic→Story/task tree) + a hierarchy picker in `CreateTaskModal`/`TaskDetail`.

## Products: TMS and HRMS sold separately (FB-4, option B)
- Plans carry `product` (`suite` = the legacy bundle, `tms`, `hrms`; six per-product Starter/Professional/Enterprise plans live in `config/subscriptions.php`) and a tenant holds **one subscription per product** (`subscriptions` unique `(tenant_id, product)`, migration `2026_10_22_000027`). `Tenant::subscription()` is the *primary* (suite > tms > hrms) for legacy readers; use `subscriptionFor($product)`. A tenant is on a bundle **or** per-product plans (`SubscriptionService::assertProductFits`; `splitBundle()` moves between them atomically — SA `POST tenants/{t}/subscription/split`).
- `TenantLimits` merges the plans (modules = union; a numeric key defined by several plans = the most generous, explicit null = unlimited) and answers `productEnabled($tenant, 'tms'|'hrms')`: `features_override.products.{product}` (SA `PUT tenants/{t}/products`: true/false/null=follow plan) wins, otherwise the subscriptions decide; no subscription = both. `hasModule()` denies every module of a switched-off product (`hrms.*` → HRMS; `branding`/`export.full` are platform-wide). `ensure_product:tms` gates the workspace/project/task core + dashboard + analytics + search/tasks + search/global + reports/overview (+ the v1 search/tasks); the whole HRMS route group carries `ensure_product:hrms` on top of its `hrms.*` module gates. Middleware priority runs the coarse product gate before the fine module gate, and every product denial carries `X-Product-Denied`. `/auth/me` carries `products` and `modules` (= `visibleModules`); the SPA renders a per-product shell for an HRMS-only tenant (HR sidebar from `utils/nav.js`, `ProtectedRoute product=`, `homeRouteFor` → `/hrms`, topbar `ProductSwitcher.jsx` for both-product callers).
- Intake/registration plan step takes `plan_id` (bundle) **or** `tms_plan_id`/`hrms_plan_id`; `ProvisionTenantJob` creates one subscription per plan and (with a saved card) one provider trial per product. Self-service Subscription page shows one card per product; cancel/renew take `product`.
- **One tenant DB, four migration sets (Tenant Core / TMS / HRMS / bridge).** Tenant Core (identity, roles, permissions, settings, activities, notifications, exports, domain events, webhooks, API tokens, 2FA) lives in `database/migrations/tenant` and always runs; the 13 TMS files moved to `database/migrations/tenant_tms/` (run only for TMS tenants); every HRMS table stays in `database/migrations/tenant_hrms/` (run only for HRMS tenants); the cross-product bridge (`hrms_task_links` + `add_source`, `goal_task_links` extracted from the HRMS perf migration) lives in `database/migrations/tenant_integration/` and runs only when BOTH products are on (its tables FK across the two schemas). The two mixed index passes were split core/TMS (`…000012…` keeps its basename for core with an existence-guarded TMS twin; `…000036…` the same) so a repair re-run is a no-op. `TenantDatabaseManager::migrateTenant($tenant, $products)` builds the path list from the products (`null` = `TenantLimits::coveredProducts()` — no subscriptions ⇒ both; provisioning passes the plans being subscribed) and flushes the `TmsSchema`/`HrmsSchema`/`BridgeSchema` probes afterwards. `TenantProductSync::ensure()` — called after `assign`/`startTrial`/`splitBundle` and the SA product switch — now handles both products: it migrates the missing sets, seeds TMS catalogs (`TenantProvisioner::seedTmsCatalogs()`) and/or HRMS defaults + `EmployeeBackfill`, idempotently; removing a product never drops data. `TenantProvisioner::seed()` gates TMS catalogs on `TmsSchema::present()` (shared identity/permissions always seed). Cross-set readers ask first: `TmsSchema` (probe `tasks`), `HrmsSchema` (probe `employees`), `BridgeSchema` (probe `hrms_task_links`) — GlobalSearch fan-out + per-method guards, `PlatformResourceTotals`, tenant `stats`, export CSVs (empty file, not 500), signed downloads (404), task-link/goal-link bridges (`ensure_product:tms` on top of the HRMS module gates so binding never 500s), checklist conversion (422 needs-both), goal evidence refresh (skips TMS metrics), team cards, work-log derivation. HRMS table tests `require` the new paths (`HrmsTaskLinkTablesTest` → `tenant_integration/`); `TenantModuleSchemaTest` pins the four sets (TMS-only / HRMS-only / suite / upgrade + catalog gating + 403s). Migrating the 102 PG dev tenants was a plain `tenants:provision` repair (moved basenames stay recorded → skipped; new split/bridge basenames run guarded).

## Support desk (FB-7)
- Tickets live in the **system** DB (`SupportTicket`, `SupportTicketMessage`; `tenant_id` + a snapshot of the requester) so staff never open a tenant DB. `SupportDesk` owns the rules: customer reply reopens a resolved ticket, a closed one refuses replies, a public staff reply moves open → `waiting_on_customer` and notifies the requester in their tenant DB (`support.reply|resolved|closed`), **internal notes** (`is_internal`) never leave the staff API. Tenant routes `support/tickets…` need `support.manage` (new catalog permission; run `tenants:provision` so existing admin roles get it) and treat another tenant's ticket as 404; staff routes `system/support/tickets…` are `super_admin`. SPA: `/support`, `/admin/support`.

## User management & CSV import (FB-8)
- `GET|PUT users/{user}` (name + email; email change also updates the central `tenant_users` routing row), roles via `PUT users/{user}/roles`. The SPA edits a user on `/users/:userId` (`UserEdit.jsx`), never inline in the list.
- `UserImporter` (`app/Services/Users/`): CSV columns `name,email,roles[,password]`, roles by slug or name separated by `|`/`;`, ≤500 rows, 1 MB. Routes (`users.manage`): `GET users/import/sample`, `POST users/import/preview` (dry run, per-line errors + the roles this admin may assign), `POST users/import` (`skip_invalid` to import only valid rows; otherwise any error → 422 with the report). Each row is checked with `GrantCeiling::assertCanAssignRoles`, the plan `users` limit and email uniqueness (tenant users, routing index, super admins); blank passwords are generated and returned once. One `users.imported` audit row.

## Stripe billing (FB-6)
- Env: `STRIPE_SECRET`, `STRIPE_KEY` (or the legacy `STRIPE_PUBLIC`), `STRIPE_WEBHOOK_SECRET`. With no secret the gateway returns mock sessions (dev/tests); tests use `Http::fake` (`StripeBillingTest`) or the fake gateway.
- `StripeGateway` (also `RecurringGateway`): paid plans check out in Stripe **subscription mode** — one Customer per tenant (`tenants.billing_customer_id`), one Price per plan (`subscription_plans.stripe_price_id`, re-created when amount/currency/cycle change), remaining trial days carried as `trial_period_days`, tenant/payment/plan ids copied into `subscription_data[metadata]` so renewal events can be tied back. The success URL returns `?session_id&payment_id&status`; **`Subscription.jsx` calls `POST billing/verify` with the session id** (the server re-reads the session from Stripe and refuses one whose `client_reference_id` is a different payment). Existing Stripe subscribers change plan in place (`changePlan`, prorated) instead of a second checkout; `POST billing/portal` returns the customer-portal URL; cancel/renew in the app call `setCancelAtPeriodEnd` at Stripe first.
- Webhook `POST api/webhooks/stripe` (signature + 5-min replay window + event-id dedupe): checkout/payment events keep the old per-payment path; `invoice.paid` (renewal → `Payment` row per invoice + period end), `invoice.payment_failed` (→ past_due), `customer.subscription.updated|deleted` go through `SubscriptionBillingSync`. Local webhook testing: `stripe listen --forward-to http://localhost/api/webhooks/stripe` and put the printed `whsec_…` in `STRIPE_WEBHOOK_SECRET`. Events to enable on the endpoint: the five above plus `checkout.session.completed`.
- Migration `2026_10_20_000025` (system DB) adds the three columns — run `php artisan migrate --database=system --path=database/migrations/system`.
- Not done: card capture at registration/trial start (`onboarding.require_card_for_trial` stays off); a card-only SetupIntent flow is the follow-up.

## Stripe billing v2 (catalog sync, scheduled changes, cards, restricted mode)
- **Transport:** `App\Billing\Stripe\StripeClient` is the only HTTP caller: bearer + form encoding, bounded retries on connection errors/429/5xx (`billing.stripe.attempts`), an `Idempotency-Key` (caller key + fingerprint of the params) on every write, failures as `BillingProviderException` (`retryable`). The exception handler in `bootstrap/app.php` answers 503 (retryable) / 502 with a generic message + `code: billing_unavailable` — Stripe's wording is logged, never shown. **Provider calls run BEFORE local writes** (schedule a change, cancel, card default), so an outage leaves nothing half-applied. Tests fake `Http` against `api.stripe.com/v1/*` and must also fake `products*`, `prices/*`, `customers*` (the catalog creates a product per plan).
- **Catalog:** `CatalogSync` keeps one Product per plan (`subscription_plans.stripe_product_id`), one active Price per (amount, currency, interval) (a changed amount creates a new price, makes it `default_price`, archives the old one), one Customer per tenant named after the organisation (`tenants.billing_customer_id`; `Tenant::updated` pushes a renamed organisation / billing email via `SyncStripeCustomer`). A deactivated plan archives product+price (subscribers untouched, nobody new can choose it — `is_active` is enforced by switch AND checkout); a deleted plan queues `ArchiveStripePlan`. Free plans never reach Stripe. `PlanController` store/update dispatch `SyncPlanToStripe` (unique per plan, backoff, error stored in `stripe_sync_error`, shown on the SA Plans page); `billing:sync-plans` (nightly 02:30) and `POST plans/{plan}/sync` reconcile. Only super admins mutate plans (`routes/web/platform.php`, persona map `api/plans*` → `billing.manage`); tenants read active plans with the Stripe fields hidden.
- **Price changes:** `PlanPriceChanges::announce()` (from `PlanController::update`) records a `plan_price_changes` row and one `subscription_price_notices` row per billed subscriber (active/past-due, or trialing WITH a card; platform-assigned plans and card-less trials are skipped) with `effective_at` = the first renewal at least `billing.price_change_notice_days` (30) after the announcement (a decrease: the next renewal), and emails the billing/contact addresses + tenant admins immediately (`BillingNotifier` → `BillingNoticeMail`, copy in `lang/{en,es}/billing.php`). An increase that reaches paying subscribers needs `confirm_price_change=true` (409 `price_change_confirmation_required` with the count otherwise). `billing:apply-price-changes` (daily 07:00) moves the provider subscription to the new price one day before the renewal with `proration_behavior=none`; a cancelled/changed subscription's notice is cancelled; a newer change supersedes a pending one and keeps the amount the subscriber pays today.
- **Scheduled plan changes:** `PlanChangeScheduler::request()` (used by `my-subscription/switch` AND the checkout shortcut) QUEUES a change for a billed, active, paid, in-period subscription: `subscriptions.pending_plan_id/pending_change_at` (= period end) + a Stripe subscription schedule (phase 1 current price, phase 2 new price, `proration_behavior=none`, `provider_schedule_id`), one `plan_change_scheduled` event + email. No overlap, one invoice per period, no proration. Trials, free plans, lapsed or unbilled subscriptions switch at once. Stripe performs the swap itself; `customer.subscription.updated` carrying the pending plan's price mirrors it locally (`SubscriptionService::applyScheduledChange`), `subscription_schedule.released|canceled|aborted` clears it, and `billing:apply-plan-changes` (hourly) reconciles missed webhooks (never applies ahead of the provider) and applies unbilled changes. `DELETE my-subscription/pending-change` (or picking the current plan) releases the schedule; cancelling the subscription drops it.
- **Cards:** `PaymentMethods` + `billing/payment-methods*` (list, hosted setup session, `complete`, `{pm}/default`, delete): every card id is checked against the tenant's own list, the default cannot be removed while a renewal depends on it, a default change also updates the provider subscriptions started with an explicit card. The default card's summary is cached in `tenants.settings.billing.card` (refreshed by list + `payment_method.*` webhooks) for `billing:card-expiry` (30 and 7 days before the end of the expiry month, once per threshold, billed tenants only). SPA: `components/billing/PaymentMethodsPanel.jsx`; its copy (and the new Subscription/Plans strings) lives in the `billing` locale file (`locales/{en,es}/billing.json`, keys `billing.sub.*` / `billing.plan.*`).
- **Webhooks** (`POST api/webhooks/stripe`, signature + 5-minute window): event-id replay screen + a per-event cache lock (`in_progress` ack for a concurrent delivery); renewals are idempotent per invoice (`invoice.paid` and `invoice.payment_succeeded` renew once) and the period only moves forward; receipts (`hosted_invoice_url`, `invoice_pdf`, `receipt_number`) are stored in `invoices.metadata` and exposed by `InvoicePresenter`; `invoice.payment_action_required` and `customer.subscription.trial_will_end` email the billing contacts; `charge.refunded` marks the payment refunded.
- **Trials and expiry:** a trial needs no card (`billing:trial-reminders` emails card-less trials 3 days before the end; Stripe's `trial_will_end` covers card trials). When `tenants:expire-trials` expires the tenant it can still sign in (`Tenant::canSignIn()` = serviceable OR expired; suspended/deactivated cannot) but `EnsureSubscribed` (`ensure_subscribed`, in the priority list and on every tenant route group) answers 402 `subscription_required` (+ `X-Subscription-Required`) for everything outside `config('billing.restricted_allow')` (auth, billing, plans, my-subscription, profile, notifications, support, export). The data is untouched; paying (`SubscriptionService::renew/assign`) moves expired → active and the gate opens on the next request. `/auth/me` carries `subscription_required`; the SPA (`AdminLayout`, `api.js` 402 handler) sends the person to `/subscription`.
- **Concurrency:** `BillingLock::for($tenant, …)` (re-entrant cache lock, 409 on contention) wraps checkout, plan change, cancel, renew and every card change. Tests: `StripeCatalogSyncTest`, `PlanPriceChangeTest`, `ScheduledPlanChangeTest`, `PaymentMethodsTest`, `ExpiredTenantAccessTest`, `BillingWebhookEdgeTest`, `BillingProviderFailureTest` (+ the older `StripeBillingTest`).
- Env: `STRIPE_SECRET`, `STRIPE_KEY`, `STRIPE_WEBHOOK_SECRET`; extra webhook events to enable: `invoice.payment_action_required`, `customer.subscription.trial_will_end`, `subscription_schedule.released|canceled|aborted`, `payment_method.attached|detached|updated|automatically_updated`, `charge.refunded`. After deploying run `migrate --database=system --path=database/migrations/system` and `php artisan billing:sync-plans`.

## Request correlation (P2.2)
- `App\Http\Middleware\AssignRequestId` is prepended to the global stack (`bootstrap/app.php`): it reuses an inbound `X-Request-Id` only if it matches `^[A-Za-z0-9._-]{8,64}$`, otherwise mints a UUID, binds it as shared log context (`Log::shareContext(['request_id' => …])`, so every log line of the request carries it) and echoes it on the response. Quote it when reporting a failure. Not yet in audit rows or queued-job payloads (follow-up).

## API tokens (P2.7, track/platform-engines)
- A user can mint **personal API tokens** (`GET|POST api/api-tokens`, `DELETE api/api-tokens/{apiToken}`, `GET …/logs`; gated `ensure_module:api` + `permission:api.manage` in the tenant domain group). Plaintext looks like `fst_<tenant_id>_<40 random>`; **only a SHA-256 is stored** (`fake`-able cast). A token carries `abilities` (from `config/api.php` — the only slugs a `/api/v1` route may gate on, currently `workspaces.view` and `hrms.view`), `can_write`, expiry ≤ `config('api.max_expiry_days')`, and per-token rate limit (default `api.default_rate_limit` 60/min, cap 600). Revoking the user or the token kills it; a token's effective rights are its abilities AND what its owner still holds (`token_can` + the same policies/row scopes the session controllers use).
- `App\Http\Middleware\AuthenticateApiToken` (`api_token`) is the bearer door for the `/api/v1` routes in `routes/web/platform_engines_v1.php` (required at top level in `routes/web.php` — it replaces the session trio for those routes only, **outside** `switch_tenant`/`auth`/`tenant`): it decodes the tenant id from the prefix, connects that tenant's DB *before* the hash lookup, loads the owner as the request user, refuses non-safe verbs for read-only tokens, and logs every call (incl. refusals) to `integration_logs`. Every bad credential answers the same 401 (unknown token, revoked token, suspended tenant — indistinguishable). `api/v1` endpoints: `me`, `projects`, `projects/{p}/tasks` (GET + idempotent POST), `search/tasks`, `hrms/employees` — each also needs `ensure_product/ensure_module` like its session twin.
- **Idempotency** (`EnforceIdempotency`, `idempotent`) keys post endpoints on the `Idempotency-Key` header + token + payload fingerprint → `api_idempotency_keys` (TTL `api.idempotency_ttl_hours` 24); a replayed key returns the stored response. `IntegrationLog` rows are pruned after `api.log_retention_days` (30).
- SPA: `components/settings/ApiTokens.jsx` on the Settings page (create shows the plaintext once, list + logs + revoke). `config/subscriptions.php` module `api` gates the whole surface; new migration `2026_11_02_000033` adds it to existing plan module lists.

## Security hardening (Phase 8, track/security)
- **Headers/CSP (P8.5):** `SecurityHeaders` (global) + `ContentSecurityPolicy`; `config/security.php`, `SECURITY_CSP_MODE=off|report_only|enforce` (default report-only). Allows self, inline script/style (theme bootstrap), Stripe js/api/frames, Google Fonts, the Reverb ws(s) origin from `config('reverb')`, and the Vite dev origin only while `public/hot` exists. nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy, HSTS on https only.
- **Feature flags (P8.7):** system `feature_flags`/`flag_overrides`; `FeatureFlags::enabled($key, ?$tenantId)` = tenant override → global switch → stable crc32 rollout bucket; unknown = off. Route gate `ensure_flag:<key>`; SA console `/admin/feature-flags`; `user.flags` in `/auth/me`.
- **2FA (P8.1):** self-implemented RFC 6238 `App\Support\Totp` (no package); `two_factor_credentials` lives in each tenant DB AND the system DB (encrypted secret, hashed-at-rest 50-bit recovery codes, replay-guarded step counter). Sign-in: password → `{two_factor_required:true}` + a `2fa.pending` session record → `POST api/auth/2fa/challenge` (throttle 10/min, 5 attempts). Enforce-per-role: `tenants.settings.security.two_factor_roles` (tenant, `roles.manage`) and platform setting `require_2fa_super_admin`; a required-but-unenrolled session is limited to enrolment by `EnsureTwoFactorEnrolled` (skips auth/me, logout, 2fa\*). SPA: Login step + `/security`.
- **Platform personas (P8.3):** `config/platform_access.php` (route→permission map + 4 seeded personas: Billing Admin/Support/Auditor/Operator). `EnsureSuperAdmin` is persona-aware — a persona account is denied on any unmapped super_admin route (fail-closed; `PlatformAccessTest::test_every_super_admin_route_maps_to_a_catalogued_permission` fails the day a new one is added). **No persona = unrestricted break-glass** (all existing super admins keep working). `platform:<slug>` middleware for explicit gates; assignment via `PUT system/users/{user}/platform-roles`.
- **Consent-based support access (P8.4):** tenant admin (`support.manage`) grants time-boxed read-only/write windows (`support_access_grants`, system DB, `config/tenancy.php` `support_access` caps: 72h max window / 120 min max session); impersonation accepts `grant_id` (caps mode + session length); revoking a grant ends its running session (401 `support_access_revoked`). Platform setting `require_support_consent` makes a grant mandatory before impersonation.
- **Audit hash-chain + retention (P8.6):** `audit_logs.hash/prev_hash` sealed by `AuditChain` on create (cache-locked, id order, never fails a write); `php artisan audit:verify`, `GET system/audit-logs/verify`, export carries hashes + `X-Audit-Chain-Head`; `audit:prune` (nightly 03:50) applies the plan limit `audit_retention_days` (starter 90/pro 365/business 730) and leaves `audit_log_tombstones`. Rows before the first seal report as `legacy`.
- Security routes live in `routes/security.php` (required from `routes/web.php`): `auth/2fa*`, `security/two-factor-policy`, `support-access*`, `system/two-factor-policy`, `system/support-access*`, `system/platform-roles`, `system/users/{user}/platform-roles`, `system/audit-logs/verify`, `system/feature-flags*`. `ImpersonationGuard` blocks writes to `auth/2fa*`, `security/*`, `support-access*` in write mode.

## Realtime (Reverb + Echo, Phase 0 base)
- Composer `laravel/reverb`; npm `laravel-echo` + `pusher-js` (+ `@dnd-kit/*` for the board, `recharts` for analytics, `@tiptap/*` + `dompurify` for the description editor). `BROADCAST_CONNECTION=reverb`.
- `app/Events/NotificationSent` broadcasts on `PrivateChannel('user.{id}')` (queued, `broadcastWith` includes `actor {id,name}` for client rendering). `app/Events/TaskSynced` broadcasts on `PrivateChannel('project.{id}')` (`broadcastAs('task.synced')`, queued). `app/Events/CommentSynced` broadcasts on `PrivateChannel('project.{id}')` (`broadcastAs('comment.synced')`, queued). Broadcast channel auth in `routes/channels.php`: `user.{id}` (self only), `workspace.{id}` + `project.{id}` (membership). Same checks used for realtime sync events.
- **`/broadcasting/auth` is registered in `routes/web.php`**, not via `withRouting(channels:)` in `bootstrap/app.php`: the framework would register it with the bare `web` group, so the session user resolves on the central connection (where tenant users don't exist) and every channel auth answers 403. It now runs `web → switch_tenant → auth`.
- **Queued jobs carry their tenant** (`App\Listeners\SwitchesTenantConnectionForQueuedJobs`): `AppServiceProvider` registers `Queue::createPayloadUsing()` to stamp `tenant_id` onto every job payload while a tenant context is active, and a `JobProcessing` listener connects that tenant's DB **before** the job command is unserialized (`SerializesModels` re-queries models at that point, so without this every queued broadcast — TaskSynced/CommentSynced/NotificationSent — failed with "relation tasks does not exist"). `JobProcessed`/`JobExceptionOccurred`/`JobFailed` release the connection again — except for `SyncJob` (`QUEUE_CONNECTION=sync`, `dispatchSync`), where the connection belongs to the dispatching request.
- **Realtime hardening (verified end to end against the docker stack: login → `/broadcasting/auth` 200 own / 403 foreign → WebSocket client received `task.synced`, `comment.synced`, `notification.sent` from real API calls).** Root causes fixed: (1) `routes/channels.php` was `require`d from `routes/web.php`, which `route:cache` (docker entrypoint) never executes → **zero channels registered → every `/broadcasting/auth` 403** — it is now required by `AppServiceProvider::boot()`; (2) pusher-js authenticated with the CSRF `<meta>` token baked into the page at load, stale after an SPA login (419) — `echo.js` now uses `authorizer: channelAuthorizer` (`utils/realtimeAuth.js`, posts through axios with the live XSRF cookie); (3) `NotificationSent`/`CommentSynced` were queued behind the worker — all five events are now `ShouldBroadcastNow` and fired through `App\Support\Realtime::send()`, which logs and swallows a dead Reverb server instead of 500-ing the write (never call `broadcast()` directly); (4) pushes missed during a socket outage are not replayed — `watchReconnects` emits `flowsync:realtime-reconnected` and `NotificationContext`, `ProjectDetail`, `CommentThread` and both support pages refetch on it; (5) listeners now unsubscribe with their own handler (the project channel is shared by board + comments) and `NotificationContext` leaves `user.{id}` on sign-out/impersonation. (6) `project.{id}`/`workspace.{id}` channel auth mirrors `ProjectPolicy::view`/`WorkspacePolicy::view` — tenant admin (`workspaces.manage`) OR member; membership alone left admins who open a project they don't belong to with a 403 and no live updates (one side of a two-user session updated, the other didn't). Docker note: containers run the **baked image** (`docker compose build app && docker compose up -d app queue reverb` after code changes; `REVERB_API_HOST=reverb` is what lets the app container reach the reverb container). Guards: `RealtimeResilienceTest`, `utils/realtimeAuth.test.js`.
- Client: `resources/js/echo.js` bootstraps `window.Echo` from `VITE_REVERB_*` env (`.env` only; `.env.example` has blank reverb keys so tests are unaffected).
- **Channel/event name rule:** events MUST broadcast on the channel clients subscribe to (`private-` prefix is applied by `PrivateChannel`/Echo automatically — never hardcode `private-` twice).

## RBAC & provisioning
- `config/permissions.php` — the canonical permission catalog (base slugs plus the generated scope
  variants below; ~99 entries today) + role definitions: `admin` = `*`, `editor`/`viewer` =
  self-service reads (`*_own`) plus broad non-HRMS grants, **`manager`** = `*_own` + `*_assigned`
  (team scope, stops at the reporting line), `hr_manager`/`payroll_manager` = prefix selectors. **R4 adds the named defaults the product brief uses:** `employee` (self-service `*_own` only), `hr_executive` (day-to-day HR, no payroll/compensation/sensitive documents), `finance_admin` (expenses + tenant-wide payslip/compensation reads + billing view), `auditor` (read-only views + audit trail + analytics, no `.manage`, no sensitive reveal). `editor`/`viewer` remain the legacy names existing tenants assign; `tenants:provision` creates the new roles in existing tenants and reassigns **no** user.
  A role's `permissions` is a list of *selectors* (`*`, `hrms.*`, `!hrms.payroll.*`, exact slug)
  resolved against the catalog by `app/Support/PermissionSelector` at provision time and
  snapshotted onto the role.
- **Scope variants (member-access plan, Phase B — additive and inert until Phase C).** A base
  permission with a "my rows" reading gets three suffixes, generated from `$scopeDomains` in the
  same config so a new domain is one line: `X.view_own` (rows about you), `X.view_assigned` (you +
  your direct reports' rows), `X.view_all` (every row). `app/Support/PermissionScope` answers
  *which grants satisfy a scoped check* — a wider scope, or the legacy unsuffixed slug, which has
  always meant `all`; `User::granted($slug)` is the scope-aware check while `hasPermission()`
  stays exact-match, so nothing changes for callers outside the `scopes` map. The single legacy
  exception is `hrms.payroll.view` (always "runs + own payslip" → answers `_own` only, declared in
  `legacy_scope_aliases`). `.manage` is deliberately **not** in the lattice: folding it in would
  widen e.g. `hrms.payroll.manage` into "read every payslip". `App\Services\ReportsTo::idsFor(User)`
  is the single definition of `_assigned` (one hop down `employees.manager_id`, direct reports' user
  ids; memoized per unit of work like `TenantLimits`, flush wired into the same boundaries). **The
  Phase C enforcement cutover (steps 9–11) is shipped**: `TaskScope` (Row scoping section) and HRMS's
  `App\Services\Hrms\HrmsScope` deploy the lattice — `widestView()`/`seesAll()`/`canRead()`/
  `employeeIdsFor()`/`coversEmployee()` are the one shared answer for every HRMS list and per-record
  policy (leave/expense/document/comp-off flipped; the attendance-month/leave-balance/comp-off-credit
  employee-keyed reads and the performance goal/check-in/1:1/feedback lists+show all intersect a
  client-supplied `employee_id` with the caller's real scope — spoofed params resolve to `[]`/403,
  never wider; leave exemptions + the leave team calendar stay
  manage-only / subtree-team by design). Before the alias is ever removed, `tenants:scope-grants
  --force` makes the implicit explicit: every role holding a legacy scoped base gets the variant(s)
  it has always meant (`X.view_all` + `X.view_own`; payroll `view_own` only), idempotently
  (`ScopeGrantBackfill`, `syncWithoutDetaching`).

### Own/Assigned/All Policy & Scope Matrix

| Domain | Grant Base | `_own` Scope | `_assigned` Scope | `_all` Scope | Policy / Service Enforcement |
|---|---|---|---|---|---|
| Tasks (TMS) | `tasks.view\|edit\|delete\|move\|assign` | Assignee or reporter = caller | Caller + direct reports (`ReportsTo::idsFor()`) | All project tasks | `TaskScope::rowMatches()`, `TaskScope::constrainQuery()`, `TaskPolicy` |
| Leave Requests | `hrms.leave.view` | Caller's own requests | Caller + direct reports' requests | All tenant requests | `HrmsScope`, `LeaveRequestDirectory::listFor`, `LeaveRequestPolicy` |
| Expense Claims | `hrms.expenses.view` | Caller's own claims | Caller + direct reports' claims | All tenant claims | `HrmsScope`, `ExpenseClaimController::index`, `ExpenseClaimPolicy` |
| Documents | `hrms.documents.view` | Caller's own files | Caller + direct reports' files | All non-confidential tenant files | `HrmsScope`, `DocumentService::listFor`, `EmployeeDocumentPolicy` |
| Performance Goals | `hrms.performance.view` | Caller's own goals | Caller + direct reports' goals | All cycle goals | `HrmsScope`, `PerformanceGoalController::index`, `PerformanceGoalPolicy` |
| Check-Ins & 1:1s | `hrms.performance.view` | Caller's own sessions | Caller + direct reports' sessions | All cycle sessions | `HrmsScope`, `CheckInController`, `OneOnOneController` |
| Attendance & Records | `hrms.attendance.view` | Caller's own month grid | Caller + direct reports' grids | All tenant employee grids | `HrmsScope`, `AttendanceRecordsController`, `AttendanceDayPolicy` |
| Comp-Off Requests | `hrms.comp_off.view` | Caller's own credits/asks | Caller + direct reports' credits/asks | All tenant comp-off asks | `HrmsScope`, `CompOffRequestDirectory`, `CompOffRequestPolicy` |
| Payroll & Payslips | `hrms.payroll.view` | Caller's own payslips | Caller's own payslips (1:1, never widened) | All tenant payslips (`view_all` explicit) | `PayslipPolicy`, `PayrollRunController`, `PermissionScope::legacyScope()` |

- `config/project_roles.php` — project-permission catalog (view/create/edit/delete/assign/move/comments/attachments/work_logs/members/settings + `tasks` scope variants `view|edit|delete|move|assign × {own,assigned,all}`, exported as `scope_domains`/`scope_text`; 35 slugs) + system roles lead/developer/viewer.**Project-role resolution is scope-aware like the tenant catalog** — `ProjectRole::hasPermission()` stays literal (`['*']` → all) while `ProjectRole::grants()` answers `satisfying()` like `User::granted()`, so a `tasks.view_all` grant satisfies a `tasks.view_own` request and the legacy `tasks.view` means `_all`. The default roles deliberately keep the UNSCOPED slugs (`legacy = _all`); narrowing a team is what a custom role is for. `TenantProvisioner::provisionProjectRoles()` now UNION-adds configured slugs into system roles (additions only, never revoke; `['*']` on either side wins) so a pre-variant provisioned role picks up new slugs on repair.
- `app/Support/TenantProvisioner.php` — clones catalog + roles + priorities + project roles + owner admin per tenant; **idempotent** (`firstOrCreate`, pins tenant context null internally), safe to call repeatedly. `seed()` gives a NEW config role its full permission set and thereafter only ever ADDS to an existing one (`syncWithoutDetaching` — a plain `sync()` used to revert tenant edits on every repair); config roles are flagged `roles.is_system` (migration `2026_10_19_000040`) and are **read-only through the API** — `PUT/DELETE roles/{role}` on one is a 422 `form`; a tenant customises via `POST roles/{role}/clone` (the copy needs the actor to hold every permission, `GrantCeiling`) and a custom role is deletable only while unassigned (`DELETE roles/{role}`; both audited) — custom tenant roles are never touched by provisioning — and `provisionProjectRoles()` **union-adds** config slugs into system project roles idempotently (additions only, `['*']` guard), so system roles pick up config additions on re-provision while a config edit can never revoke what a tenant already holds.
- **Access explainer (R14):** `GET api/users/{user}/access?check=<slug>` (`UserAccessController`, `permission:roles.view`) answers "why can't they?": which of the user's roles grant the slug — exactly, or through a wider scope / the legacy unsuffixed slug (`PermissionScope::satisfying`) — then the plan module the slug lives under (longest module key prefix; `hrms.*` falls back to `hrms.core`) via `TenantLimits::hasModule`, and a plain-language `reason`. Read-only. No SPA panel yet.
- `app/Models/Tenant.php`, `Role`, `Permission`, `User`; pivots `role_user`, `permission_role`.
- `TenantSeeder` = super admin + Acme (demo users) + Globex; called from `DatabaseSeeder`.

## Task-management services (Phase 0 shells, grown in P1+)
- `app/Services/ActivityLogger.php` — append-only polymorphic audit (`log()` + `forSubject()`).
- `app/Services/NotificationService.php` — `notify()` creates `notifications` row + broadcasts `NotificationSent` on the recipient's private channel; `forUser()` paginated list (with `actor`), `unreadCount()` / `markAllRead()`; scenario helpers `taskAssigned` / `taskCommented` / `taskStatusChanged` / `taskUnblocked` (each skips self-notification) and `mentionUsers()` (parses `@token` → tenant users matched case-insensitively by email, email local part, or name).
- `app/Services/KeyGenerator.php` — atomic per-project task key allocation.
- `app/Services/WorkspaceService.php` — workspace CRUD + members (see Workspaces section).
- `app/Services/ProjectService.php` — project CRUD + members + statuses/workflow (see Projects section).
- `app/Services/TaskService.php` — task CRUD + board/list/move + filters (see Tasks section); board/list/`show` payloads include `open_blockers_count` (`withCount('openBlockers')`), and `show` lazy-loads comments/attachments/subtasks counts. The service stays a thin orchestrator under the 300-line ceiling (`TaskServiceSplitTest`): writes share `Services/Tasks/TaskLifecycle.php` (WIP/blocker guards, status history, goal refresh) and updates run through `Services/Tasks/TaskUpdater.php` — all 12 public methods stay on the service.
- `app/Services/WorkLogService.php` — work-log CRUD + overlap/duration rules + task/project/workspace time summaries (see Time Tracking section).

## Impersonation (super admin → tenant user)
- Session key `impersonate` = `['log_id', 'tenant_id', 'original_user_id', 'original_user_name']`; then `Auth::login($target)` + `session()->regenerate()`.
- Controllers: `ImpersonationController@start` (super_admin | `POST api/impersonate`) and `@stop` (`POST api/impersonate/stop`, only `auth`, works while impersonating).
- **Tenant-local id collision gotcha:** `user_id` is a per-tenant DB id (every tenant's owner is local id
  1), so the routing lookup must be disambiguated by `tenant_id` — `start` accepts an optional
  `tenant_id` and scopes `TenantUserRouting` `where('tenant_id', …)`; the super-admin UI always sends it.
- Audit rows in `impersonation_logs` (`super_admin_id`, `tenant_id`, `impersonated_user_id`, `ip_address`, `started_at`, `ended_at`).
- Stop must resolve the original super admin from the system DB (`connectSystem()` before the lookup).
- Cannot impersonate super admins; `AuthController::me`/`logout` include impersonation state.
- **Controls (R7/R8):** `POST api/impersonate` requires a `reason` (8–500 chars) and takes `mode`
  (`read_only` default | `write`); both land on `impersonation_logs` with `expires_at`
  (`tenancy.impersonation.ttl_minutes`, default 30). `App\Support\ImpersonationGuard`, called from
  `SwitchTenant`, ends a lapsed session on its next request (401 `impersonation_expired`, log closed at
  `expires_at`), refuses every non-safe verb in `read_only` (403 `impersonation_read_only`), and even in
  `write` refuses roles/project-roles/users/my-subscription/billing/my-export/tenant-profile/onboarding/
  re-impersonation (403 `impersonation_blocked`); `impersonate/stop`, `auth/logout`, `broadcasting/auth` are
  always allowed. `tenants:close-impersonations` (every 10 min) closes sessions nobody touched again.
  `me()` carries `impersonation_mode` / `impersonation_expires_at`; the banner shows both; the SA UI collects
  the reason through `ImpersonateModal`. Tenant-admin audit rows (`AuditsTenantAdminActions`) carry
  `impersonator_id`. **Not yet attributed:** `ActivityLogger` and `HrmsAuditLogger` rows written while
  impersonating still show only the impersonated user (follow-up under R7).

## Protected default tenant user
- Migration `database/migrations/tenant/2026_09_26_000013_add_default_user_to_users_table.php` adds
  `users.is_default` (boolean, default false) **plus a partial unique index** so a tenant DB can have at
  most one default (`CREATE UNIQUE INDEX users_default_unique ON users (is_default) WHERE is_default = true`).
  The predicate **must** be `true`, never `1` — `is_default` is a boolean column and PostgreSQL rejects
  `boolean = integer` (sqlite silently accepts it, so this only explodes on the PG path). The migration is
  repair-safe (`Schema::hasColumn` + `DROP INDEX IF EXISTS` first) because `tenants:provision` re-runs
  migrations that failed partway.
- `TenantProvisioner::ensureDefaultUser()` runs on every `provisionIsolated()`: the default is the oldest
  **admin** (falling back to the oldest user) — so `owner@{slug}.test` is the default for freshly
  provisioned tenants.
- `App\Models\User` — `scopeDefault()`, `defaultUser()`, `fillable += is_default`, and a `deleting` hook that
  throws `ValidationException` (`form`) so the default can never be deleted by any code path.
- `UserController`: `updateRoles()` refuses to drop `admin` from the default user, `destroy()` refuses
  self-deletion, `makeDefault()` (422 `form`) requires the target to be an admin. `/api/users*` now sits in
  the **tenant_context group** (the users table lives in the tenant DB — a non-impersonating super admin
  gets 403 instead of a "no such table: roles" 500), and every user response carries `is_default`.
- `makeDefault()` must clear + set through the **query builder**, not `$model->update()`: shifting onto the
  user who is *already* the default is a no-op for Eloquent's dirty check, which would clear the flag and
  never re-set it, leaving the tenant with **no** default user (regression-tested in `DefaultUserTest`).

## Theming
- Per-user theme stored in `user_settings.settings['theme']`; defaults in `config/theme.php`.
- ThemeController `GET/PUT api/theme` (PUT requires `settings.theme` permission).
- CSS custom props are **hyphenated** (`--sidebar-bg`); `resources/js/theme.js` `applyTheme()` maps underscores→hyphens. Keep that mapping in sync.

## Internationalization (i18n)
- **English is the source of truth.** Catalogs live under `lang/en/` and `resources/js/locales/en/`;
  other locales are added per phase and only flip `complete => true` in `config/locales.php` after a
  native review. Phases 0-6 shipped: backend messages (`lang/en/*.php`), locale resolution +
  `PUT /api/language`, the SPA dictionaries + switcher, SPA nav labels (`utils/nav.js`/`utils/hrmsNav.js`
  are key lookups, never literals), the public marketing site chrome (`lang/en/site.php`), the
  remaining SPA chrome — the product-switcher title, the date-picker placeholders (`ui.selectDate`/
  `ui.selectDateTime`), the generic API error fallback (`errors.generic`, read via `i18n.t` in
  `services/api.js`), and the asset condition vocabulary (`utils/hrmsAssets.js` `ASSET_CONDITIONS` +
  `assetConditionLabel()`, labels in `hrms.assets.conditions.*`) so no raw column value renders — and
  **email copy** (Phase 6, `notifications.*`).
- **Spanish + completion (L1–L8, plan in `.agents/roadmap/i18n-completion-plan.md`):** `es` is now a
  `complete` locale. (L1) formatters are locale-correct — `formatRelativeTime` uses
  `Intl.RelativeTimeFormat` (only `ui.justNow` is a dictionary phrase), `formatPrice` reads
  `ui.pricePerMonth[Annual]`, and `formatDateOnly`/`formatTime`/`formatNumber` replace bare
  `toLocale*()` calls — never call those with no/`[]` locale. (L2) non-English SPA dictionaries are
  **lazy**: `i18n.js` globs `./locales/*/*.json`, `changeLocale(locale)` fetches the chunk then calls
  `changeLanguage` (use it, never `i18n.changeLanguage` directly, for a non-`en` target; `en` stays
  statically bundled and is the fallback). (L3–L5) `resources/js/locales/es/{common,auth,dashboard,tms,admin,hrms}.json`
  mirror `en` exactly (4,429 strings). (L6) `lang/es/{validation,pagination,site}.php`. (L7) every
  literal backend response string (`'message' =>`, `'form' =>`, `ValidationException::withMessages`
  values, `abort*()` messages) is wrapped in `__('English sentence')` — the English text *is* the key —
  and translated in `lang/es.json`; interpolated sentences use `:placeholder` args
  (`__('A :status case cannot be completed.', ['status' => …])`). **When you add a backend message,
  wrap it and add it to `lang/es.json`** (`TranslationParityTest::test_every_literal_backend_message_has_a_translation`
  fails otherwise). (L8) Guards: `tests/Feature/TranslationParityTest.php` (PHP group files + JSON
  coverage + placeholders) and `resources/js/locales/parity.test.js` (SPA keys + placeholders per
  non-`en` locale). The `es` text is a first-pass translation **awaiting native review** — the
  catalog's `complete` flag was flipped so the switcher offers it; set it back to `false` to hide it.
- **Email locale (Phase 6):** `TaskNotificationMail` composes its subject/line from
  `notifications.task.{assigned,commented,status_changed,unblocked}.{line,subject}` (unknown kinds fold
  to `notifications.task.other`) and pins the rendering locale (`$this->locale()`) so the view's
  `__()` labels resolve too. The recipient's language — `user_settings.locale` via
  `Locales::normalize()`, else the request/tenant `app()->getLocale()` — is resolved once by
  `NotificationDispatcher::localeFor()` and threaded in as a non-promoted `?string $copyLocale` (never
  the `Mailable::$locale` name); the copy is snapshotted at construction so no locale lookup rides the
  queue. `config/locales.php` is now the single language catalog (the old `config/notifications.php`
  `locales` map is removed); `LeadReceivedMail` stays English (recipient is the internal sales inbox).
- **Resolution:** `?locale=` → `user_settings.locale` → session `locale` → `tenants.locale` →
  `Accept-Language` → `config/locales.php` default. `SetRequestLocale` (alias **`locale`**) applies it
  and sets `Content-Language`; it guards `$request->user()`/`hasSession()`, so public routes fall
  through to session/Accept-Language/default. Public marketing + blog routes (plus `/{slug}` and the
  route fallback) run through it; `sitemap.xml`/`robots.txt` deliberately do not.
- **Public site:** `layouts/site.blade.php` renders a `#site-i18n` JSON island (contact-form messages)
  that `resources/js/site.js` reads via `readSiteI18n()`; Blade copy uses `__('site.*')`, and
  `og:locale`/JSON-LD `inLanguage` derive from the active locale. **CMS page/block bodies, blog post
  data, and `SiteConfig` nav/footer labels are deployment content and are never translated by code.**
- **Blade gotcha:** `@json([...])` splits its argument on commas (last segment = flags), so an inline
  array with commas compiles to broken PHP. Build it in `@php($x = [...])` and call `@json($x)`.

## Frontend conventions
- **UI/UX programme (plan: `~/.claude/plans/i-want-you-ti-shimmering-lagoon.md`, phases U1–U9).** U1 shipped: semantic colour roles in `resources/css/app.css` (`bg-surface`, `text-fg|fg-muted|fg-subtle`, `border-line`, `bg/text/border-success|warning|danger-*|info-*`, light + `.dark`; prefer these over literal gray/red/emerald steps — the `.dark` scale remap is legacy and goes away in U6), type-scale tokens (`text-display|h1|h2|h3|body|caption`, nothing below 12px), admin-colour contrast warnings (`contrastRatio`/`themeContrastIssues` in `theme.js`, shown in the theme drawer), default accent unified to `#2563eb` (config/theme.php = theme.js = app.css), Plus Jakarta Sans self-hosted via `@fontsource-variable/plus-jakarta-sans` (no Google Fonts links), and `ui/Icon.jsx` (Phosphor wrapper: size scale, decorative-by-default).
  U2 shipped: new primitives `PageHeader`, `FormField` (label/hint/error/required wiring — hand it ONE control), `Textarea`, `Checkbox`, `Switch`, `Tooltip`, `Menu` (ARIA menu button, `trigger` render-prop), `ErrorState`, `TableSkeleton`, `DataTable` (loading/error/empty slots, `aria-sort` sorting, row selection, mobile card fallback below `sm`); fixed `Tabs` (tablist/tab roles, arrow/Home/End, `label`/`count`/`icon`), `Badge`/`StatusPill` `tone` (success|warning|danger|info|accent|neutral — prefer over text-derived colour / hex), `Alert` (+`warning`, `role=alert` only for errors), `Modal` (backdrop closes on click that began on the backdrop; full-screen on phones), `Skeleton` (`decorative` for list bars), `Button` (`size="icon"`, AA warning variant), Toast (`role=alert` for errors, pause on hover/focus, errors linger 7s) and form fields (`border-line-input`, 3:1). **Not done from the plan:** `Combobox` (build it with its first real consumer in U5/U6) and `Button` default `type="button"` (changing it silently breaks every `<Button>` that submits a form — migrate call sites to explicit `type` first). `ui/primitives2.test.jsx` covers the above.
  U3 shipped (shell): skip link + focusable `<main id="main-content">` that receives focus on every client-side navigation; mobile drawer is `invisible` when closed, focuses in on open, closes on Escape, returns focus to the opener, and the rest of the page is `inert` while it is open (and it auto-closes when the viewport reaches `lg`); sidebar links are plain links in a labelled `<nav>` (no `menuitem` roles; collapsed links carry `aria-label`); topbar menus get arrow/Home/End roving via `hooks/useMenuNavigation`; global shortcuts via `hooks/useGlobalShortcuts` (`/` search, `?` sheet, `g` then `d|p|w|n`) with `ShortcutsModal` — inert while a dialog is open or a field has focus. `PageHeader` renders an `<h2>` (the Topbar owns the route `<h1>`); adopting it page-by-page, and the HR nav My-HR regrouping, are done with the page work in U5–U7.
  U4 shipped (data/form infrastructure): `hooks/useUrlState(defaults)` (filters/sort/page in the query string; defaults stay out of the URL; non-page changes reset `page`; used by `pages/Search.jsx`), `hooks/useUnsavedChanges(dirty)` (beforeunload + `await confirmDiscard()` for in-app exits — **drawers/modals only**: the app uses a classic `<BrowserRouter>`, which has no `useBlocker`; wired into `TaskDetail` with a dirty badge), `utils/optimistic.js` (`optimistic(setState, apply, request)` with rollback that never clobbers a newer write), `ui/Resource.jsx` (first-load skeleton → `ErrorState` with retry → content, keeps previous data on refetch). New pages should use `useResource`/`usePaginated` + `Resource`/`ErrorState` instead of hand-rolled `useState(loading)` + `api.get`; the bulk migration of the remaining ~80 call sites is folded into U5–U7 with each screen.
  U5 shipped (TMS board): `ProjectDetail.jsx` 1287→~930 lines (Overview/Members/Workflow tab bodies moved to `components/project/Project*Tab.jsx`, props passed explicitly — the page still owns the state; a full `useProjectBoard` hook is the remaining step); optimistic board moves (`utils/board.js` `moveCardInBoard` + `optimistic()` rollback — a refused move snaps back instantly, the success path still refetches to re-sync counts); `DragOverlay` preview (`TaskCard` now exports `CARD_CLASS` + `TaskCardBody`); per-column quick-add (`+ Add task`, Enter submits, `onQuickAdd(statusId, title)`), collapsible columns (persisted `flowsync.board.collapsed`), phone scroll-snap columns (`w-[85vw]`), 12px minimum text in cards. **Not done:** card multi-select → `BulkBar`, TaskDetail per-field inline save/prev-next, Dashboard/Reports `DataTable` conversion — carried to U6/U8.
  U6/U7 shipped (partial, honest scope): every remaining raw `<table>` outside a scroll container got one (+ `scope="col"` headers) and `eslint.config.js` now **warns** on raw `<table>` (`no-restricted-syntax`; 14 stragglers listed by `npm run lint` — migrate them to `Table`/`DataTable` when you touch the page); HR overview shows `components/hrms/SetupChecklist.jsx` (employees / org / holiday calendar / leave policies / salary template, each probed with a read the caller may make, hidden when complete or when a step's module/permission is missing). **Correction to the earlier note:** the `.dark` gray-scale remap in `app.css` is NOT being removed — hundreds of components rely on it; it stays as the dark-mode mechanism for legacy markup and the semantic tokens are for new code. Not done: raw `<select>`/`<input>`/`<button>` codemod, `PageHeader` adoption on every page, splitting `Leave/Attendance/Analytics/Holidays/Statutory/Compensation` (tracked for follow-up).
  U8 shipped (a11y hardening): light-mode `--color-gray-400` re-pointed to AA (#6b7280, 4.8:1) in `:root` so every `text-gray-400` meta/placeholder line passes (dark mode keeps its own remap); no `text-[10px]`/`text-[11px]` left (12px floor); a global `input|select|textarea:focus-visible` ring beats any `focus:outline-none` (WCAG 2.4.7); `vitest-axe` guards the primitives (`ui/a11y.test.jsx`, incl. a control test proving it detects violations; colour-contrast is covered by token maths in `theme.test.js` because jsdom has no layout). Not done: keyboard walkthrough of the dnd board in a real browser, 360/768/1280 visual QA.
- The Roles page (`pages/Roles.jsx`) groups the permission checkboxes by domain via
  `utils/permissions.js` (`domainOfPermission`/`prettyDomain`/`groupPermissionsByDomain` — the domain
  is everything before the last slug segment, so scope variants keep grouping without a server change);
  shared `PermissionGrid` renders create-modal + edit grids. Phase B3 of the member-access plan.
  **Product-scoped catalog:** `GET /api/roles` returns only the shared prefixes plus the tenant's
  subscribed products (`App\Support\ProductCatalog`: `hrms.*` → hrms, dashboard/users/roles/settings/
  billing/content/support/tenant/webhooks/api → shared, rest → tms), each row labelled
  `product: tms|hrms|shared` and badged in the grid; `store/update/clone` refuse out-of-product ids
  with a 422 on `permissions`. Platform super admins without a tenant context keep the whole catalog.
- Routing in `resources/js/App.jsx`: `GuestRoute` (login/forgot/reset/register), `ProtectedRoute` (optional `permission` prop), super-admin-only `/tenants` route.
- **Landing route = `homeRouteFor(user)`** (`utils/deepLinks.js`): `/admin` for a non-impersonating super admin, `/dashboard` otherwise. Used by `/`, `GuestRoute`, and `Login` (so an SA never lands on the tenant dashboard, whose endpoints 403 without a tenant context); `App.jsx` also renders `/dashboard` as a `<Navigate>` to `/admin` for SAs (old bookmarks/typed URLs).
- `context/AuthContext.jsx`: `user`, `theme`, `loading`, `login`, `logout`, `stopImpersonation`, `can()`, `hasModule()`, `check()`, `refresh`. `can()` returns true for super admin unless `user.impersonating`; tenant users rely on `user.permissions`. `hasModule(module)` gates plan modules; `check(capability)` is the combined guard — `'permission:slug'` or `'module:name'` prefixes (backend `me()` fills `user.modules`).
- `context/ThemeContext.jsx` (live draft + save/reset), `context/ToastContext.jsx` (success/info/error). `useToast()` returns the toast **object** (`toast.success/error/info`) — never `const { toast } = useToast()` (that yields `undefined` and crashes every call site).
- `services/api.js`: axios base `/api`, `withCredentials`, `withXSRFToken`; on 401 redirects to `/login`; `fieldErrors()` helper.
- In `AdminLayout`, **only the page-content div is keyed by `location.pathname`** — never wrap the whole `<Routes>` (causes remount blink).
- Breadcrumbs: `context/BreadcrumbContext.jsx` (`useSetCrumbs`) + `components/Breadcrumbs.jsx` (renders account crumb = tenant name → `/workspaces`, super admin → `/tenants`). AdminLayout wraps content in `BreadcrumbProvider`. Pages set crumbs via `useSetCrumbs([{label, to?}])` in an effect; ProjectDetail uses `project.workspace` + `project.name`.
- `ImpersonationBanner` renders when `user.impersonating`; sidebar gets `top:2.5rem; height:calc(100%-2.5rem)` and the sticky topbar gets `top-10` to sit below it.
- Workspaces UI: manageability of a workspace is decided client-side with `can('workspaces.manage') || ['owner','admin'].includes(my_role)`; member/label write endpoints return `{message}` only, so pages refetch the list/`/users` after a mutation; the add-member user picker (GET `/api/users`) is gated by `users.view` and skipped when the user lacks that permission.
- Projects UI: manageability is `can('workspaces.manage') || my_role === 'lead'`; project members are role-selected via GET `/api/project-roles`; category selects drive workflow edits; member/status write endpoints return `{message}` only, so the page refetches project+members+statuses after a mutation. Sidebar has a global **Projects** nav item (gated `workspaces.view`) → `pages/Projects.jsx` at `/projects` (grouped by workspace, `GET /api/projects`). The add-member picker fetches `/api/users` on mount (gated `users.view`, skipped when lacking) so the select is populated without a prior mutation.
- **Board column settings (kanban):** a "Columns" button on the board opens `components/tasks/ColumnSettingsDrawer.jsx`. *Shared* column edits (rename/colour/category/WIP limit/reorder/add/delete — delete stays blocked while a column has tasks) are project leads + tenant admins only, through the existing `StatusController` (`manageWorkflow`); column headers get a ⋯ `Menu` for managers. *Personal* view settings (hidden/collapsed columns, width compact|default|wide, density compact|comfortable, hide-empty) live server-side in `board_preferences` (tenant_tms migration `2026_11_04_000052`, unique user+project) via `GET|PUT projects/{p}/board-preferences` (`BoardPreferenceController`, any project viewer, own row only, hidden/collapsed ids pruned to real statuses) and `hooks/useBoardPreferences` (optimistic, 400 ms debounced save). Guards: `BoardPreferenceTest`, `tasks/ColumnSettings.test.jsx`..
- Tasks UI: ProjectDetail's **Tasks** tab owns board/list state (`view`, `filters`, `board`, `listTasks`, `taskOptions`, `selectedTask`); tasks load lazily (`tab === 'tasks'`) and refetch on `view`/`filters` change and on `task.synced` broadcasts. Board/list modal+drawer (`CreateTaskModal`, `TaskDetail`) take `options` from the response's `filters` payload; opens fetch full detail via `GET tasks/{task}` (`openTask`), saves refetch the board (`updateTask`/`deleteTask`/`createTask` throw on error so forms can surface `fieldErrors`). Drag-drop posts `{status_id, index}` then refetches.
- Notifications UI: `NotificationBell` in the Topbar (badge = unread count, dropdown = latest 8 with avatar/message/relative-time, click marks read + navigates to the task via `notificationHref`) and `/notifications` page (paginated list, per-item mark-read on click, "Mark all as read", breadcrumb). Live updates come from `NotificationContext` (Echo `user.{id}` `.notification.sent` → toast via `describeNotification`; 30s unread poll fallback when Reverb is off). The provider **skips the poll + Echo subscription for a non-impersonating super admin** (no tenant DB ⇒ no notifications, mirrors the backend short-circuit).

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

## HRMS module (Phase 15, in progress — branch `new/hrms-development`)
- **Read `docs/hrms-implementation-plan.md` before any HRMS work.** It is a 22-phase, task-level plan
  (Parts 0-9) written to be executed one small task per commit, and it is the authoritative spec.
- The plan's binding design decisions (all in its Part 2): `hrms.*` **dotted module keys** stored in
  `plans.limits.modules` (so `TenantLimits::hasModule()` + the existing `ensure_module` middleware work
  unchanged); a new `business` plan inserted between `pro` and `enterprise`; per-tenant SA switching via
  `tenants.features_override.modules` (**additive** — it can grant, never revoke); `employees.user_id`
  as a **unique nullable** FK to `users` (a user with no employee row is a service account);
  `config/hrms.php` as the per-tenant defaults catalog seeded by a new
  `HrmsDefaultsProvisioner::provision()` step; and three shared primitives built once in the
  plan's Phase 1 — a generic `approvals`/`approval_steps` engine, an append-only `hrms_audit_logs`
  (separate from `activities`), and a single-row `hrms_settings`.
- **Shipped so far.** P1.1–P1.7 (entitlement foundation: `hrms.*` module keys, 47 permissions, plan
  module lists, per-tenant SA switching, `GET|PUT api/tenants/{tenant}/hrms`); P1.8–P1.10 (the three
  shared primitives: `2026_09_27_000014` migration, `HrmsAuditLogger`, the generic `ApprovalService`,
  and the HRMS defaults seeding hook); P1.11 (the module-gated `/hrms` SPA shell); P2.1
  (`2026_09_27_000015` — `employment_types`, `employees`, `employee_status_history`); P2.2 (the
  employee domain: enums, models, and the service — see the P2.2 notes below); P2.3
  (`EmployeePolicy` + `EmployeePresenter` + the `Requests/Hrms` family + `Hrms/EmployeeController` +
  routes, with signed photo download); P2.4 (`SensitiveFieldRedactor` masking + `EmployeeAccessLogger`
  + `EmployeePhotoService`, with the payload keys identical across both privilege levels); P2.5–P2.6
  (the SPA directory + `EmployeeDetail`/`StatusHistory`/`EmployeeEditModal`); P2.7
  (`EmployeeBackfill` + `hrms:backfill-employees`); P3.1 (`2026_09_27_000016` — `departments`,
  `designations`, `locations` + the three nullable `employees` FKs); P3.2 (the org models and the
  `app/Services/Hrms/Org/` bounded context — see the P3.2 notes below); P3.3 (the org HTTP surface —
  `app/Policies/Hrms/Org/`, `app/Http/Requests/Hrms/Org/`, `app/Http/Controllers/Hrms/Org/`, the
  routes, and the three org FKs finally made writable on the employee — see the P3.3 notes below);
  P3.4 (the org SPA page: `pages/hrms/Org.jsx`, `components/hrms/DepartmentTreeColumn.jsx`, the three
  form modals and the members panel, plus the directory's department filter — see the P3.4 notes);
  P3.5 (the org starters: the HRMS defaults seeder gains `seedOrgCatalogs()` — see the P3.5 notes);
  **P13.1** (`2026_09_27_000016_create_hrms_document_tables` — `document_types` +
  `employee_documents`, plus `tests/Feature/HrmsCatalogTest.php`, the cross-catalogue guard — see
  the P13.1 notes below); and
  **P13.2** (the document bounded context: `Services/Hrms/DocumentService.php` plus `Services/Hrms/Document/`,
  the `DocumentType`/`EmployeeDocument` models, `seedDocumentTypes()`, the signed `hrms.documents.download`
  route, and `hrms:documents-expiry` — see the P13.2 notes below); and **P13.3** (the document HTTP
  surface — `Policies/Hrms/Document/`, `Requests/Hrms/` document pair, `Controllers/Hrms/`
  document pair, and the routes — see the P13.3 notes below); and **P13.4** (the document SPA:
  `pages/hrms/Documents.jsx`, `pages/hrms/MyDocuments.jsx`, `components/hrms/DocumentUploader.jsx`,
  plus the profile `documents` tab — see the P13.4 notes below); P4.1
  (`2026_09_27_000017` — the onboarding/offboarding lifecycle tables, plus
  `tests/Feature/HrmsLifecycleTablesTest.php` — see the P4.1 notes below); P4.2
  (the onboarding/offboarding services: `OnboardingService` + `OffboardingService`
  orchestrating `Services/Hrms/Lifecycle/`, the shared `DocumentRequestService`,
  and the terminate → auto-initiate wiring — see the P4.2 notes below); P4.3
  (the lifecycle HTTP surface: `Policies/Hrms/Lifecycle/` bases + markers,
  ten FormRequests, four controllers, `LifecyclePresenter`, routes — see the
  P4.3 notes below); P4.4 (the lifecycle SPA: shared `Checklist.jsx`, case pages,
  `TemplateEditor.jsx` — see the P4.4 notes below); P4.5 (lifecycle notifications:
  `onboardingTaskDue`, `hrms:onboarding-reminders`, deep links — see the P4.5
  notes below); P5.1 (`2026_09_28_000018` — the attendance tables plus raw-SQL
  `employees.shift_id`, with the CHECK-preservation test — see the P5.1 notes
  below); P5.2 (the punch service: `AttendanceService` orchestrating
  `Services/Hrms/Attendance/` (`PunchClock`, `DayComputation`, `DayReading`),
  the models/enums, and the `attendancePunches()` relation — see the P5.2
  notes below); P5.3 (remote clock-in policy + punch endpoint: module-or-ownership
gates, server-observed IP, per-section settings merge, `DayPresenter` — see
the P5.3 notes below); P5.4 (regularization via the shared approval engine:
manager step from `ReportingLine::managerOf()`, supersede-don't-edit apply,
policy-decides-from-the-step, `Regularization*` bounded context — see the
P5.4 notes below); P5.5 (scheduled roll-up: `hrms:attendance-rollup` +
`AttendanceRollupJob` over one `AttendanceService::rollup()` loop — see the
P5.5 notes below); P5.6a (attendance reads: month grid, today widget payload,
and audited CSV export behind one `AttendanceDayPolicy`); P5.6b (the
attendance SPA: `Attendance.jsx`, `AttendanceApprovals.jsx`, `ClockInWidget`,
`AttendanceCalendar`, regularization notification branches); P6.1
(`2026_09_28_000019` — the leave tables: types/policies/balances/ledger/
requests/days/exemptions, plus `HrmsLeaveTablesTest`); P6.2a (leave enums,
models, conformant catalog, starter seeding + `HrmsCatalogTest` guard);
P6.2b (`LeaveBalanceService`: idempotent accruals, signed rebuild with
cap/floor, availability reserving pending only); P6.2c (`LeaveRequestService`
+ `Decisions` + `Validation` + `LeaveCalendar`: ask/decide/cancel/encash,
attendance `leave` merge); P6.3 (`LeaveApprovalRouting`: manager → head →
HR, no self-approval); P6.4a (catalog HTTP + `hrms.leave` gate, R18 route-group
finding); P6.4b (balances read + managed accrue runs, `LeaveBalanceReading`
split); P6.4c (asks/exemptions HTTP, per-record policies); P6.5 (availability
+ team-calendar reads, `Leave.jsx` admin hub, `MyLeave.jsx`, request modal);
P6.6 (`leaveRequested`/`leaveDecided` notifications + deep links); P7.1
(`2026_09_29_000020` — comp-off tables + `HrmsCompOffTablesTest`); P7.2
(`CompOffCredits` + `CompOffService`, `hrms:comp-off-accrue`, comp-off
settings column follow-up); P7.3 (comp-off HTTP surface + `hrms.comp_off`
gate); P7.4 (`CompOff.jsx` + `MyCompOff.jsx`); P7.5 (comp-off
notifications); P8.1 (`2026_09_29_000021` — holiday tables +
`HrmsHolidayTablesTest`); P8.2 (`HolidayService` + assignments + seeder);
P8.3 (holiday merge into attendance/leave/comp-off, full-suite chunks);
P8.4a/b/c (holiday HTTP + `Holidays.jsx`); P9.1 (`2026_09_30_000022` —
payroll core tables + `HrmsPayrollTablesTest`); P9.2a/b (`Money` + payroll
enums/models/catalogs + `CompensationService`/`SalaryRevisionService`);
P9.3a (run/payslip/adjustment models + attendance/leave read edges +
`HrmsPayrollReadsTest`); P9.3b (`Payroll/` bounded context: `PayslipCalculator`
LOP math + `PayrollService` runs + `PayrollLifecycle` transitions +
`payrollPublished` notifier + `HrmsPayrollEngineTest`); P9.4 (payslip reads:
`PayslipPolicy` + `PayslipReading` with per-read access rows + `PayslipRenderer`
via seeded `payslip_templates` + signed `hrms.payslips.download` +
`HrmsPayslipAccessTest`); P9.5a (compensation writes: `CompensationCatalogService`
+ component/structure policies + `Salary{Component,Structure,Assignment}Request`
+ the three payroll controllers + `HrmsCompensationApiTest`); P9.5b (run writes:
`PayrollRunPolicy` + `Payroll{Run,Adjustment}Request` + run/adjustment controllers
+ self-scoped `my-payslips` + `HrmsPayrollRunApiTest`); P9.6 (`Compensation.jsx`
+ `Payroll.jsx` + `PayrollRunDetail.jsx` + `MyPayslips.jsx` + sidebar/routes); P10.1
(`2026_10_03_000023` — statutory tables + `HrmsStatutoryTablesTest`); P10.2
(`StatutoryEngine` pure table-driven PF/ESI/PT/LWF/TDS + `StatutoryEngineTest`
fixture corpus); P10.3 (`StatutoryResolver` jurisdiction gates + calculator
fold + `recomputeStatutory` + `hrms:statutory-recompute` +
`HrmsStatutoryIntegrationTest`); P10.4 (`StatutoryService` TDS projection +
surrender + `HrmsTdsProjectionTest`); P10.5 (`StatutoryProfile` masking +
`StatutoryProfileService`, array-form policy binding, manage-only reveal with
access rows + `HrmsStatutoryProfileTest`); P10.6a (rulebook + declaration HTTP:
`StatutoryDeclarationService`, both policies, `Statutory{Configuration,Declaration}Request`,
both controllers + `HrmsStatutoryConfigApiTest`); P10.6b (TDS + recompute HTTP:
`TdsProjectPolicy`, `Tds{Projection,Surrender}Request`, `TdsProjectController` +
`Statutory.jsx` + `HrmsStatutoryTdsApiTest`); P11.1 (`2026_10_04_000027` — expense tables +
starter catalogue + `HrmsExpenseTablesTest`); P11.2 (`ExpenseService`
file/submit/decide/reimburse + payroll hook + expense notifications +
`HrmsExpenseServiceTest`); P11.3a (expense HTTP: `ExpenseCategoryService`,
claim + category policies, the four expense requests, both controllers +
`HrmsExpenseApiTest`); P11.3b (`Expenses.jsx` + `MyExpenses.jsx` +
`ExpenseClaimModal.jsx` + notification branches + shell pin); P12.1
(`2026_10_05_000028` — performance tables + `HrmsPerformanceTablesTest`); P12.2
(`PerformanceService` evidence engine + `hrms:performance-evidence` +
`HrmsPerformanceEvidenceTest`); P12.3 (`PerformanceCycleService` stage
machine + feedback generation + completion notifications +
`HrmsPerformanceCycleTest`); P12.4a/b (performance HTTP: cycle/goal/note/
1:1 + feedback/review/evidence surfaces + `HrmsPerformanceApiTest`); P12.5
(`Performance.jsx` + `PerformanceCycleDetail.jsx` + `MyPerformance.jsx` +
`GoalCard`/`CheckInComposer` + nudges); P12.6 (goal↔task attach/detach +
`HrmsGoalTaskLinkTest`); P14.1 (`2026_10_07_000030` — asset tables +
`HrmsAssetTablesTest`); P14.2 (`AssetService` + handover service + exit
gate + `hrms:assets-overdue` + `HrmsAssetServiceTest`); P14.3a/b (asset
HTTP + `Assets.jsx`/`MyAssets.jsx` + nudges + `HrmsAssetApiTest`);
P15.1 (`000031` inbox reads + `InboxService` + `HrmsInboxTest`); P15.2
(inbox HTTP + `HrmsInboxApiTest`); P15.3 (clearance/document/cycle/share
notifiers + `HrmsTaxonomyTest`); P15.4 (`Inbox.jsx` + badge + copy/links +
shell pin); P16.1 (`2026_10_09_000032` — survey tables +
`HrmsSurveyTablesTest`); P16.2 (`EngagementService` + templates +
anonymity + `HrmsEngagementTest`); P16.3a (survey HTTP: both policies,
four requests, both controllers, `hrms:surveys-open-close` +
`HrmsSurveyApiTest`).
Phase 13
  was jumped to *before* Phase 4 because P4.1's `document_requests.document_type_id` is a real FK to
  `document_types`, and no migration created that table until P13.1 — the plan's "documents ship
  first" prerequisite is a hard schema dependency, not a preference.
- **Migration numbering is pre-assigned** in the plan's Part 3.3 (`2026_09_27_000014` for the shared
  tables, through `2026_10_04_000031`; later phases extended the series beyond the pre-assignment —
  the tenant set currently reaches `2026_10_17_000039`), one monolithic tenant migration per phase — except the
  document tables, renamed from their assigned `000026` to `2026_09_27_000016`. Five later files
  (lifecycle, leave, comp-off, payroll, statutory) hold FKs into `document_types`/`employee_documents`
  and PostgreSQL validates the referenced table at `CREATE` time, so the later number breaks every
  fresh provision with `relation "document_types" does not exist"` while SQLite tolerates the forward
  reference — which is why only the PG path ever saw it. The file is `hasTable`-guarded, so an
  already-migrated database re-runs it as a no-op and `tenants:provision` re-records it under the new
  name. Table tests `require` migration files by their **literal filename**, so a rename must update
  them in the same commit.
- **`Schema::getColumns()` spells "no default" two different ways.** SQLite *omits* the `default` key
  for a column that has none; PostgreSQL returns the key with a `null` value. Written as
  `($c['default'] ?? 'sentinel') === null`, every column reads as optional and a "required columns"
  assertion passes while checking nothing. Use
  `! array_key_exists('default', $c) || $c['default'] === null`, and verify on **both** grammars. This
  is the dual of the existing "PostgreSQL-only SQL slips past the sqlite fast-path" note, and it bites
  the tenant test suite specifically because `IsolatesDatabase` is sqlite-only — nothing in
  `php artisan test` ever exercises the PG shape.
- **Chain `unique()` BEFORE `constrained()` on a `foreignId`.** `constrained()` returns through
  `references()->on()->foreign()`, so any modifier chained after it is **silently dropped** — the FK is
  emitted with no unique index and no error. `employees.user_id` shipped with this bug and enforced
  nothing until `HrmsEmployeeTablesTest::test_a_user_can_back_at_most_one_employee_record` caught it.
- **P2.2 (employee domain) — bounded contexts, not top-level files.** The plan's literal
  `app/Models/Hrms/Employee.php` / `app/Services/Hrms/EmployeeService.php` paths are superseded by
  D2.16.2 folder-per-context, matching P1.9: `app/Models/Hrms/Employee/{Employee,EmploymentType,EmployeeStatusHistory}.php`,
  `app/Services/Hrms/Employee/{EmployeeService,EmployeeDirectoryQuery,EmployeeUserProvisioner,ReportingLine,EmployeeStatusTransition}.php`
  + `ValueObjects/EmployeeProfile.php`, `app/Support/Hrms/EmployeeCodeGenerator.php`, and the
  `EmployeeStatus`/`WorkMode` enums. `EmployeeService` stays a thin orchestrator; the rules live in
  `ReportingLine` (self/cycle rejection) and `EmployeeStatusTransition` (record + history row + exit
  date in one transaction).
- **Read `QueryException::errorInfo[2]`, never `getMessage()`, to identify a unique violation.**
  Laravel appends the failing statement to the message, and every insert into `employees` *names*
  `employee_code` — so matching the full text reports a violation on `user_id`/`email` as a code
  collision, retries it five times, and finally blames a code failure that never happened.
  `EmployeeCodeGenerator::isCodeCollision()` reads the driver message only.
- **The inline-hire path is deliberately ordered, not atomic.** `users` is in the tenant DB and
  `tenant_users` routing is central, so no transaction covers both; the login is written *before* the
  employee row because a missing routing row makes a hire un-signable-in and unrepairable from the UI,
  where a spare account is a visible, deletable record. `TenantProvisioner::syncRouting()` is the
  repair path. `EmployeeUserProvisioner` resolves roles *before* inserting the user — validating after
  leaves a real account with no roles and no employee to explain it.
- **Carbon 2 `diffInYears` is signed and returns a float.** `Employee::tenureOn()` wraps it in `abs()`
  *and* casts to `int`; without either, a past joining date returns a negative span and the `?int`
  return type deprecates on PHP 8.3.
- **`exited_reason` is stamped by the offboarding transition, and re-stamped on a
  re-departure.** A rehired-then-dismissed person did not leave on the first
  episode's date; the first departure keeps its own date and reason in
  `employee_status_history`. Reactivation touches neither field — erasing the
  record of the exit is not what "reactivated" means.
- **HRMS HTTP surface lives in `app/Http/Controllers/Hrms/`** (not the plan's `Api/Hrms/`) matching
  this repo's flat controller root, behind `ensure_module:hrms.core` + `permission:hrms.view` with
  `EmployeePolicy` deciding per record. `view` includes **self** — no tenant permission grants
  reading your own record, and every later "my payslip" surface depends on it. `EmployeePresenter`
  **omits** the personal fields for a reader without `hrms.documents.view_sensitive` and sets
  `restricted: true` rather than blanking them. `EmployeeUpdateRequest` **rejects** `status`,
  `manager_id`, `employee_code`, `user_id` and the login fields by name — dropping them silently
  would answer 200 to a client that thinks it changed a status.
- **Never put `sometimes` before a conditional `required`.** `sometimes` means "skip the remaining
  rules for this field when the key is absent", so `['sometimes', Rule::requiredIf(...)]` skips the
  very rule meant to fire — the inline-hire `password` rule was dead and every hire got no password.
- **Every HRMS photo/file link is signed and tenant-scoped**, never `Storage::url()`: unsigned, a
  person's photo is readable by anyone holding the URL and revoking it means unpublishing a file.
  The central `tenant` id rides inside the signature and the lookup happens inside
  `TenantDatabaseManager::using()`; `HrmsEmployeeApiTest` covers the flushSession + `iso_system` case.
- **HRMS PII masking lives in `SensitiveFieldRedactor`** (P2.4) and the *response keys are the same*
  for both payloads — a non-privileged reader gets masked values plus `restricted: true`, never absent
  keys. Its `email()` masks the **domain** too (for a personal address the domain is the provider);
  `dateOfBirth()` takes no argument and returns null (a year alone identifies someone in a small
  company); `phone()` cuts any `ext`/`x`/`#` **before** extracting digits, or the extension shifts the
  mask. `SENSITIVE_COLUMNS` is the single source for both what gets masked and what
  `hrms_data_access_logs.fields` records.
- **The employee directory masks personal fields even for a privileged reader**, and so is
  `photo_url` — a list of 50 rows with home addresses is a bulk-harvest surface no single-record
  screen is. `EmployeeAccessLogger::recordView()` writes the access row only when the personal fields
  were actually visible *and* were populated; a false row in a sensitive-access ledger is worse than
  none. `EmployeePhotoService` holds the photo's three rules (signed, tenant-scoped, download-logged)
  and its URL carries the reader's id **inside the signature** so the download is attributable.
- **HRMS SPA pages live in `resources/js/pages/hrms/`** (`HrmsOverview.jsx` moved there in P2.5) behind
  the `module="hrms.core"` route gate; the directory is `Employees.jsx` on `permission:hrms.employees.view`,
  with the create modal's three login modes (none / new / link) — "no account" is first-class so the
  inline-login fields are never the default state of a new-person form. `work_modes` is **served by
  `filterOptions()`** from the PHP enum, never hardcoded in JS: a JS copy of a PHP enum is drift no test
  can catch. The `department` filter is absent on purpose until P3.1 adds the column. P2.6 added
  `EmployeeDetail.jsx` (URL-driven tab via `HRMS_PROFILE_TABS`, module-filtered, **entries only for
  sections that have shipped**), `StatusHistory.jsx` and `EmployeeEditModal.jsx`; the directory's name
  cell links via `employeeUrl(id, tab)`. **Two product shells (TMS/HRMS separation):** the sidebar is
  product-aware — `Sidebar.jsx` picks `tmsSections` vs `hrmsSections` from `utils/nav.js` by
  `productForPath()` (`utils/product.js`: `/hrms*` + `/my` render the HR shell, everything else the
  task shell). The HR shell lists the HRMS hub groups as **collapsible sections** (derived from
  `HRMS_NAV`, never re-listed; the group holding the current page opens on its own, explicit toggles
  persist per label, collapsed sidebar navigates a group icon to its first page) with My HR leading
  Overview, plus shared Administration (Users/Roles/Settings/Security, no Webhooks) and Billing; the
  TMS shell has no `/hrms` entry and no My HR at all — crossing shells is the topbar
  `ProductSwitcher.jsx`'s job (shown only for the other product the caller can reach: subscribed
  product + a TMS landing permission / the `hrms.core` module; never for a non-impersonating super
  admin). There is no secondary rail: `HrmsLayout.jsx` is deleted and `/hrms` routes render pages
  directly inside an invisible `HrmsErrorScope` (App.jsx) that keeps the route-keyed crash isolation.
  Routes keep their literal `/hrms/{section}` paths and gates — preserve both when adding a page and
  add it to `HRMS_NAV`; `tests/Feature/HrmsNavTest.php` asserts the TMS shell offers no `/hrms` entry,
  HRMS sections derive from the hub catalog, My HR lives in the HR shell only, no rail element/file
  remains, route↔tab parity and per-tab gate equality.
- **Never submit a masked value back, and never build a picker from a report list.**
  `EmployeeEditModal` omits the whole personal block when the record arrived `restricted`, because
  prefilling it from masked values and posting them would store `p***@***` as somebody's real address.
  The `managers` option list is **everyone**, not only people who already have a report: a reports-only
  list cannot seed a reporting line at all, since a new hire reporting to a first-time manager is an
  ordinary assignment and the select comes back empty for exactly that case.
- **A `color()` in this app is a hex, never a Tailwind palette name.** The status pills render
  `backgroundColor: \`${color}22\``, so a palette name silently yields an invalid colour and every
  status looks identical. `EmployeeStatus::color()` returned `emerald`/`sky`/… and was fixed in
  P2.6; `config/task_statuses.php` and `config/priorities.php` already store hex. Wire-tested.
  `WorkMode`, `ApprovalStatus` and `ApprovalStepStatus` carried the same palette names with **no
  consumer yet** — which is the only reason nobody had hit it, and the reason the next person to
  render a work-mode or approval pill would have. All three now return hex, and
  `tests/Unit/Hrms/HrmsEnumColorTest.php` reflects over `app/Enums/Hrms/` to pin the convention for
  every current and future enum. It needs no application boot, so it locates the directory relative
  to the test file rather than calling `app_path()`, and it guards `method_exists` before building a
  `ReflectionMethod` (a missing method throws, and `DataAccessAction` has no `color()`).
- **P3.2 splits the plan's single `OrgService.php` into `app/Services/Hrms/Org/`.** The literal path
  is superseded by D2.16.2 (P1.9 precedent): `DepartmentTree` (cycle check, descendants, path),
  `DepartmentChart` (the nested read model with headcounts), `DepartmentService`, `DesignationService`,
  `LocationService`, `OrgNaming`. Three features follow `parent_id` in both directions, so the walk is
  stated once. `Department::descendants()` became `DepartmentTree::descendantsOf()`; the plan's
  `buildTree()` is `DepartmentChart::build()` — split out separately because guards + nesting in one
  file breached the plan's own 300-line ceiling. `tests/Feature/HrmsOrgServiceTest.php` is the guard
  (37 tests).
- **A subtree headcount starts at `direct_count`, never at 0.** `nest()` accumulates upward, so a
  zero seed reports every parent-only department as 0 staff. `headcount` (subtree) and `direct_count`
  (this node) are separate keys because they are routinely confused.
- **A cycle reader must prune, not re-emit.** `assertCanReparent()` stops new cycles, but imported org
  data can arrive tangled, so `nest()` returns null for a node already on the branch and `build()`
  surfaces unreachable rows (dangling parent, cycle) at the top level instead of dropping them.
  A department that renders nowhere is silent data loss.
- **`is_geo_fenced` is derived from the geometry, never stored as sent** — `applyGeofence()` writes
  `$wantsFence && $location->hasFenceGeometry()`. Setting the flag by asking `isGeoFenced()` is
  **circular** (it reads that very column) and silently stored `false`; the split into
  `hasFenceGeometry()` (stored lat/lng/radius) vs `isGeoFenced()` (flag *and* geometry) is what makes
  the write checkable.
- **A reorder is intersected with the real siblings first** (`OrgNaming::order()`): a reorder names the
  members of one list, and honouring a foreign id lets a payload drag a row out of a *different*
  parent. Omitted siblings keep their relative order at the end.
- **`hrms.org.manage` does NOT imply `hrms.org.view`.** `OrgRecordPolicy` checks the two independently
  and the three subclasses (`DepartmentPolicy`/`DesignationPolicy`/`LocationPolicy`) exist purely so
  Laravel's convention-based discovery binds a *named* policy to each model. A manager-only role gets a
  403 on every show, which is deliberate: `manage` is granted to somebody asked to fix a tenant's org
  chart, and letting it imply reads would hand that person every employee's team. `HrmsOrgApiTest` pins
  both directions.
- **Declare each `reorder` route before its own `{resource}` sibling.** `hrms/departments/reorder`
  declared after `hrms/departments/{department}` binds the literal string "reorder" as a department id
  and 404s a perfectly valid drag-and-drop.
- **A field-shaped rule is keyed to its field, not to `form`.** `head_employee_id` → the head picker
  shows the error; a structural rule (a cycle, a record in use) has no one field to blame and uses
  `form`. Getting this backwards makes a client attach a banner instead of the field's own message.
- **P3.1's org FKs were unreachable until P3.3** — the columns existed and P3.2 read them, but no
  employee *write* path accepted them, so only a seeder or raw SQL could fill them. They now live in
  `EmployeeProfile::WRITABLE`, `Employee::$fillable` + casts, `EmployeeRequestBase::orgRules()` (split
  out of `profileRules()` to stay under the 40-line method ceiling) and the audit snapshot, with
  `department()`/`designation()`/`location()` as the inverse of the Org models' `employees()`.
  `EmployeePresenter` emits the three as **scalars, not nested objects** — the org page already has
  every department from the single `GET api/hrms/org` response, so a per-row join buys nothing.
- **A directory filter on a department is an EXACT match, never a subtree** (`EmployeeDirectoryQuery::
  applyOrgFilters()`): "everyone in Engineering" and "everyone under Engineering" are different
  questions, and answering the second when asked the first understates the headcount badge the org
  page prints. The subtree walk belongs to the org context, the only place holding the tree.
- **`EmployeeDirectorySort` was extracted, not merged, when the class hit 303 lines.** The three org
  filters pushed `EmployeeDirectoryQuery` past the 300-line ceiling; trimming comments treats the
  symptom. The sort whitelist is a self-contained decision (the column list *and* the `ORDER BY` that
  consumes it), so it became its own class. `EmployeeFilterOptions` was then extracted for the same
  reason (the option *catalogues* are data, not a query) — that class is where `departments` /
  `designations` / `locations` live now, and `EmployeeDirectoryQuery` delegates. **The ceiling has been
  reached twice: do not add a fourth concern to either class.**
- **A filter needs its option lists from the same endpoint that offers the filter.** P3.3 taught the
  directory to *filter* by department and returned no departments to filter *by*; a select with no
  options silently sends no filter and the unfiltered list reads as a broken search — exactly the
  failure `Employees.jsx`'s docblock had documented when it omitted the filter. The org catalogues
  deliberately do **not** come from `GET api/hrms/org`: a reader with `hrms.employees.view` but no
  `hrms.org.view` may filter the directory and may not read the chart. Retired rows are included —
  a closed department is the one a reader filters by to see who is still in it.
- **The org page's "members" are employee records, not login accounts.** The plan's `users.view`
  picker is superseded: the panel is gated on `hrms.employees.view` and assigns on
  `hrms.employees.manage` **alone** (the write lands on the employee's record, which is what the API
  checks). Adding `hrms.org.manage` on top would give the UI a rule the server does not have.
- **The org starters are in `config/hrms.php`, and a config key that maps to no column sits
  unreported until the first thing that reads it.** P3.5's first run failed *every tenant's*
  provisioning with "table locations has no column named code": the catalogue had carried
  `'code' => 'head_office'` since P1.10, unconsumed, and `locations` is the one org table P3.1
  gave no `code` to. The starters are keyed on `slug`. The org tables have **no `is_system`
  column** either — what stops a referenced row being deleted is P3.2's service rule, and adding
  the flag would be a second, weaker answer to the same question.
- **The `document_types` catalogue had the same defect, and
  `tests/Feature/HrmsCatalogTest.php` is the gate that now catches it.** It named `code`,
  `is_confidential` and `validity_months` — no such columns — while omitting `category`, the one
  column the compliance story is built on. The blast radius is identical, because the first
  consumer is a `seed*()` step inside `HrmsDefaultsProvisioner::provision()`: the error surfaces as **every
  tenant in the fleet fails to provision**, not as "the document catalogue is wrong". The guard
  asserts both directions (catalogue key → real column, required column → catalogue key) across the
  catalogues that are seeded today. Two details it depends on, each silent when wrong: the
  `default`-key dual above, and the fact that **three seeders derive `slug` themselves**
  (`employment_types` from `Str::slug($name)`, `departments`/`designations` from `code`), so those
  are exempted *by name with the derivation recorded* rather than exempting the `slug` column
  wholesale — which would let a future `slug` with no derivation behind it pass. `document_types`
  joined the seeded list with the P13.2 service, when `seedDocumentTypes()` first existed. `validity_months` was **dropped, not mapped** to `retention_months` — how long a
  passport stays valid is a fact about one row (`employee_documents.expires_at`), retention is how
  long the tenant keeps the bytes;   mapping one onto the other writes a fabricated policy into every
  tenant.
- **P4.1’s sketch omits `document_requests.document_id`, and the column ships
  anyway.** The phase’s own prerequisite note references “a null
  `document_id`”, and without it there is no link between a request and its
  fulfillment — the hinge story collapses to “HR asked, something happened
  somewhere”. Nullable FK to `employee_documents`, nullOnDelete: a deleted
  file orphans the request (“asked, file gone”) instead of un-asking it.
  Likewise case-level `category`/`owner_scope` are plain-string snapshots of
  the template enums (an enum on the snapshot holds new catalogue values
  hostage to a migration on old rows), and `asset_id`/`expense_claim_id` are
  bare nullable integers until P14/P8 exist to constrain them.
- **P4.2 splits the plan’s two services into a `Lifecycle/` bounded context.**
  `OnboardingService` orchestrates `OnboardingTemplates`, `OnboardingCases`
  and `CaseProgress`; `OffboardingService` orchestrates `OffboardingChecklist`;
  both share `DocumentRequestService` (the request state machine both case
  types raise from). `CaseProgress` owns the mandatory definition *and* the
  photograph, so waiving and closing cannot disagree. Mandatory is derived
  from the template link, never stored: ad-hoc items are never mandatory,
  and `removeTemplateTask` refuses while an open case references the item so
  a deleted row cannot quietly unblock a case. Clearance counters are
  task-driven until P14/P6/P8 wire real records (leave/expense read an honest
  0); `clear()` 422s naming the blockers, `complete()` requires the signed
  clearance. `terminate()` auto-initiates an exit run when none is open
  (last working day = stamped exit date); initiating stamps `exit_date` but
  never touches status, so the two cannot loop.
- **P4.3 splits “may this caller open this row” from “which rows may the list contain”.**
  `Policies/Hrms/Lifecycle/` holds the per-record bases (`OnboardingPolicy`,
  `OffboardingPolicy`, `DocumentRequestPolicy`) plus empty marker subclasses
  for convention discovery — one shared class would be discoverable by none
  of its models (the Org precedent). Set-level scoping lives in the services’
  `casesFor`/`indexFor`, reading the same permissions. Nested task routes
  declare both models and verify belonging (a foreign task id 404s). Free
  document asks attach to onboarding cases only; an offboarding ask is raised
  by the exit run itself. The nested `tasks` rules live on the template
  request — without them `validated()` silently drops the checklist and the
  form lies about succeeding.
- **P4.5 notifies asymmetrically by design.** The owner always hears about
  their own item; manage-holders additionally hear about hr-scoped items (a
  pool nobody nudges is a pile nobody works). Creation nudges only items due
  within 7 days; the daily `hrms:onboarding-reminders` covers the rest, at
  most one nudge per item per week. Dedup uses the portable
  `where('data->case_task_id', …)` selector — pluck with a JSON path is not
  portable, so the test maps rows in PHP. The deep link lands on the case
  detail, not the plan’s list-with-param (the list takes no case parameter);
  the JS branches are pinned by string in the shell test since there is no
  JS runner.
- **P5.1 adds `employees.shift_id` with raw SQL, not `constrained()`.** P3.1 proved
  why: `Schema::table()` + `constrained()` rebuilds the table through a `__temp__`
  copy on SQLite, and doctrine introspection drops the column-level CHECKs — the
  test fast-path ended with a weaker schema than production. The raw
  `ADD COLUMN … REFERENCES`, grammar-quoted (never backticks: PostgreSQL 42601s
  those), touches nothing else; the index is separate, and `down()` drops the
  index before the column. `HrmsAttendanceTablesTest` asserts the CHECKs survive
  on both grammars, and the PG path confirms both `employees_*_check` constraints
  intact. Small sketch gaps closed the same way: `lng` mirrors `lat`,
  timestamps everywhere, `approval_id` a real FK to the P1 engine.
- **P5.2 splits the plan’s `AttendanceService` three ways** (`PunchClock`,
  `DayComputation`, `DayReading` under `Services/Hrms/Attendance/`) — the
  punch window is asymmetric (in bounded both sides, out from below only, so
  overtime never 422s), night punches attribute by owning date with a fixed
  noon cutoff, and out-of-range is a flag for IPs and geofences alike, never
  a refusal. Two Carbon-3 facts re-learned: diffs are signed floats (every
  pairing/lateness diff is `abs()`-bounded and int-cast), and the `date`
  cast serializes with a time part on SQLite — so every date lookup is
  `whereDate`, never exact-string `where` or a `Y-m-d` `whereBetween` (which
  misses rows on the fast path while PostgreSQL coerces fine).
- **P5.3 gates by module *or* ownership, never both at once.** The punch
  endpoint takes no permission: it resolves the employment record from the
  login, so there is nothing to authorize against and no login may punch for
  another. The 403 is a *module* 403 from `hrms.attendance.remote`. Settings
  take `hrms.attendance.settings` and merge per section. The IP is
  server-observed (`$request->ip()`), never client-claimed — a `meta.ip`
  would let anyone assert they are at the office.
- **P5.4 splits “may this caller open this row” from “which rows may the list contain”.**
  `Policies/Hrms/Attendance/` holds the per-record policy (self-service
  included) while `Services/Hrms/Attendance/RegularizationDirectory` owns the
  set-level scope — the P4.3/P13.3 shape, one context over. Corrections
  **supersede, never edit**: approval inserts `regularized`-source punches via
  `AttendanceService::applyRegularization()` and recomputes the day, so the
  row always equals its inputs. The manager step resolves through
  `ReportingLine::managerOf()` (the Attendance context never reads the
  employee graph directly); no resolvable manager → the engine auto-approves
  and the correction applies in the same transaction, audited, never stuck.
  The 300-line ceiling forced the split: `RegularizationService` (lifecycle) +
  `RegularizationDirectory` (reads) + `RegularizationNotifier` (event names,
  payloads, skip-self) + `RegularizationInput` (the boundary DTO) +
  `RegularizationPresenter`. Notifications are backend-only — the JS
  describe/link branches land with the P5.6 pages.
- **P5.5 rolls up through one loop, not two.** `AttendanceService::rollup()`
  ensures a day row per `active` employee (exited records are history nobody
  asked to revisit) with no audit rows — idempotent maintenance is not a
  decision, and a per-employee-per-night ledger would bury the decisions.
  The `HrmsAttendanceRollup` command adds the house `--tenant XOR --all`
  scope, a strict date that refuses the future, and a `--dry-run`; the
  `AttendanceRollupJob` carries the central tenant id and wraps its own
  `using()` (proven from the `iso_system` connection), `tries = 1` because
  re-running is the repair path.
- **P4.4 shares one checklist and gates detail pages by module, not permission.**
  `components/hrms/Checklist.jsx` serves both phases (separate `canAct`/
  `canWaive` — offboarding has no waive endpoint); the waive reason comes
  from `window.prompt`, and the backend 422s an empty one either way. Case
  detail routes carry no permission gate so a hire can open their own run —
  the backend policy decides whose case it is; list pages stay view-gated.
  `LifecyclePresenter::person()` carries `user_id` so the client can answer
  “is this me”. Pending asks carry an inline upload-then-submit flow, and the
  exit detail leads with the clearance panel because a blocked exit must read
  as blocked at a glance.
- **P13.2 keeps the plan's `Services/Hrms/DocumentService.php` path, but not as a monolith.** Upload
  rules, lifecycle, download serving and presentation together exceeded the 300-line class ceiling, so
  the service orchestrates `app/Services/Hrms/Document/` (`DocumentUpload`, `DocumentLifecycle`,
  `DocumentDownload`, `DocumentPresenter`) — the P3.2 `OrgService` split, applied to documents. The
  signed URL carries `actor` as well as `tenant`: a session-free request has no other identity source
  for the confidential `hrms.documents.view_sensitive` check and access row. Audit snapshots identify
  the row by employee/type/status and omit the title, which can itself be sensitive.
- **P13.3 splits “may this caller open this row” from “which rows may the list contain”.**
  `Policies/Hrms/Document/EmployeeDocumentPolicy` owns the per-record answers (self-service included);
  the new `Services/Hrms/Document/DocumentDirectoryQuery` owns the set-level one, and both read the
  same three permissions so they cannot disagree. A confidential row is invisible — not merely
  unreadable — to a reader without the sensitive permission, because a list that names it leaks its
  existence. `expiring` is declared before `{document}` (the P3.3 `reorder` lesson), and list filters
  are validated inline so P13.3 ships exactly the two request classes the plan names. A confidential
  *show* writes an access row like the download does: the title and original name can name the
  condition or the account.
- **The document SPA shares one row component, and P13.4 fixed the dead
  `permission:` gates.** `can('permission:…')` never matched anything because
  `hasAccess` compared it verbatim against bare slugs — every HRMS manage
  button, picker and nav item gated that way never rendered for tenant users.
  One strip in `hasAccess` fixed all call sites (bare slugs still match).
  `Documents.jsx` (store + expiring panel + bulk verify), `MyDocuments.jsx`
  (self-scoped, no directory permission), and the profile `documents` tab all
  render `components/hrms/DocumentList.jsx`; `DocumentUploader.jsx` takes the
  employee id as a prop and only renders a picker when given a list. `mine`
  returns `employee_id` (no “my employee” endpoint exists) and `present()`
  names the owner, so neither page needs a second lookup.
- **The starters invent no facts, and a tenant's starter name is not up for grabs.** No
  `parent_id`/`head_employee_id`/`country`/`timezone`; the numeric `designations.level` **is** set.
  `HrmsOrgApiTest::test_a_seeded_department_name_is_not_up_for_grabs` pins that creating a second
  "Engineering" cannot re-point the starter's slug.
- **Seeding org data changes the fixture every org test runs against (20 broke).** Raw-insert
  fixtures in `HrmsOrgTablesTest` must not reuse a seeded natural key — a second `'manager'`
  designation makes the free-text backfill link to the *seeded* row, so the assertion passes for
  the wrong reason. `$tree[0]` is "whichever row sorts first", not "the one I just made" (some
  tests passed only because their fixtures defaulted to `position => 0`); locate by id. And a
  whole-tenant exact-list assertion is only true while the tenant starts empty.
- **`EmployeeDirectoryQuery::orgOptions()` was renamed** — it now lives in `EmployeeFilterOptions` as
  `orgCatalogues()` (private); `filterOptions()` and `managerOptions()` stay public on the query so
  `EmployeeService` and `EmployeeController` are unchanged.
- **`DepartmentService::update()` is a transaction** because the re-parent is validated *after* the
  scalars are applied — otherwise a rename + cyclic parent commits the rename and then 422s.
- **`hrms:backfill-employees` is a data migration, and it infers no facts.** P2.7: `EmployeeBackfill`
  creates an `active` employee for every login that lacks one (code `EMP-{user_id}`, default user
  first, one transaction per user). It deliberately does **not** go through `EmployeeService::create()`,
  which applies the `employees` plan quota — a tenant on a 25-employee plan could not backfill its own
  30 logins — and does not set `joining_date` (from `users.created_at` it is a guess that moves payslip
  proration), `employment_type_id` ("full-time is common" is a guess payroll reads) or `personal_email`
  (deliberately a different address from the login). Plan limits are **reported** in the summary table,
  never enforced. `EmployeeCodeGenerator::retrying()` takes an optional preferred code for callers whose
  code is derived rather than allocated: `EMP-{user_id}` cannot be re-derived, so a collision with a
  service-created employee degrades to an allocated code instead of aborting the run.
- **`HrmsDefaultsProvisioner::provision()` guards each step individually.** A single "the `hrms_settings` row exists,
  nothing to do" early return meant a tenant provisioned before a catalog was added to that list could
  never receive it, since it short-circuited the whole method — and `tenants:provision` is the repair
  path. Adding a catalog to the config means adding its own guarded step, never wrapping the others in
  one.
- **The HRMS starter-data seeding lives in `app/Services/Hrms/Defaults/HrmsDefaultsProvisioner.php`,
  not in `TenantProvisioner`.** It was moved there after P13.1: `TenantProvisioner` had reached 368
  physical lines against the plan's 300-line class ceiling, and `document_types` (the next catalogue)
  would have pushed it further over. The concern is cohesive — insert-only starter rows a tenant may
  then rename — and the move is folder-per-context (D2.16.2) like `Employee/` and `Org/`. The seam is
  one line in `TenantProvisioner::seed()`: `app(HrmsDefaultsProvisioner::class)->provision()`. That is
  the place the next catalogue goes.
- **A refactor commit that also edits behaviour is a refactor nobody can review.** The extraction moved
  the seven methods byte-for-byte; the only deltas were `provisionHrmsDefaults()` becoming the public
  `provision()` entry point and the new class closing brace. Verified by diffing the moved block against
  the pre-commit file, not by reading it.
- **`TenantLimits::currentCount()` counts `employees` via `Employee::count()`** — omitting the mapping
  made `assertQuota('employees')` a silent no-op, since an unknown resource counts 0.
- **`employees.manager_id` is `nullOnDelete`, deliberately not `cascadeOnDelete()`.** Unlike
  `tasks.parent_id` (which cascades), cascade here would delete an entire department when one manager
  is removed. `employee_status_history` is the opposite case: it cascades with its employee, because a
  history row has no meaning without the record, and it `nullOnDelete`s `actor_user_id` so the trail
  outlives a deleted account.
- `TenantDatabaseManager::migrateTenant()` runs **pending-only**, so a new tenant migration reaches all
  102 live dev tenants on the next `php artisan tenants:provision` — and a migration that must backfill
  existing tenants does the backfill inline, repair-safe (`Schema::hasColumn`, `DROP INDEX IF EXISTS`,
  `whereNull` guards).
- Every HRMS file download (documents, payslips, letters, receipts) uses the **signed tenant-scoped**
  pattern documented in the Collaboration section: the central tenant id rides inside the signature, the
  route sits outside `switch_tenant`, and the controller resolves `Tenant::find($request->query('tenant'))`
  + streams inside `TenantDatabaseManager::using()`. Write the `flushSession()` +
  `DB::setDefaultConnection('iso_system')` regression test with it.
- Payroll statutory (PF/ESI/PT/TDS/LWF) is a **separate high-risk phase**, jurisdiction-configured per
  tenant region with no hard-coded thresholds, unit-tested against a fixture corpus, and snapped onto
  each payslip so a config change can never rewrite a locked one.
- Performance integrates with tasks via `ScopesVisibleTasks::visibleTaskQuery()` as **evidence only** —
  automated signals are shown to the reviewer and never converted into a rating or score.

## HRMS reference (Phase 15 → 21 shipped)
- **Module keys** (`config/subscriptions.php` → `module_meta`, mirrored 1:1 in
  `resources/js/utils/hrmsModules.js` — `HrmsShellTest` fails on drift): `hrms.core` (gate for the whole
  surface) + `hrms.onboarding|offboarding|attendance|attendance.remote|shifts|leave|leave.exemption|
  comp_off|holidays|expenses|compensation|payroll|payroll.statutory|exemptions|performance|talent|
  engagement|documents|assets|analytics|inbox`. `hrms.shifts` now ships (P5.1); `hrms.talent` is reserved
  (permissions exist, no pages); every other key has a sidebar entry, a route gate, and an overview tile wired through
  `HRMS_MODULE_ROUTES` (tile without a route fails the shell test).
- **Approval override (R11):** `ApprovalService::canAct` (the one check every approval policy and the engine use) also admits a holder of `hrms.approvals.override` (`ApprovalService::OVERRIDE_PERMISSION`) on a pending step they were not assigned — never on a request they made themselves, and only with a reason of ≥5 chars (`note`, else 422). It is audited as `approval.override_approved` / `approval.override_rejected`, not `approval.approved`. `admin` (`*`) holds it; `hr_manager` explicitly subtracts it (`!hrms.approvals.override`) so its `hrms.*` prefix does not hand it out. There is still no *silent* admin bypass.
- **Shared primitives** (built once in P1, reused by all 22 phases): generic `approvals`/`approval_steps`
  engine (`ApprovalService`), append-only `hrms_audit_logs` + `hrms_data_access_logs` written only via
  `HrmsAuditLogger` (field *names* travel, values are masked at the writer; reads via `accessed()` with
  `DataAccessAction` view/download/export — salary/bank/statutory/document reads and every export write
  one), and the single-row `hrms_settings` (`HrmsSetting::current()`, `setting('a.b.c')` dotted reader).
- **`config/hrms.php`** is the per-tenant defaults catalog (employment types, week start, attendance
  windows, leave/comp-off rules, statutory off-switch, `mask_sensitive`, `data_retention_months` 24).
  Seeded by `HrmsDefaultsProvisioner::provision()` — one guarded step per catalog, never a single
  early-return (a pre-catalog tenant must still receive later catalogs on `tenants:provision`).
- **HTTP shape:** routes in `routes/web.php` inside the tenant stack
  (`switch_tenant → auth → tenant → tenant_context → onboarding_complete`, P19.5 repair) +
  `ensure_module:hrms.core` (+ domain module) + `permission:hrms.view`, with per-record policies deciding
  rows (self-service included: view covers self; payslips/documents/1:1s read their own). Controllers do
  validate → authorize → one service call → present; writes use `Http/Requests/Hrms/` FormRequests;
  shapes come from `Services/Hrms/<Context>/` presenters (same folder as the service); state is always a
  backed enum in `Enums/Hrms/` (hex `color()` when rendered). Set-scoping (`casesFor`/`indexFor`) lives in
  services, per-record answers in policies — the two read the same permissions so they cannot disagree.
- **Signed downloads** (photos, documents, payslips, asset files, receipts): never `Storage::url()` — the
  central tenant id (and the reader id for confidential rows) rides inside a `signed` route OUTSIDE
  `switch_tenant`, resolved inside `TenantDatabaseManager::using()`; confidential rows are invisible
  (not unreadable) without `hrms.documents.view_sensitive`. Every one ships the `flushSession()` +
  `iso_system` regression test. CSV exports (`hrms/attendance/export`, `hrms/analytics/export`) are the
  opposite shape: session routes, per-domain permission, `throttle:30,1`, one `accessed(..., Export)` row.
- **Task integration (P20):** `hrms_task_links` (employee/task/kind grain, unique; kind `goal` feeds
  performance evidence with auditable `task_ids`) + `tasks.hrms_employee_id` (HR-owned asks surfacing on
  HR home); checklist items convert to project tasks with pull-only sync (never writes back);
  work-log-derived attendance is opt-in (`attendance.auto_derive_from_work_logs`, default false).
- **Test gate:** `Tests\IsolatesDatabase` (per-tenant sqlite files; default connection left on acme) —
  current count on the `## Commands` line, updated on every count-moving change. Focused feature tests
  per task; the full suite (Hrms/non-Hrms chunks + `Isolated/`, zero omitted files) is the phase end-gate.
  No `docs/hrms-architecture.md`: the plan (Parts 1–3) plus this section are the architecture record —
  a third copy would rot.

## HRMS P5 completion slices (track/hrms-p5)
- **Shifts catalogue (P5.1):** `attendance_shifts` (+ `shift_patterns` starter catalogue in `config/
  hrms.php`, `ShiftPatternSeeder`), CRUD behind `ensure_module:hrms.shifts` + `permission:hrms.view`
  (`ShiftController`; schema from `AttendanceShiftPolicy`); `POST hrms/shifts/{shift}/assign` sets a
  shift as a user's default (or the tenant default via `POST hrms/shifts/default/clear`); SPA
  `ShiftCatalog.jsx` + `/hrms/shifts` page + `hrms.shifts` nav item + overview tile (module no longer
  "reserved").
- **Rosters & rotations (P5.2):** `attendance_rotations` (weekly/rotational templates: cycle `[{day:{start,end,break}]`, `night` flag, weekly offs) + `roster_assignments` on `attendance_rosters` (weekly + per-employee). `RosterService`/`RotationService` (+ `apply`, materialising a week of shifts); endpoints nested under `hrms/shifts/…` (`rosters`/`rosters/mine`/`rotations`); SPA `RosterPanel.jsx`/`RotationPanel.jsx` on the Shifts page.
- **Bulk employee CSV import + bulk status (P5.5):** `EmployeeImporter` (validate→preview→commit,
  quota-aware via `TenantLimits`, audited) + `EmployeeBulkStatus` (bulk status change). Routes
  `hrms/employees/import/{sample,preview}` + `POST hrms/employees/import` (throttled) +
  `POST hrms/employees/bulk-status`; SPA `EmployeeBulkModal.jsx` on the directory (`/hrms/employees`)
  with a status bulk-action.
- **Leave completion (P5.9):** `LeaveYearRollover` (carry-forward/lapse — config-driven cap/expiry per
  `config/hrms.php`, `hrms:leave-rollover --all` daily 04:50, `POST hrms/leave/rollover` manual run),
  `LeaveManualAdjustment` (post a ledger row + balance correction, audited; `hrms/leave/ledger`, `POST
  hrms/leave/adjustments`), `LeaveBlackoutService` (`hrms/leave/blackouts` CRUD — a blackout blocks
  asks on those days). All behind `ensure_module:hrms.leave`; writes need `hrms.leave.manage`. SPA:
  `LeaveAdjustPanel.jsx`/`LeaveBlackoutPanel.jsx` on the Leave page.
- **Document versioning (P5.12):** `employee_documents.version_number` + SHA-256 per version in
  `document_versions`; `DocumentVersioning` (`store` bumps the number, keeps prior bytes),
  `POST|GET hrms/documents/{document}/versions` (throttle 30/min); SPA `DocumentVersionsModal.jsx` in
  `DocumentList.jsx`.
- **Geofence block-vs-flag + break punches (P5.15):** `attendance.settings.out_of_range_action`
  (`flag` default = record for review, `block` = refuse punch) — `PunchRangeCheck` replaces the
  old always-flag logic; `attendance_punches.kind = break_start|break_end` allow unpaid-break punching
  (pairing rules for `kind` in `PunchPairing`). SPA: `ClockInWidget` switches on the action + coordinates.
- **Holiday CSV/ICS import + asset replacement (P5.16):** `HolidayImporter` (CSV or ICS feed →
  calendar rows, preview→commit, `hrms/holidays/calendars/{calendar}/import` throttled, `HolidayImportModal.jsx`); `AssetReplacement` (`POST hrms/assets/{asset}/replace` records a replacement state on the asset row, exits-gate-aware, `AssetReplaceModal.jsx`); `hrms:assets-overdue` nudges unchanged.
- Shared: `CsvTable` helper (`app/Support/Hrms/CsvTable.php`) for parsing tenant-uploaded CSVs;
  `resources/js/App.jsx` route additions for `/hrms/shifts` + the two new overview tiles.

## F-phase additions (branch `feat/f0-baseline`; plan: `.agents/roadmap/missing-functionality-phase-plan.md`)
- **Test infra:** `tests/IsolatesDatabase.php` provisions the acme+globex template **once per test process** and copies the sqlite files per test (a 24-test focused run went from ~12 min to ~1.5 min). Never run tests from a git worktree that symlinks `vendor/` (Composer resolves `App\` to the main checkout); copy the repo with `rsync` instead. Full-suite baseline notes: `.agents/roadmap/f0-baseline-report.md`.
- **Audit trace:** `App\Support\AuditTrace::columnsFor($ledger)` stamps `request_id` and (impersonated sessions) `impersonator_id` on `activities`, `hrms_audit_logs`, `hrms_data_access_logs`; queued jobs carry `request_id` in the payload and `SwitchesTenantConnectionForQueuedJobs` restores it (`RequestId::useForJob`). `POST /api/csp-report` collects CSP violations (`csp.violation` log lines); `audit:verify` runs daily and alerts on a broken chain; `hrms:leave-consistency [--repair]`.
- **Notification delivery controls:** `NotificationDispatcher` honours the tenant `notification_policies` (`GET|PUT notification-policies`, `tenant.manage`), the user's `email_mode` (`instant|digest`; digest items in `notification_digest_items`, sent by `notifications:send-digests` daily 07:30), a 60 s dedupe window and an hourly email throttle (overflow goes to the digest).
- **Custom fields (module `custom_fields`, perm `custom_fields.manage`):** `CustomFieldService` (definitions per entity `employee|task|project|asset|expense|document`, typed values with text/number/date shadow columns, `applyFilters`, `enabled()`), routes in `routes/web/platform_engines.php`; controllers opt in with `HandlesCustomFields` (validate before write, store after, `withCustomFields` on read; no-ops when the plan lacks the module). Wired: employees, tasks, projects (assets/expenses/documents are the next entities).
- **Task search:** saved filters (`saved_filters`, `projects/{p}/saved-filters`) and the JQL-style language (`jql`, `?jql=` on the task list; `app/Services/Jql/{JqlLexer,JqlParser,JqlCompiler,JqlSearch}` — fields are a whitelist, values are bound, ANDed onto the caller's row-scoped query so it can narrow but never widen). Views: board, list, **calendar** (`CalendarView`, drag to reschedule), **timeline** (`TimelineView`); project tab **Workload** (`WorkloadService`, `projects/{p}/workload`, module `sprints`); sprint **burnup** chart.
- **Time:** one running timer per person (a work log without `ended_at`; `time/timer`, `time/timer/stop`, `projects/{p}/tasks/{t}/timer/start`), `work_logs.billable`, weekly `timesheets` (`TimesheetService`: submit freezes that week's logs, deciders = tenant admins or a lead of a project with time on the sheet, never the owner; module `timesheets`). `TimerPill` in the Topbar; `/timesheets` page.
- **Payroll reopen:** `PayrollLifecycle::reopen` (approved/processing/paid → review, payslips back to draft, reason ≥10 chars audited; locked never reopens).
- **API:** `docs/openapi-v1.json` is the contract for `/api/v1` (`OpenApiContractTest` fails on drift); `RouteAuthAuditTest` fails when an `api/*` route is reachable without auth unless listed in its reviewed `PUBLIC` array.
- **Later F-slices (all written without running tests — verify at the end gate):** shift swaps (`ShiftSwapService`, `shift_swaps`), task SLAs (`SlaService`, `tasks:sla-sweep` hourly, `sla_policies`/`sla_breaches`), board types + issue scheme (`config/board_types.php`, `project_issue_types`, `ProjectIssueScheme`), approval-gated moves (`approval` entry rule → `TaskMoveRequest`, `MoveApprovals`), unified audit timeline (`GET audit/timeline`, `tenant.manage`), expense category limits (`max_per_line`, `monthly_limit`), bank payout file (`hrms/payroll/runs/{run}/bank-file`), loans (`LoanService`, deductions are `loan_installment` payslip adjustments), improvement plans, letters (`LetterService`, `config/letters.php`), record comments (`record_comments`, types `expense|leave`), role templates (`config/role_templates.php`), upload scanning (`App\Support\UploadScanner`, `security.upload_scan.*`), metrics (`GET api/metrics`, `METRICS_TOKEN`), PWA (`public/sw.js`, `manifest.webmanifest`), PostgreSQL ILIKE + trigram indexes (`Like::any`). New tenant migrations need `php artisan tenants:provision`. Further slices after the first full run: development plans/OKRs/skill expectations/careers (`hrms.performance` + `hrms.talent`), OIDC SSO (`App\Services\Sso\{OidcClient,SsoSettings}`, public `api/sso/{slug}/{start,callback,info}`, `tenants.settings.sso`, `Auth` enforce check in `AuthController::loginIsolated`), `tenants:purge`, e-signatures (`document_signatures`, public `api/signatures/verify/{code}`), tenant activity metrics, report builder (`saved_reports`, `reports:send-scheduled` hourly), GitHub link (`api/integrations/github/{tenant}`, HMAC), calendar feed (`api/calendar/{tenant}/{token}`), duplicates/clones link types, `BoardColumnsChanged`. **Dropped by the owner: hourly leave (F7.2).**

## Pitfalls / gotchas
- **`EnsureSuperAdmin` is persona-aware — every new `super_admin` route needs an entry in `config/platform_access.php` `routes`** or restricted (persona) accounts get 403 on it; `PlatformAccessTest` fails the day one is missed. No-persona super admins are unaffected (break-glass).
- **A tenant `users` row without a central `tenant_users` row is an account nobody can log into.** In
  isolated mode `AuthController::loginIsolated` resolves the tenant from the routing index first, so
  *every* code path that creates a tenant user must write the routing row too: `UserController::store`
  (Phase 0 fix), `RegisterController` (claims the owner row), `TenantProvisioner::syncRouting` (seeds).
  `UserController::destroy` removes it. Regression test: `tests/Feature/TenantUserCreationTest.php`.
- **Lower-case tenant user emails at creation.** Login does `Str::lower(trim($email))` for the routing
  lookup and then `Auth::attempt`, which matches `users.email` case-sensitively — so a mixed-case stored
  email is only reachable by typing that exact casing. `UserController::store` normalizes before
  validation (so `unique:users,email` also checks the normalized form).
- **`constrained()` inside `Schema::table()` silently DESTROYS other columns' CHECK constraints on
  sqlite.** A `foreign` command is in `SQLiteGrammar::getAlterCommands()`, so Laravel rebuilds the
  table through a `__temp__` copy; the rebuild regenerates every column from doctrine introspection,
  and **doctrine does not report column-level CHECK constraints**. P3.1 hit this adding
  `employees.department_id`: `employees.status` and `employees.work_mode` came out of the ALTER as
  bare `varchar` and lost `check ("status" in (...))`. PostgreSQL takes a native
  `ALTER TABLE ... ADD COLUMN` and never rebuilds, so the loss was invisible there — the test
  fast-path and any local sqlite file ended up with a **weaker schema than production**, with the
  enum guards still passing against PG. Fix: add such a column with raw
  `alter table <t> add column <c> integer null references <ref> (id) on delete set null` (valid on
  both grammars) and create the index separately — index creation is not a rebuild. `down()` must
  drop the index **before** the column: sqlite refuses `DROP COLUMN` on an indexed column. P5's
  `employees.shift_id` has the same trap. When hand-writing the raw statement, take the quoting
  from the connection's grammar (`Schema::getConnection()->getQueryGrammar()->wrap()`), **not**
  backticks: SQLite and MySQL accept `` `col` `` but PostgreSQL rejects it with `42601`, so the
  failure lands on the PG path only — the one path the raw statement exists to protect.
- **PostgreSQL-only SQL slips past the sqlite test fast-path.** The tenant suite runs on sqlite files, so a
  sqlite-tolerated statement (`WHERE is_default = 1` on a boolean column) only fails once a tenant DB is
  actually Postgres. When touching a tenant migration, either stay on the schema builder or exercise the PG
  path (`TENANT_DB_PG_ROLE=... TENANT_DB_DRIVER=pgsql php artisan tenants:provision`).
- **`assertDatabaseHas` resolves the default connection, not the model's.** After tenant-scoped requests the default sits on the tenant DB, so asserting a central table (`audit_logs`, tombstones) 500s with "no such table" — pin `DB::setDefaultConnection(config('tenancy.system.connection'))` first (the Collaboration/AuditChain/SupportAccess precedent), or read through the central model like PlatformAccessTest does.
- **Never put a backslash-quote sequence in raw SQL text.** PDO's placeholder parser treats `\'` as an
  escaped quote, so a second `escape '\'` clause swallows the `?` between them as string content and
  PostgreSQL answers HY093 (SQLite tolerates it — `Like::any` 500d every multi-column search in prod
  while green here). `App\Support\Like` escapes with `!` for exactly this reason; keep backslashes out
  of SQL text (bound values are safe — the parser only scans the statement). Pin new raw SQL with a
  `toSql()` shape assertion, since SQLite cannot reproduce the failure.
- **Laravel `SortedMiddleware` reorders route middleware by the Kernel `$middlewarePriority` list.**
  Any custom middleware that must run BEFORE route-model binding (Swizzlen i.e. `SwitchTenant`,
  `SetTenantContext`, `EnsureTenantContext`, `EnsureSuperAdmin`, `EnsurePermission`) MUST be registered
  in `$middleware->priority([...])` in `bootstrap/app.php` (positioned before `SubstituteBindings`) —
  otherwise binding runs on the connection the previous request left behind (the central DB in tests →
  "no such table: <table>" 500s on iso_system). If you add a new DB-switching middleware to a route
  group, update that priority list.
- **`TenantDatabaseManager::connect()` purges the `tenant` connection whenever the tenant id changes**
  (the connection manager caches it by name across all tenants, so without the purge a `using($tenant)`
  for a second tenant silently re-hits the first tenant's DB) and `restore()` rebuilds the tenant
  connection from the previously-active tenant — `using()` is therefore safe to nest across different
  tenants. Never add a purge-skip for the *tenant* name; the purge-protection lives in
  `switchDefault()` and only guards the central/system connection.
- Public registration exists: `POST api/register` (throttled) + the `/register` SPA page, gated by the
  platform `public_registration` setting (**default off** → 403; `RegisterController` falls back to the
  onboarding config at seed time). Tenant admins can still create users directly via `POST /api/users`
  and roles via `POST /api/roles`.
- React requires `@vitejs/plugin-react@5` (v6 needs Vite 8); `app.blade.php` needs `@viteReactRefresh` with `react()` plugin in `vite.config.js`.
- CSS: global scrollbar hiding + `html/body overflow-x:hidden` in `resources/css/app.css` (keeps theme drawer off-screen).
- DB is SQLite (tenant fast-path): schema changes requiring drops need explicit `dropUnique`/`addUnique` rebuilds.
- **Laravel 12 `Migrator` uses non-recursive `glob($path.'/*_*.php')`** — migration paths must always be
  passed explicitly: system DB `--path=database/migrations/system`, tenant DBs
  `--path=database/migrations/tenant`. Plain `php artisan migrate`/`migrate:fresh --seed` runs **nothing**
  (reset via `migrate:fresh --database=system --path=database/migrations/system --seed`). Tests do this
  automatically inside `Tests\IsolatesDatabase` and `TenantProvisioner::provisionIsolated`.
- **Laravel's `boolean` validation rule rejects `"true"`/`"false"` strings**, which is what query strings and checkbox-style payloads actually deliver (axios serializes `trashed=false` into the URL). Controllers that accept such input use the `NormalizesBooleanInput` concern (`normalizeRequestBooleans()` before `validate()`) so real booleans reach the model — a raw `"false"` would be cast to **true** by Eloquent. Non-boolean values still 422.
- **TDZ trap:** a component that references a `useState` binding in a hook call placed *above* its declaration throws `Cannot access 'X' before initialization` on every render (minified, so the identifier looks random) — map the frame with `vite build --sourcemap` + `@jridgewell/trace-mapping` instead of hunting for import cycles. Component-body statements must not read a binding declared below them.
- **A repair-safe guard in a migration can silently skip the very work it was written to do.**
  `2026_10_13_000036_add_phase2_performance_indexes` first named the payroll ledger
  `payroll_adjustments` (real table: `payslip_adjustments`) and its own `Schema::hasTable()`
  guard turned that typo into a green **and empty** migration — the same defect class as the
  `document_types` catalogue finding. Keep the `hasTable`/`hasColumn` guards (repairs re-run
  migrations), but assert the *outcome* in a test: `DBPerformanceTest` checks the index columns
  exist, never a migration's exit status.
- **`TenantLimits::effective()`'s static memo lives for exactly ONE unit of work** — a single
  HTTP request, or one queued job in a worker. `AppServiceProvider` flushes it on
  `RequestHandled` + `JobProcessing`, `IsolatesDatabase` flushes per test. Its key is
  (tenant, plan_id, limits_override) and deliberately does **not** hash the plan's `limits`
  column, so without those boundary flushes a `$plan->update(['limits' => …])` keeps serving the
  old merge until the process recycles (`ModuleGateTest` is the guard: grant a module → the next
  request must allow it). Never carry it across requests or jobs.
- Broadcast events fire through the queue: `QUEUE_CONNECTION` must be `sync` in tests, and `Event::fake()` (NOT `Broadcast::fake()`) intercepts queued broadcasts. Channel auth should be tested by invoking `Broadcast::driver()->getChannels()` callbacks directly (NullBroadcaster returns 200/empty in tests).
- Inline test routes that pass through `tenant`/`tenant_context` need the `web` middleware group (or you get "Session store not set on request").
- Test isolation is handled by the **`Tests\IsolatesDatabase` trait** (hooks via `setUpTraits()`) — it
  creates a file-backed `iso_system` sqlite + per-tenant sqlite files under
  `tenancy.tenant.db_path`, migrates/seeds them, and leaves the default connection on `acme`.
  Wild `Config::set('tenancy.driver', 'isolated')` is NOT enough (the `TenantDatabaseManager` singleton
  is cached); never `connectSystem()` while the default connection is `:memory:` sqlite — purging that
  connection wipes the test DB.
- Agentic workflow (Claude/Gemini/OpenCode via MCP): see `.agents/orchestrator/README.md`.
