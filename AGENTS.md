# FlowSync — Multi-Tenant Admin Panel

Laravel 12 + React 19 SPA. Session-based auth **without** Breeze/Fortify/Sanctum. **Phase 13: one database per tenant** (PostgreSQL everywhere incl. this dev host; sqlite files remain the test fast-path) + a central `system` DB. **Phase 14 (subscriptions) shipped code:** plans/subscriptions/events (central), onboarding trials, and plan-limit enforcement — gates pending a PHP-capable env. Vite 7 + Tailwind v4 + axios. See `docs/multi-tenancy-architecture.md`.

**Coding standards (binding for all new code, fixes and refactors): `docs/coding-standards.md`.** Read it before writing code; `CLAUDE.md` imports it automatically. Refactor plan: `.agents/roadmap/refactor-plan.md`.

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
- **Run PHP tests with ParaTest: `php artisan test --parallel` (add `--processes=N`; installed via `brianium/paratest`, if `vendor/brianium` is missing run `composer install`). Never run the suite serially and never pass a long single-process run — it exceeds tool time limits.** `php artisan test` (serial) — run test suite (Phase 13: **isolated, per-tenant file DBs** via `Tests\IsolatesDatabase`; current gate: **2345 tests / 15011 assertions on `chore/code-cleanup` (2026-10-23; the only failure is `HrmsShellTest::test_every_commit_is_pushed`, which passes once pushed), Vitest 242 tests / 51 files** — previous gate **2226 tests / 13410 assertions on `feat/f0-baseline` (2026-10-18): the full ParaTest run had 8 problems — 7 in the newest slices' own tests/code (SSO fixture ordering, a missing `SavedReport::user()`, SSO settings echoing the encrypted secret, the signature verify page not loading the file path, 6 public/self-scoped routes missing from the authorization audit list), fixed and re-run green; the 8th is `HrmsShellTest::test_every_commit_is_pushed`, which passes once the branch is pushed** — previous gate 2143 tests / 12785 assertions (2026-10-17) — the older figures that follow are historical: **1720 tests / 8815 assertions (full run 2026-10-09; the only failure is the unpushed-commit guard)** — verified by focused feature runs on 2026-10-09 and the P4.10 focused suite (`TaskBoardWipSwimlaneTest`, 9 tests; +9 tests / +53 assertions over the 2026-10-09 full run); TMS expansion completion (H-4/H-5/H-6) adds issue types CRUD (`IssueTypeController` store/update/destroy gated by `workspaces.manage`), task watchers notification fan-out (`NotificationService` status change, comment, and unblock fan-out to watchers + `TaskService::present()` `watchers` emission), and SPA surfacing in `CreateTaskModal`, `TaskDetail`, `FiltersBar`, `TaskCard`, and `TaskTable` with issue types management in `ProjectDetail.jsx` workflow tab (`TmsExpansionTest`); the TMS/HRMS product separation adds `Unit/ProductCatalogTest` (permission→product map, 4 tests), `TenantProductRbacTest` (catalog scoping + grant refusal + product labels, 7 tests) and `ProductAccessTest` (whole-product 403s + `X-Product-Denied`, 5 tests); the audit/security slice adds `AuthAuditTest` (`auth.login`/`auth.login_failed`/`auth.logout` rows across the SA, tenant and registration paths), `PlatformAuditTest` (`PlatformAudit::diff()` before/after key reduction + shared `AuditMask` secret masking), `ExitedUserAuthTest` (`loginIsolated` refuses `exited`/`terminated` employees after credential match — `reason=employee_exited` + 422 — while suspended/rehired/no-record users still sign in) and `AuditFeedTest` (SQL-union pagination of the full trail, `tenant_id` JSON/subject filter, tie ordering, CSV export + `audit.exported`, `(created_at,id)` index); the scheduler phase (§F) adds `ScheduleRegistrationTest` (every `routes/console.php` entry incl. the 10 hrms sweeps + trial expiry, exclusions pinned) and `TrialExpiryTest` (tenant+subscription `expired` transition, audit row, scheduling), and `TenantSubscriptionTest` gained the `seats_changed` from/to case; Phase 7 (TMS expansion) adds `TmsExpansionTest` (workspace/project metadata expansion, project components CRUD with uniqueness, project versions/releases CRUD with uniqueness, issue types catalog & seeding, task start dates, story points, issue type & version associations, component tagging, task watchers lifecycle, and filtering); Phase 6 (HRMS residual behavior & UX) adds `HrmsErrorBoundary` wrapping HRMS hub tabs in `HrmsLayout.jsx` with automatic route-keyed reset, onboarding redirection (`EnsureOnboardingComplete.php` web 302 redirect & API 403 with `X-Onboarding-Redirect` header + `api.js` interceptor), and work-log attendance settings (`attendance.auto_derive_from_work_logs` in `AttendanceSettingsRequest`, `GET /api/hrms/attendance/settings` in `AttendanceController`, and admin settings modal in `Attendance.jsx`); Phase 5 follow-ups (performance & scale) adds `PerformanceFollowupsTest` (board column limits & single-pass status scans, notification incremental fetch via `?after=id` cursor with slower Echo fallback polling, configurable category search caps in `GlobalSearchController`, and scale seeder activity backfill in `ScaleDataSeeder`); Phase 4 (production readiness & operations) adds `PlatformHealthTest` (public health ping `/api/health` + super admin detailed inspection `/api/platform/health` with central DB and sampled tenant DB latencies), `TenantBackupTest` (`tenants:backup` system & tenant DB snapshots with `manifest.json`, SHA-256 verification, and non-destructive restore drill), and live gateway credentials verification in `BillingTest`; Phase 3 (subscription lifecycle) adds `TenantUsageCollectionTest` (system usage_metrics table & collector command `tenants:collect-usage`), failed-payment webhook past_due subscription suspension, `EnsureModule` structured reasons (`X-Module-Reason`), and user/tenant profile attrs in `/api/auth/me`; Phase 1 residuals adds fail-closed `EnsureModule` (A3), tenant-aware password reset (A2), impersonation guards (A5, A6), and serviceable tenant channel auth (A7) across `BroadcastingChannelAuthTest`, `ModuleGateTest`, `SecurityRegressionTest`, and `SystemAdminTest`; Phase E step 17 adds `MemberAccessMatrixTest` (cross-domain matrix over TMS tasks and HRMS leave/expense/document/performance with {own, report, stranger} x {none, own, assigned, all, manage} + sidebar/capabilities) and extends `MemberIsolationTest` with assigned reporting line strict isolation; Phase D step 14 adds button/denial audits with `test_a_permission_denial_names_the_missing_grant`; Phase C step 9 adds `TaskScopeAccessTest` (project-role `tasks.*_own/_assigned/_all` row enforcement on TaskPolicy + board/list), step 12's `ReportsToTest`, step 10's `HrmsScopeAccessTest` (`App\Services\Hrms\HrmsScope` — own/assigned/all row scoping for the leave/expense/document/comp-off lists and policies, `_assigned` via `ReportsTo`), and step 11's `HrmsScopeEmployeeIdTest` (attendance/leave-balance/comp-off-credit per-record policies and the performance goal/check-in/1:1/feedback lists+show flip to `HrmsScope`, so a client-supplied `employee_id` on the My-page reads intersects the caller's real scope instead of widening it — `_assigned` reads reports, `_all`/legacy/manage read everyone); Phase 7 adds `FactoryParityTest` (~12 central + tenant + HRMS factories maintaining relational consistency) and `BrandSweepTest` (branding verified as FlowSync, config-driven storage prefix, zero legacy boilerplate); Phase 6 adds `BillingTest` (checkout initiation, idempotency, verification, history, refunds, locale routing) and `PaymentWebhookTest` (Stripe & Razorpay signature verification, replay guard, payment failure); Phase 5 adds `SubscriptionStateTest` (explicit subscription states, export.full gate, attachment storage quota) and `TenantExportTest` (queued full export, ZIP generation, signed download, tenant isolation); Phase 4-1 adds `NotificationPreferenceTest` (api/notification-preferences), Phase 4-2 adds `MentionEmailTest` (task notification emails + mention cap), Phase 4-3 adds `MentionAutocompleteTest` (api/projects/{project}/members/autocomplete); the Phase 3 HRMS IA gate was 1389/6886 (verified by a single `php artisan test` run at the Phase 3 end-gate: all 145+ test files, zero omitted; the Phase 2 database & performance gate was 1384/6862, the Phase 1 security gate 1372/6829)). The 2026-10-09 full-run totals predate the 2026-10-10 track merges — P2.7 (`ApiTokenTest`), P4.1 (`IssueHierarchyTest`), P4.10 (`TaskBoardWipSwimlaneTest`), P8.x (`SecurityHeadersTest`, `FeatureFlagTest`, `TwoFactorTest` + `Unit/TotpTest`, `PlatformAccessTest`, `SupportAccessTest`, `AuditChainTest`), and HRMS P5 (`HrmsShiftApiTest`, `HrmsRosterApiTest`, `HrmsEmployeeBulkTest`, `HrmsLeaveCompletionTest`, `HrmsDocumentVersionTest`, `HrmsPunchPolicyTest`, `HrmsHolidayImportTest`, `HrmsAssetReplacementTest`) all pass via focused sandboxed runs; Phase 22 (HRMS completion) adds `ModuleGateTest` compensation/payroll cases (+2 tests) and the `HrmsNavTest` Outlet-import guard (+1 assertion), delists `hrms.exemptions` (`ModuleCatalogTest` counts 22→21), and tiles the 8 shipped tile-less modules (`HRMS_MODULE_ROUTES` 10→18 entries); Phase 23 wires the orphaned `ApprovalChains.jsx` (`/hrms/approvals/chains` + Records NAV entry, `hrms.approvals.manage`-gated like its API policy); Phase 24 (talent) adds `HrmsTalentCalibrationTest` (6 tests), `HrmsTalentApiTest` (7 tests) and `HrmsTalentGuardrailTest` (3 tests) — calibration service + policy, `hrms/talent/*` HTTP, `Talent.jsx` + NAV + tile, anonymity/no-score pins, and the `talent.review_finalized` nudge (+16 tests / +134 assertions over the quoted totals); the Tenant Core/TMS/HRMS schema split adds `TenantModuleSchemaTest` (TMS-only / HRMS-only / suite / upgrade + catalog gating + 403s — 7 tests / 92 assertions, focused run green 2026-10-14); the i18n work adds `PublicSiteLocaleTest` (site catalog source of truth, default `en`, `?locale=es` `Content-Language` w/ English fallback, `#site-i18n` island — 4 tests / 15 assertions) and the earlier phases' `LocaleResolutionTest` (9 tests), and the i18n email slice adds `EmailLocaleTest` (task-mail subject/line + rendered body follow the recipient's language, unknown-actor fallback, dispatcher threads per-recipient locale, request-locale fallback — 5 tests / 13 assertions); the full-suite totals are re-verified at the next end-gate run

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

## TMS domain (workspaces, projects, tasks, collaboration, time, search)

Details moved verbatim to `docs/architecture/tms-domain.md` (covers: Task-management domain, Workspaces, Projects, Tasks, Collaboration, Notifications, Time Tracking, Search & Reporting, Global Search & UI/UX, Scale Seed Data & Deep Links). **Read it before working in this area.**

## Website CMS and public marketing site

Details moved verbatim to `docs/architecture/cms-public-site.md` (covers: Website management / CMS + public site). **Read it before working in this area.**

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

## Billing: Stripe, scheduled changes, reconciliation

Details moved verbatim to `docs/architecture/billing.md` (covers: Stripe billing, Stripe billing v2, Billing consistency: customers, trials, cards, reconciliation). **Read it before working in this area.**

## Request correlation (P2.2)
- `App\Http\Middleware\AssignRequestId` is prepended to the global stack (`bootstrap/app.php`): it reuses an inbound `X-Request-Id` only if it matches `^[A-Za-z0-9._-]{8,64}$`, otherwise mints a UUID, binds it as shared log context (`Log::shareContext(['request_id' => …])`, so every log line of the request carries it) and echoes it on the response. Quote it when reporting a failure. Not yet in audit rows or queued-job payloads (follow-up).

## API tokens & security hardening

Details moved verbatim to `docs/architecture/api-tokens-and-security.md` (covers: Personal API tokens, Bearer auth, idempotency, security headers/CSP, feature flags, 2FA Totp, platform personas, support access grants, and audit chain verification). **Read it before working in this area.**

## Realtime (Reverb + Echo)

Details moved verbatim to `docs/architecture/realtime.md` (covers: Laravel Reverb, private channels, queued tenant connection resolution, Echo reconnect resilience, and channel auth). **Read it before working in this area.**

## RBAC, provisioning & scope matrix

Details moved verbatim to `docs/architecture/rbac-and-permissions.md` (covers: Permission catalog, role definitions, scope variants `own|assigned|all`, policy matrix, project roles, idempotent tenant provisioning, and access explainer). **Read it before working in this area.**

## Task-management services (Phase 0 shells, grown in P1+)
- `app/Services/ActivityLogger.php` — append-only polymorphic audit (`log()` + `forSubject()`).
- `app/Services/NotificationService.php` — `notify()` creates `notifications` row + broadcasts `NotificationSent` on the recipient's private channel; `forUser()` paginated list (with `actor`), `unreadCount()` / `markAllRead()`; scenario helpers `taskAssigned` / `taskCommented` / `taskStatusChanged` / `taskUnblocked` (each skips self-notification) and `mentionUsers()` (parses `@token` → tenant users matched case-insensitively by email, email local part, or name).
- `app/Services/KeyGenerator.php` — atomic per-project task key allocation.
- `app/Services/WorkspaceService.php` — workspace CRUD + members (see Workspaces section).
- `app/Services/ProjectService.php` — project CRUD + members + statuses/workflow (see Projects section).
- `app/Services/TaskService.php` — task CRUD + board/list/move + filters (see Tasks section); board/list/`show` payloads include `open_blockers_count` (`withCount('openBlockers')`), and `show` lazy-loads comments/attachments/subtasks counts. The service stays a thin orchestrator under the 300-line ceiling (`TaskServiceSplitTest`): writes share `Services/Tasks/TaskLifecycle.php` (WIP/blocker guards, status history, goal refresh) and updates run through `Services/Tasks/TaskUpdater.php` — all 12 public methods stay on the service.
- `app/Services/WorkLogService.php` — work-log CRUD + overlap/duration rules + task/project/workspace time summaries (see Time Tracking section).

## Impersonation & protected default tenant user

Details moved verbatim to `docs/architecture/impersonation-and-users.md` (covers: Super admin impersonation controls, audit logs, routing keys, and protected tenant default user lifecycle). **Read it before working in this area.**

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

## Multi-tenant architecture, subscriptions, module gates, onboarding

Details moved verbatim to `docs/architecture/tenancy-and-subscriptions.md` (covers: Multi-tenant SaaS architecture, Subscriptions, Subscription module gates, Tenant onboarding). **Read it before working in this area.**

## HRMS module (Phase 15 → 16)

The detailed build history (P1.1–P16.3, per-context design rules and the gotchas each slice hit) moved to `docs/architecture/hrms-history.md` (verbatim). **Read it before any HRMS work**; `docs/hrms-implementation-plan.md` stays the task-level spec, and the "HRMS reference" section below is the current-state summary.

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

## HRMS P5 slices and F-phase additions

Details moved verbatim to `docs/architecture/hrms-p5-and-f-phase.md` (covers: HRMS P5 completion slices, F-phase additions). **Read it before working in this area.**

## Maintenance mode, app name, list saved filters
- **Maintenance mode** (SA Settings → `maintenance_mode`): `PlatformMaintenance` (web group + priority list, before auth) answers every `api/*` call 503 `{code:'maintenance'}` + `Retry-After` unless the path is open (`api/maintenance` status probe, health, locales, webhooks, contact, csp-report, signature verify, logout), the caller is a platform session (no `login.tenant_id`; includes impersonating super admins), or it is `auth/login` for a super-admin email. SPA shell + public site stay up; `api.js` redirects to `/app/maintenance` (`Maintenance.jsx`, polls the probe). The flag is cached 10 s (`platform.maintenance`), forgotten on save.
- **App name:** the SA setting `app_name` is the single source — `App\Support\AppName::get()` (cached 300 s, `forget()` on save) and `ApplyAppName` middleware / `JobProcessing` set `config('app.name')` and `mail.from.name` (when `MAIL_FROM_NAME` is unset). SPA: `utils/appName.js` (`window.__FLOWSYNC_CONFIG__.appName`), `{{app}}` is an i18next default variable in locale strings, PHP lang strings use `:app` (callers pass `'app' => config('app.name')`). `manifest.webmanifest` is a route. Never hardcode the product name.
- **List saved filters:** `ListSavedFilters` (`components/ui`) on every filterable list; tenant store `list_saved_filters` (tenant core migration, `api/saved-filters`, own + shared, sharing needs `tenant.manage`) and platform store (system DB, `api/system/saved-filters`, persona `tenants.view`). Both controllers share `ManagesListSavedFilters`; `page` is a slug, values are scalars/flat lists (≤4 KB). The project task filters keep their own `saved-filters` module.

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
