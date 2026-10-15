# Phase-wise Implementation Plan (8 phases, strictly sequential)

> Source: user roadmap (2026-10-05) + codebase audits in `.agents/10-security.md`,
> `04-database.md`, `07-hrms.md`, `06-tms.md`, `08-subscriptions.md`.
> Execution rule: **Analyze → Plan → Implement → Test → Fix → Verify → Report →
> Next Phase**. Never skip a phase; next phase starts only after the current one is
> tested, verified, and reported. One reviewed commit per task on
> `new/hrms-development`, pushed immediately. Every request targets **<3 s**;
> async/background jobs where appropriate. No breaking of existing functionality;
> backward compatible where possible.

## Global gates (every phase)

- Focused feature tests for the phase in full + `./vendor/bin/pint --test`
  (+ `npm run build` when JS changed) before each commit.
- Full suite (`php artisan test`, Hrms/non-Hrms chunks, ~14 min) only at phase
  end-gate or on cross-cutting changes (`routes/web.php`, `bootstrap/app.php`,
  `TenantLimits`, shared `config/*`, middleware).
- Update `AGENTS.md` `## Commands` counts when they move + `.agents/memory/state.md`
  log on every task + relevant `.agents/*.md` file(s) in the same commit.
- End-of-phase report: implemented changes, DB migrations, env vars, deployment
  notes, perf results, remaining risks.

---

## Phase 1 — Full Audit & Security

**Objective:** close all critical/high findings in `.agents/10-security.md`.
**Findings (verified):** HRMS group missing `switch_tenant/auth/tenant`
(`routes/web.php:363`); under-authorized signed downloads (attachments,
non-confidential documents, employee photos — zero check); photos on `public`
disk bypassing signatures; SVG in upload allow-lists; reset broker on wrong DB;
`min:8` passwords, no max, thin throttles; `SESSION_ENCRYPT=false`, no CORS
config; `db_*` exposure; enumeration oracles.
**Affected:** `routes/web.php`, `bootstrap/app.php` (priority list if new
middleware), `AttachmentController`, `Document/DocumentDownload`,
`AssetController`, `EmployeePhotoService`, `*StoreRequest` upload rules,
`Forgot/ResetPasswordController`, `RegisterRequest`/`UserController`,
`config/session|cors|auth`, `Tenant` serializers, `EnsurePermission`/
`AppServiceProvider` SA-bypass guard, `channels.php`.
**DB:** none expected (possible index for uniform reset lookup).
**API/UI:** unified auth error messages; photo/download 403-matrix change
(document shared-link breakage); 429s surfaced in UI; photo disk move
(public→private) needs file migration.
**Risks:** HRMS middleware fix can 403 everything if ordered wrong — keep
`switch_tenant→auth→tenant→tenant_context` + priority list; verify matrix
(anon/SA/tenant/impersonating × TMS/HRMS/download routes).
**Tests:** new `SecurityRegressionTest` (unauth HRMS 401, signed-URL
tamper/anon 403, SVG 422, reset uniformity, throttle 429, enumeration
uniformity) + existing `BroadcastingChannelAuth`, `TenantUserCreation`, shell gates.
**Exit gate:** all criticals closed, focused suite green, no new 500s on the
auth matrix. **Report:** per-finding fix + file:line refs.

## Phase 2 — Database & Performance

**Objective:** entire-DB audit → indexes, N+1, caching; <3 s p95.
**Findings:** schema healthy (FKs/cascades, `000011/000012` passes); gaps:
future-FK bare columns (`expense_claim_id`, `reference_id`×2, `approvable_*`),
`notifications(user_id,read_at)` composite (verify), `LIKE %q%` without `pg_trgm`,
payroll per-employee N+1, `SystemAnalyticsController` cold fan-out (100×4 counts),
uncached `me()`/`TenantLimits::effective`/dashboard/reports, DB cache+queue+
sessions on one PG, board-move write amplification. Do NOT "dedupe" intentional
duplications (`users` in both DBs, tenant-DB central clutter).
**Affected:** `database/migrations/tenant` (new index-only migration),
`TenantLimits` (request-memoize `effective()`), `PayrollService` (chunk + eager
structures map), `SystemAnalyticsController` (rollup or longer TTL + async
refresh), `SearchController` (trigram behind flag), `config/cache|queue` (Redis
option, env-driven, database fallback).
**DB:** additive indexes only (`CONCURRENTLY` on PG); no procedures except a
trigram helper if justified.
**API/UI:** none visible; publish measured p95 table.
**Risks:** index builds on 250k-task tenants (concurrent, off-peak); Redis
optional-flag must default to current behavior.
**Tests:** `DBPerformanceTest` (index assertions, payroll query-count guard,
analytics cold-cache bound), migration repair-safety, PG-path exercise for raw SQL.
**Exit gate:** p95 <3 s on dashboard/board/search at scale-seed volume; report
with before/after numbers.

## Phase 3 — HRMS

**Objective:** audit + improve all HRMS; new IA: ONE sidebar tab, features as sub-tabs.
**Findings:** all domains shipped; gaps are structural (~20 sidebar items violate
the single-tab rule) + plan-doc statuses stale past P2 + per-feature
validation/API/UI parity to verify (regularization, exemptions, TDS surrender,
1:1s, survey anonymity).
**Affected:** `resources/js/pages/hrms/*`, `Sidebar.jsx`, `App.jsx`,
`docs/hrms-implementation-plan.md` status lines, `tests/Feature/HrmsNavTest.php`.
**DB:** none.
**API/UI:** every existing `/hrms/{section}` route keeps its literal path and its
module/permission gates — legacy deep links are untouched, so **no redirects are
needed**. The sidebar collapses the People section to ONE `/hrms` entry (inbox
badge). A nested `<Route path="/hrms" ...>` hub renders `HrmsLayout`, a grouped,
capability-gated secondary rail of all 30 tabs. Sub-tab scheme is **path-based** —
a deviation from the `?tab=` idea in the original plan, recorded here because no
redirect preserves a query design the routes never implemented.
**Risks:** a hub that re-gates any tab differently from its route (stricter or
looser) breaks the single-tab promise — `HrmsNavTest` parses both files and asserts
exact per-tab equality.
**Tests:** `HrmsNavTest` (4: one sidebar entry; route↔tab parity; per-tab gate
equality; layout + nav pins), `HrmsShellTest` (+1 pinning the module-gated Leave
exemption queue), all existing HRMS feature suites re-run green, one full-suite run
at the close.
**Found by the audit, then fixed:** Leave's exemption tab fetched unconditionally —
a plan with leave but without `hrms.leave.exemption` 403'd the *whole* catalogue;
it is now module-gated and the admin page gained its filing form. Approval decide
buttons stay visible for queue-openers (non-approver 403 → toast, not /403, the
app-wide convention).
**Exit gate:** single-tab IA live with all features reachable + gated; stale plan-doc
statuses corrected. **Status: DONE (2026-10-06), gate green — report at the end.**

## Phase 4 — TMS
> ✅ **COMPLETE** — all tasks shipped in individual commits on `refactor/work-hrms`:
> D1 `TaskNotificationMail` (kind-driven, scalar-snapshot, queued, deep-linked) + email
> wiring in `notify()` gated by prefs; D2 `notification_preferences` table + GET/PUT
> `api/notification-preferences` + Settings toggles (`2026_10_15_000037`); D3
> `GET api/projects/{project}/members/autocomplete` (project-member scope, cap-10,
> name/email-local-part prefix) + CommentThread `@`-typeahead; D4 mention fan-out
> capped at `MAX_MENTIONS_PER_COMMENT`=20 with `truncated_mentions` echo + warning
> toast; D5 already satisfied by `snippet`. Cross-tenant hardening: mention regex
> `@([A-Za-z0-9._-]+(?:@[A-Za-z0-9._-]+)?)` treats a full mailbox `@x@tenant.tld` as
> an exact-email-only token so a foreign mailbox never pings a same-tenant local-part
> collision. Full-suite gate: **1411 tests / 6991 assertions**.

**Objective:** audit + improve TMS; @mentions with notifications; EMAIL to assignee
+ board members for relevant events.
**Findings:** @mention + in-app notification exists (`mentionUsers`,
`NotificationSent`); email entirely absent (zero `Mail::`, log driver).
**Affected:** new `app/Mail/*` (Assigned/Mentioned/StatusChanged/Unblocked),
`NotificationService` (queue mail after in-app row, prefs check), `config/mail`,
`.env` SMTP keys, `TaskDetail` mention UX (autocomplete from project members),
board/comment flows.
**DB:** `notification_preferences` (per-user per-event, tenant DB) + mail-log
columns; queued via central `jobs` with existing tenant-stamping.
**API/UI:** prefs endpoints + UI; autocomplete API; 429-safe mention fan-out.
**Risks:** mail volume (mention-heavy comments fan out linearly) — queue +
rate-cap + unsubscribe; tenant-scoped rendering inside `using()` (no cross-tenant
leak in mail bodies).
**Tests:** `MentionEmailTest` (mention → in-app + queued mail to assignee +
mentioned; self-skip; prefs-off skips mail but keeps row), autocomplete test.
**Exit gate:** assignee + board members receive email for assign/mention/comment/
status/unblock; prefs honored; no leak across tenants.

## Phase 5 — Subscriptions, Tenants & Complete Data Export

**Objective:** consistent subscription state per tenant; plan-gated capabilities
enforced backend + UI; full one-operation tenant export gated by Export Data.
**Findings:** code-complete subscriptions, no billing; 4 plans
(starter/pro/business/enterprise); no-sub ⇒ unlimited (legacy-safe — backfill
explicitly, don't silently null); exports = 2 HRMS CSVs only.
**Affected:** `SubscriptionService`, `TenantLimits`, `config/subscriptions.php`,
`EnsureModule`, `MySubscriptionController`, `Tenants.jsx`/`Subscription.jsx`,
new `ExportController/ExportService/ExportJob` (+ ZIP builder + manifest).
**DB:** tenant-local `export_runs` (+ central audit row) via repair-safe tenant
migration; backfill migration giving every tenant an explicit subscription state
(trial/active/canceled/expired-unsubscribed).
**API/UI:** `POST api/my-export` (checkbox categories: employees, workspaces,
projects, tasks, + HRMS sets) → queued job → signed ZIP download (short TTL);
full export requires Export Data capability; plan-gate 403s both layers;
subscription-state admin UI.
**Risks:** GB-scale ZIPs on 250k-task tenants — stream + chunk + quota + job (never
request); PII in exports (manage-gate + access-log rows).
**Tests:** `SubscriptionStateTest` (every tenant has state; gates enforced both
layers), `TenantExportTest` (category selection, plan-gate 403, checksum
round-trip, cross-tenant isolation).
**Exit gate:** all tenants have explicit states; export round-trips byte-identical;
decision recorded (ADR-11): Export Data as add-on `export.full` vs 4th plan.

## Phase 6 — Payments (Stripe + Razorpay, locale-based)

**Objective:** provider-independent billing with checkout, verification, webhooks,
failures, refunds, idempotency, transactional state changes, no frontend secrets.
**Findings:** greenfield — zero gateway code; `billing_provider/reference` columns
are metadata passthrough.
**Affected:** new `app/Billing/` (`PaymentGateway` interface,
`StripeGateway`, `RazorpayGateway`, `PaymentResolver` locale→provider map in
`config/payments.php`), checkout/session + webhook controllers, billing history UI.
Test keys provided by owner later (Stripe first, Razorpay similarly).
**DB:** central `payments` (tenant, provider, amount, currency, status,
idempotency-key unique, subscription link) + `payment_events` log; ALL state
changes in DB transactions.
**API/UI:** checkout endpoints (publishable key only to frontend), webhooks outside
auth (signature-verified + replay-guard + throttle), history/retry/refund UI.
**Risks:** double-charge on retry (idempotency), webhook spoofing, currency/locale
edges, PCI (hosted checkout only — never touch card data).
**Tests:** gateway fakes, idempotent double-post, bad-signature 403, refund flow,
rollback-on-failure.
**Exit gate:** test-mode end-to-end charge→webhook→active-subscription→refund with
idempotency proof; secrets audit (no secret outside server env).

## Phase 7 — Factories & Application Rename

**Objective:** factories replacing static/scale seed data at same volume +
relational consistency + realistic subscription mix; rename product EVERYWHERE.
**Findings:** `database/factories/` = `UserFactory` only; scale via imperative
`ScaleDataSeeder`; branding 100% FlowSync (no legacy residue).
**Affected:** ~12 new factories (Tenant, User/Employee, Workspace, Project, Task,
Plans/Subscriptions, + HRMS/TMS related), seeders rewired onto factories (keep
`tenants:seed-scale` CLI flags working; preserve bulk-insert speed for 250k tasks);
then brand sweep: frontend, backend, DB where necessary, config/env, emails,
notifications, API responses, Docker, docs, metadata, branding.
**DB:** factories must keep FK integrity + plan-reflecting features; subscription
states trial/active/canceled/expired distributed realistically.
**API/UI:** new name in all surfaces; redirects/keys (`/app/login`, Echo keys,
`flowsync.*` storage keys) decided per owner call (stable-infra vs full rename).
**Risks:** volume parity performance; missed references — global-sweep gate test;
rename breaking cached config/keys.
**Tests:** `FactoryParityTest` (counts + FK integrity + subscription mix),
`BrandSweepTest` (zero old-name references).
**Exit gate:** factory-seeded fleet statistically matches current scale output;
zero old-name hits repo-wide.

## Phase 8 — Final QA & Production Readiness

**Objective:** full verification + final report.
**Scope:** unit/integration/feature (full `php artisan test`), frontend
tests/build, Pint + static analysis, security re-check (Phase 1 checklist),
DB/migration checks (fresh + `tenants:provision` repair, PG path), perf checks
(Phase 2 p95 re-measurement), API + tenant-isolation matrix, auth/HRMS/TMS/
subscriptions/payments/exports E2E + critical workflows. Fix all findings.
**Deliverable — final report:** implemented changes, DB migrations, environment
variables, deployment requirements, performance results, remaining risks.
**Exit gate:** full suite green (Hrms/non-Hrms + Isolated, zero omitted), build
green, security checklist clean, report committed (`final-report.md` in
`.agents/roadmap/`).

---

## Findings, Bugs & Recommendations (2026-10-06 codebase audit)

> Audited: `routes/web.php`, `Sidebar.jsx`, `.agents/` context pack (00–12),
> `IMPLEMENTATION_TRACKER.md`, `AGENTS.md`, `state.md`, controllers/services
> directory listings, selected grep checks for Mail, Billing, factories, quota
> enforcement, caching, mention autocomplete. Verified Phase 1 fixes landed.
> Categories: **[BUG]** active defect, **[GAP]** missing feature/code,
> **[RISK]** not broken today but will fail under certain conditions,
> **[IMPROVEMENT]** quality/UX/perf enhancement, **[DEBT]** technical debt.

---

### A. Security (Phase 1 — Completed fixes + Residual risks)

#### A1. Phase 1 fixes — confirmed shipped ✅
- HRMS route group now carries the full domain middleware stack
  `switch_tenant → auth → tenant → tenant_context → onboarding_complete`
  (verified `routes/web.php:365`). `SecurityRegressionTest` pins it.
- Signed downloads (attachments, documents, employee photos) now require the
  named `actor` + `Gate::forUser($reader)->authorize('view', $record)`.
- Employee photos moved `public → local` disk; photo endpoint streams through
  `using()`.
- SVG removed from both upload allow-lists; tenant `allowed_mimes` clamped to
  the server list.
- Forgot-password returns uniform 200; `password.reset` redirect route added.
- `max:72` on all password fields; throttles on impersonate/users-store/
  subscription/tenants.
- `db_*` hidden from `Tenant` serializers; restrictive `config/cors.php` shipped.

#### A2. [SHIPPED] Tenant-aware password-reset broker ✅
- `Forgot/ResetPasswordController` routes by tenant using `TenantUserRouting`
  and executes `Password::sendResetLink` and `Password::reset` on the tenant's
  database via `TenantDatabaseManager::using()`.
- Verified by `SecurityRegressionTest::test_tenant_password_reset_routes_to_tenant_database`.

#### A3. [SHIPPED] Fail-closed `EnsureModule` guard ✅
- When tenant is missing or not found in the DB, `EnsureModule` aborts 403
  with 'A valid tenant context is required.' instead of failing open.
- Verified by `ModuleGateTest::test_ensure_module_fails_closed_when_tenant_not_found`.

#### A4. [SHIPPED] SVG removed from TMS attachments doc in `06-tms.md` ✅
- Confirmed updated.

#### A5. [SHIPPED] `ImpersonationStartRequest` validates `Rule::exists(Tenant::class, 'id')` ✅
- Validates that `tenant_id` exists against the system/central database.
- Verified by `SystemAdminTest::test_impersonation_rejects_invalid_tenant_id`.

#### A6. [SHIPPED] `EnsurePermission` and `Gate::define('permission')` SA bypass guarded ✅
- Explicit `&& ! app(TenantContext::class)->impersonating()` guard added so
  super admins impersonating tenant users cannot bypass permission policies.
- Verified by `SecurityRegressionTest::test_impersonating_super_admin_does_not_bypass_permission_checks`.

#### A7. [SHIPPED] Channel auth verifies serviceable tenant state ✅
- `routes/channels.php` verifies that the tenant is active/trial before
  authorizing private user, workspace, and project channels; suspended tenants are 403 forbidden.
- Verified by `BroadcastingChannelAuthTest::test_suspended_tenant_users_cannot_authorize_channels_over_http`.

---

### B. Database & Performance (Phase 2 backlog)

> **Phase 2 status (2026-10-06): DONE, gate green.**
> B1 ✅ already shipped · B2 ✅ new index · B3 ✅ shipped behind `ENABLE_TRGM` ·
> B4 ✅ memoized, flushed per request/job · B5 ✅ stale-while-revalidate + job ·
> B6 ✅ chunked + assignment map · **B7 ⏭ deliberately deferred** · B8 ✅ one bulk
> `CASE` update · B9 ✅ boot-time guard · B10 ✅ 3 indexes (2 were the missing ones,
> `approvals` composite pre-existed) · B11 ✅ pre-existing unique index.
> Evidence, counts and the two bugs the work surfaced: **report at the end of this file**.

#### B1. [GAP] Missing composite index `notifications(user_id, read_at)`
- Notification endpoints are polled every 30 s by every logged-in user.
  The hot-path query filters `WHERE user_id = ? AND read_at IS NULL`.
  No composite index exists; this will degrade linearly with notification volume.
- **Fix target:** Phase 2 — additive migration `CONCURRENTLY` on PG.

#### B2. [GAP] Missing covering index `(status_id, position)` on `tasks`
- Board query orders by `position` within each status column; only separate
  `status_id` and `position` columns are indexed. A covering index dramatically
  reduces sort cost on wide boards.
- **Fix target:** Phase 2.

#### B3. [GAP] No `pg_trgm` / trigram extension for `LIKE %q%` search
- `SearchController` and `GlobalSearchController` use `LIKE '%q%'` patterns
  which perform sequential scans on PG even with B-tree indexes. At 250k tasks
  this is seconds, not milliseconds.
- **Fix target:** Phase 2 — `CREATE EXTENSION IF NOT EXISTS pg_trgm` +
  GIN index on `tasks.title`, `users.email`; gate behind `ENABLE_TRGM` env
  flag so sqlite tests remain unaffected.

#### B4. [GAP] `TenantLimits::effective()` not memoized per request
- Called on every `assertQuota()` inside `UserController::store`,
  `WorkspaceService::create`, `ProjectService::create`, `TaskService::create`.
  Each call re-queries the system DB for the subscription + plan. Under bulk
  operations (scale seeder, API bots) this fans out significantly.
- **Fix target:** Phase 2 — request-lifetime static cache keyed on `tenant_id`.

#### B5. [GAP] `SystemAnalyticsController` cold fan-out is unbounded
- Iterates up to 100 serviceable tenants × 4 DB queries = 400 queries on a
  cold cache. Cache TTL is 300 s. First SA load after expiry can take many
  seconds. No async refresh strategy exists.
- **Fix target:** Phase 2 — background refresh job + stale-while-revalidate;
  cap to top-N tenants with a "total count" aggregate.

#### B6. [GAP] Payroll generation has per-employee N+1 query patterns
- `PayrollService` runs loop per employee; no eager structure map documented
  as fixed. Each payslip calculation potentially re-queries compensation
  components, leave balances, etc.
- **Fix target:** Phase 2 — chunk + pre-load employee data structures map.

#### B7. [GAP] `me()` payload is not cached
- Called on every authenticated page load (React StrictMode doubles it in dev).
  Loads user, roles, permissions, modules, subscription from multiple queries.
- **Fix target:** Phase 2 — short TTL (60 s) cache keyed on `user_id`; bust
  on role/subscription change.

#### B8. [GAP] Board-move write amplification
- `TaskMoveController::move` renumbers ALL tasks in both source AND destination
  columns with individual `UPDATE` statements in a transaction. On a 100-task
  column this is 200 writes per move.
- **Fix target:** Phase 2 — use `CASE WHEN` bulk update or fractional
  position strategy to reduce to 1–2 writes.

#### B9. [GAP] `DB_CACHE_CONNECTION` / `DB_QUEUE_CONNECTION` not pinned
- `.env` comments suggest pinning `SESSION_CONNECTION=system` etc. in prod but
  the defaults currently fall through to the application default connection.
  Under the multi-tenant architecture the default switches per request, meaning
  sessions/cache/jobs could accidentally land on the tenant DB.
- **Fix target:** Phase 2 — make `SESSION_CONNECTION`, `DB_CACHE_CONNECTION`,
  `DB_QUEUE_CONNECTION` explicit (already in `.env.example` guidance; enforce
  with a boot-time assertion if value is null in production).

#### B10. [GAP] Future-FK bare columns lack indexes
- `offboarding_case_tasks.expense_claim_id`, `payroll/leave_adjustments
  .reference_id` (×2), `approvals.approvable_type/id` composite — all bare
  columns without indexes. These are polymorphic and soft-FK by design, but
  they are queried by these values.
- **Fix target:** Phase 2 — verify each with `EXPLAIN` on scale data; add
  indexes where query plans show sequential scans.

#### B11. [GAP] `attendance_days` lacks unique index on `(employee_id, work_date)`
- Rollup idempotency is enforced in application code but no DB-level unique
  constraint; a race condition (two concurrent rollup jobs for the same
  employee/date) can produce duplicate rows.
- **Fix target:** Phase 2 — add unique index; rollup job to upsert.

---

### C. HRMS (Phase 3 — Structural & UX gaps)

> **Phase 3 status (2026-10-06): DONE, gate green.**
> C1 ✅ one sidebar entry + path-based hubs, all legacy paths kept (no redirects) ·
> C2 ✅ plan-doc statuses corrected through P21 + umbrella header ·
> C3 ⏭ TMS scope, retargeted to Phase 4 · C4 ✅ anonymity verified (structural:
> `employee_id` null, fingerprint only, `anonymity_threshold` min 1) ·
> C5 ✅ surrender/projection flow walked with a real tenant; `Statutory.jsx`
> gated by `hrms.payroll.statutory.manage` · C6 ✅ decided: keep reserved as
> documented stubs · **C7 ⏭ deferred** by design (P20 pull-only conversion) ·
> **C8 ⏭ deferred** (no attendance-settings page exists to host the toggle).
> Evidence, counts, deviations: **report at the end of this file**.

#### C1. [BUG / UX] Sidebar has ~25 HRMS items in a flat list — violates the one-tab rule
- Verified in `Sidebar.jsx:5–185`: the "People" section contains 25+ HRMS
  items (Inbox, My team, Employees, Organisation, Documents, My files,
  Onboarding, Offboarding, Attendance, Approvals, Leave, My leave, Comp-off,
  My comp-off, Holidays, Compensation, Payroll, My payslips, Statutory,
  Expenses, My expenses, Performance, My performance, Assets, My assets,
  Engagement, My surveys, Analytics, Audit log, HRMS overview).
- Phase 3 objective is to collapse these into ONE "HR" tab with sub-navigation.
- **Fix target:** Phase 3.

#### C2. [DEBT] `docs/hrms-implementation-plan.md` status markers stale
- Per-phase `Status: done` markers stop at ~P2 although all HRMS contexts are
  implemented. Literal paths in the doc (`Api/Hrms/`, single `OrgService.php`)
  were superseded by repo conventions.
- **Fix target:** Phase 3 — update status lines + correct paths.

#### C3. [GAP] No `@mentions` autocomplete UI in TMS comment box — ✅ DONE (Phase 4)
- `mentionUsers()` backend exists and parses `@token` from saved text, but
  the `TaskDetail` comment input has no mention autocomplete dropdown UI.
  Users must know exact email/username to trigger a mention notification.
- **Fix target:** Phase 4 (TMS phase) — add member autocomplete API endpoint
  + typeahead UI in `CommentThread`. Delivered: `members/autocomplete` endpoint
  + `@`-typeahead in the comment + reply composers.

#### C4. [RISK] Survey anonymity enforcement not independently verified
- `07-hrms.md` notes survey anonymity as a gap to verify. The backend
  `HrmsEngagementTest` exists but whether the response payload strips
  `respondent_id` in anonymous mode before returning to non-admin viewers
  has not been spot-checked in this audit.
- **Fix target:** Phase 3 verification step — add explicit test asserting
  anonymous responses carry no user linkage to non-admin callers.

#### C5. [RISK] TDS surrender / projection not verified end-to-end via UI
- `HrmsTdsProjectionTest` and `HrmsStatutoryTdsApiTest` exist. However,
  the surrender flow (employee submits declarations, system updates projection)
  is complex and the `Statutory.jsx` page is 29k bytes — likelihood of
  UI/API parity gaps is moderate.
- **Fix target:** Phase 3 audit — walk the surrender + projection API flow
  with a real tenant and verify `Statutory.jsx` handles all edge states.

#### C6. [GAP] `hrms.shifts` and `hrms.talent` reserved but unimplemented
- Module keys exist in `config/subscriptions.php` and `hrmsModules.js`.
  Sidebar items gated on them will never show (no pages). If these are planned
  for post-roadmap, they should remain as-is but documented as stubs. If they
  are in-scope they need Phase 3 planning.
- **Fix target:** Phase 3 — decide: stub/remove or plan.

#### C7. [RISK] Checklist → task conversion is pull-only sync (not bidirectional)
- `07-hrms.md:110` confirms that task status changes do not push back to
  lifecycle case progress automatically. Case managers must manually re-check.
- **Fix target:** Phase 3 — add a `TaskSynced` listener that updates
  `case_task_progress.completed_at` when the linked task moves to `is_done`.

#### C8. [GAP] Work-log–derived attendance is opt-in but default-off with no UI
- `attendance.auto_derive_from_work_logs` setting exists but there is no
  UI toggle on the Attendance settings or HRMS settings page.
- **Fix target:** Phase 3 — expose the toggle in `HrmsSetting` admin UI.

---

### D. TMS — Email & Notifications (Phase 4 gaps)
> ✅ All five shipped in Phase 4 (`refactor/work-hrms`, commits 4-1 → 4-4).

#### D1. [GAP] Zero email delivery — complete absence of Mail classes — ✅ DONE
- `app/Mail/TaskNotificationMail.php` (kind-driven assigned/commented/
  status_changed/unblocked; scalar snapshot; queued via `Mail::queue`),
  `resources/views/emails/task-notification.blade.php`, wiring in
  `NotificationService::notify()` gated by `EMAIL_EVENTS` + prefs;
  deep link `/app/projects/{id}?tab=tasks&task=KEY&section=comments`.
  Verified by `MentionEmailTest`.

#### D2. [GAP] No `notification_preferences` table or API — ✅ DONE
- Tenant-DB migration `2026_10_15_000037`; `NotificationPreference` model
  (`wants()` literal-key read — the dot-key trap); config catalog
  `config/notifications.php`; `GET|PUT api/notification-preferences`
  (`DetectsPlatformUsers` short-circuit); Settings Notifications card
  (per-event switches, optimistic save). Preferences gate **email only**.

#### D3. [GAP] No mention autocomplete backend API endpoint — ✅ DONE
- `GET api/projects/{project}/members/autocomplete` (authorize view, q prefix on
  name/email local part, cap 10, `{id,name,email}`), powering the
  `CommentThread` `@`-typeahead (debounced fetch, keyboard nav, caret insert).

#### D4. [RISK] Mention fan-out is unbounded — could spike on large teams — ✅ DONE
- `taskCommented()` caps mentioned users at 20
  (`TaskNotificationMail::MAX_MENTIONS_PER_COMMENT`; assignee/reporter always
  kept, actor excluded) and returns `{notifications, truncated}`; the comment
  response echoes `truncated_mentions` → warning toast in the UI; mail queued.

#### D5. [GAP] `task.commented` notification does not include the comment preview — ✅ DONE
- Already satisfied at audit time: `taskCommented` ships `snippet`
  (`mb_strimwidth(..., 0, 120, '…')`) in the notification data, and the email
  renders it as the `Comment:` line. No code change was needed; documented.

---

### E. Subscriptions & Export (Phase 5 gaps)

#### E1. [GAP] No tenant-level explicit subscription states — all new tenants get "unlimited"
- `TenantLimits::effective()` returns unlimited when no subscription row exists.
  This is the designed legacy-safe default. But newly provisioned tenants via
  `RegisterController` DO get a subscription (trial/active via `ProvisionTenantJob`).
  The issue is *existing* seeded/legacy tenants that were provisioned before
  Phase 14 have no subscription row and run unlimited indefinitely.
- **Fix target:** Phase 5 — backfill migration giving every tenant an
  explicit `canceled` or `active` subscription state.

#### E2. [GAP] No full data export — only 2 HRMS CSVs exist
- `HrmsAnalyticsExportController` provides attendance/analytics exports.
  There is no TMS export (workspaces, projects, tasks, work logs), no
  general tenant export, no ZIP bundle with manifest.
- **Fix target:** Phase 5 — `ExportService + ExportJob + ExportController`
  with category selection; signed ZIP download; plan-gated by `export.full`.

#### E3. [GAP] `export.full` module not defined in `config/subscriptions.php`
- ADR-11 recommends it as an add-on module but it does not yet exist in the
  module catalog. Without the module key, `EnsureModule` cannot gate it.
- **Fix target:** Phase 5 — add `export.full` to `config/subscriptions.php`
  module list + `hrmsModules.js` mirror; gate new export endpoint behind it.

#### E4. [GAP] `export_runs` table does not exist
- Planned in Phase 5 but not yet migrated. Required for queued job tracking,
  signed download generation, and cross-tenant isolation of download links.
- **Fix target:** Phase 5 — tenant-local migration (repair-safe).

#### E5. [GAP] Subscription self-service UI (`Subscription.jsx`) is read/write but lacks
  billing history and invoice download
- The page shows plan cards and usage meters; switch/cancel/renew work.
  But there is no payment history table (since payment infra is Phase 6).
  The UI should show a "Billing history (coming soon)" placeholder to set
  expectations rather than a blank section.
- **Fix target:** Phase 5 (placeholder UI) → Phase 6 (real data).

#### E6. [RISK] `storage_bytes` limit is tracked in `config/subscriptions.php`
  but `AttachmentController::store` does NOT call `assertQuota`
- All other quotas (users, workspaces, projects, tasks, employees) are enforced
  via `TenantLimits::assertQuota()`. Attachment storage bytes are NOT checked
  before upload — a tenant can fill the disk regardless of their plan limit.
- **Fix target:** Phase 2 (security/performance audit) or Phase 5 — tally
  `SUM(size_bytes)` on the attachments table before accepting new uploads;
  reject with 422 when over plan limit. This is also referenced in Phase 15
  of `IMPLEMENTATION_TRACKER.md` as pending.

---

### F. Payments (Phase 6 — Greenfield)

#### F1. [GAP] Zero payment gateway code
- No `app/Billing/` directory, no `PaymentGateway` interface, no Stripe or
  Razorpay integration. The `billing_provider` and `billing_reference` columns
  on `subscriptions` are metadata passthroughs only.
- **Fix target:** Phase 6 — entire greenfield build.

#### F2. [GAP] No `payments` or `payment_events` tables
- Central DB migrations for payment records do not exist.
- **Fix target:** Phase 6.

#### F3. [RISK] Webhook endpoints must be outside `auth` group
- Phase 6 webhook controllers must be registered OUTSIDE the
  `switch_tenant → auth → tenant` middleware chain (no session on a webhook
  request). The signed-download pattern (central `tenant_id` inside the
  signature) can serve as reference.

#### F4. [RISK] PCI — must never store or log raw card data
- Enforce by design: use hosted checkout (Stripe Checkout / Razorpay Hosted)
  only; never accept raw card fields on server endpoints; audit all webhook
  payloads for accidental card data logging.

---

### G. Factories & Rename (Phase 7)

#### G1. [GAP] Only `UserFactory` exists — all test/seed data is imperative
- `database/factories/` contains only the stock `UserFactory.php`. The
  `ScaleDataSeeder` uses raw `DB::table` bulk inserts.
- **Fix target:** Phase 7 — ~12 factories needed (Tenant, TenantUserRouting,
  Workspace, Project, Task, SubscriptionPlan, Subscription, Employee, Leave,
  Payroll, Expense, Performance cycle at minimum).

#### G2. [RISK] `flowsync.*` localStorage keys and Echo app key would break on rename
- `Sidebar.jsx` and persistence code use `flowsync.sidebar.collapsed` etc.
  Renaming the product would leave stale localStorage keys causing UI glitches.
- **Fix target:** Phase 7 — decide rename scope with owner before writing a
  single line; use a config-driven `APP_STORAGE_PREFIX` for all client keys.

#### G3. [DEBT] `README.md` is stock Laravel boilerplate (59 lines)
- No product readme exists at the repo root. `AGENTS.md` is the AI reference
  but not suitable for a human developer README.
- **Fix target:** Phase 7 (during rename/branding) — write a real README with
  setup instructions, architecture overview, and demo credentials.

---

### H. Cross-cutting Risks & Technical Debt

#### H1. [RISK] `IMPLEMENTATION_TRACKER.md` early phases describe pre-cutover reality
- Phases 0–8 reference `TenantScoped`, shared-driver, `tenant_id` columns.
  These are historical. New developers reading the tracker might implement the
  old pattern.
- **Fix target:** Add a prominent banner at the top of `IMPLEMENTATION_TRACKER.md`
  marking Phases 0–13 as historical (pre-isolation-cutover).

#### H2. [RISK] Two `users` tables (system + tenant) is the #1 foot-gun
- Any new endpoint that resolves `User::find()` on the wrong connection returns
  wrong data or null silently. Middleware priority mitigates this for HTTP
  requests, but background jobs, artisan commands, and seeders must manually
  connect.
- **Recommendation:** Add a PHPStan/Psalm rule or a boot-time assertion that
  `User::find()` on the default connection is only called inside `using()`.

#### H3. [RISK] `30s notification poll` degrades at scale
- The SPA polls `GET notifications/unread` every 30 s per user. At 1,000
  concurrent users = 2,000 req/min to this endpoint, all hitting PG.
- **Fix target:** Phase 4 / Phase 8 — rely primarily on `NotificationSent`
  Echo push; poll only as a fallback at 60 s or on reconnect. Consider a
  cursor-based `?after=id` variant to avoid full-count queries.

#### H4. [DEBT] `IMPLEMENTATION_TRACKER.md` is 569 lines and growing
- The tracker conflates historical record (phases 0–13), in-progress
  (phases 14+), and a maturity initiative (13 items). Consider splitting into:
  - `HISTORY.md` (phases 0–13, immutable)
  - `TRACKER.md` (current + future phases only)

#### H5. [RISK] `ScaleDataSeeder` uses raw `DB::table` inserts bypassing model events
- Task creates skip `KeyGenerator`, `TenantLimits::assertQuota()`, activity
  logging, and all model observers. This is intentional for speed, but it means
  scale-seeded data has no `activities` rows and no `notifications`, which can
  make the analytics/reports pages look broken on a scale-seeded tenant.
- **Fix target:** Either accept the gap (document it) or add a post-seed
  activity backfill step.

#### H6. [GAP] No API versioning or rate limiting on domain endpoints
- `config/subscriptions.php` lists `api` as a plan module but no API key
  system, versioning, or per-tenant rate limiting exists. Plan-gating via
  `EnsureModule` only hides the routes — it does not enforce per-minute
  quotas for programmatic callers.
- **Fix target:** Phase 8 (or a Phase 17 API expansion) — throttle middleware
  on domain endpoints by `tenant_id` (not IP); API module gate means the routes
  are accessible, not rate-unlimited.

#### H7. [RISK] Channel auth (`channels.php`) `workspace.{id}` has no suspended check
- Same gap as A7 but specifically: a user from a suspended tenant can
  authenticate the `workspace.{id}` channel if they have a valid session.
  Reverb broadcasts would still reach them.
- **Fix target:** Add tenant lifecycle check in `channels.php` callbacks.

#### H8. [GAP] No frontend error boundary around HRMS pages
- The 43 HRMS SPA pages are large (Statutory.jsx = 29k, Leave.jsx = 28k,
  Holidays.jsx = 29k). A JS runtime error in one page crashes the whole
  module without a recovery UI.
- **Fix target:** Phase 3 — wrap each HRMS hub in a React `ErrorBoundary`
  with a graceful fallback and a "Report issue" link.

#### H9. [DEBT] No frontend tests / no JS test runner configured
- `HrmsShellTest` pins JS string literals server-side (PHP) as a proxy for
  correctness. No actual frontend tests (Vitest/Jest) exist. API contract
  changes would not be caught until runtime.
- **Fix target:** Phase 8 (or later) — add Vitest + a handful of critical
  component tests (AuthContext, TaskDetail, KanbanBoard DnD).

#### H10. [GAP] `onboarding_complete` middleware blocks self-registered tenants from
  all domain routes until the wizard completes — but error messages are generic 403s
- Users who abandon the wizard mid-way and try a deep link get a generic 403.
  No redirect to the wizard or a helpful message.
- **Fix target:** Phase 3 (UX) — `EnsureOnboardingComplete` should redirect
  to `/onboarding` instead of returning 403 when the session is a tenant user
  with an incomplete onboarding.

#### H11. [IMPROVEMENT] `me()` payload does not include tenant profile fields
- Tenant profile (legal name, industry, timezone, locale, branding) is
  available on `GET /api/tenant/profile` but not in `me()`. The SPA has to
  make a second request to personalize the UI (e.g. locale-aware date formats,
  branded colors).
- **Fix target:** Phase 3 or Phase 5 — extend `me()` to include a minimal
  tenant profile subset: `timezone`, `locale`, `branding.primary_color`.

#### H12. [IMPROVEMENT] Global search results cap at 8 tasks, 5 projects, 5 workspaces, 5 users
- These caps are hardcoded in `GlobalSearchController`. On a tenant with 250k
  tasks the most relevant result may not appear in the top 8.
- **Fix target:** Phase 4 — make caps configurable via query param
  (`?limit=` per category); default stays 8/5/5/5 but power users can request more.

#### H13. [GAP] No `robots.txt` / `sitemap.xml` for marketing site derived from CMS data
- Wait — confirmed SHIPPED in Phase 11 (CMS). This finding is resolved. ✅

#### H14. [GAP] `EnsureModule` does not distinguish between "module not on plan" and
  "module disabled by admin via Feature Management"
- Both cases return the same 403. UI cannot distinguish to show "Upgrade your
  plan" vs "Contact your administrator".
- **Fix target:** Phase 5 (or Phase 3 if impacting HRMS UX) — return a
  structured 403 body: `{"error":"module_not_on_plan"}` vs `{"error":"module_disabled"}`.

---

### I. Pending Owner Decisions (blocking or shaping implementation)

| # | Decision | Blocks | Deadline |
|---|---|---|---|
| 1 | New application name + infra-rename scope | Phase 7 | Before Phase 7 starts |
| 2 | Stripe-first vs locale→provider split; test-vs-live staging env | Phase 6 | Before Phase 6 starts |
| 3 | Export Data: add-on `export.full` vs 4th plan tier | Phase 5 | Before Phase 5 starts |
| 4 | SMTP provider + from-address; immediate vs digest board mail | Phase 4 | Before Phase 4 starts |
| 5 | HRMS single-tab sub-tabs IA approval (see C1 above) | Phase 3 | Phase 3 kickoff |
| 6 | Redis optional (env-driven) vs DB cache/queue only | Phase 2 | Phase 2 kickoff |
| 7 | `hrms.shifts` / `hrms.talent` — stub or in-roadmap? | Phase 3 | Phase 3 kickoff |
| 8 | API versioning + per-tenant rate limits scope | Phase 8 | Phase 8 planning |

---

### J. Summary: Priority Matrix

| Finding | Phase | Severity |
|---|---|---|
| A2 — tenant reset broker silent failure | 4 | HIGH |
| A3 — `hasModule` null bypass fail-open | 1 residual / 8 | HIGH |
| B1 — `notifications` missing composite index | 2 | HIGH |
| B3 — no `pg_trgm` for search | 2 | HIGH |
| B4 — `TenantLimits::effective()` not memoized | 2 | HIGH |
| E6 — `storage_bytes` quota not enforced on uploads | 2 / 5 | HIGH |
| D1 — zero email delivery | 4 | HIGH |
| C1 — Sidebar HRMS flat list (25+ items) | 3 | MEDIUM |
| C3 — no mention autocomplete UI | 4 | MEDIUM |
| D2 — no notification preferences | 4 | MEDIUM |
| B5 — `SystemAnalyticsController` cold fan-out | 2 | MEDIUM |
| H3 — 30s notification poll at scale | 4 | MEDIUM |
| H8 — no HRMS error boundaries | 3 | MEDIUM |
| H10 — `onboarding_complete` 403 instead of redirect | 3 | LOW |
| H11 — `me()` missing tenant profile subset | 3/5 | LOW |
| H12 — global search hard-coded caps | 4 | LOW |
| A4 — `06-tms.md` stale SVG reference | immediate | LOW |
| G3 — stock README | 7 | LOW |
| H4 — tracker size/split | 7 | LOW |


---

## Phase 2 report (2026-10-06) — Database & Performance

**Status:** complete. Full suite green in a single run (**1384 tests / 6862
assertions**, all 145 test files; +12 tests from the new gate file over the Phase 1
gate of 1372/6829). `pint --test` clean repo-wide — the 3 pre-existing failures
(`HrmsInboxTest`/`HrmsStatutoryIntegrationTest`/`HrmsPayslipAccessTest`) were
style-only and are fixed in this commit. No JS changed, but `npm install &&
npm run build` was run anyway: the host had no `node_modules`/`public/build`, so
`ExampleTest` 500s on a missing Vite manifest until it is built.

### Implemented

| Item | Outcome |
|---|---|
| B1 `notifications(user_id, read_at)` | Pre-existing — asserted, not duplicated |
| B2 `(status_id, position)` on `tasks` | New index in `2026_10_13_000036_add_phase2_performance_indexes` |
| B3 trigram search | `pg_trgm` GIN on `tasks.title`/`tasks.description`/`users.email`, **opt-in** `ENABLE_TRGM=true` (PG-only; sqlite has no trigram ops, `CREATE EXTENSION` needs install rights). No query rewriter — the existing `LIKE '%q%'` plans pick the index up |
| B4 `TenantLimits::effective()` | Static memo keyed (tenant, plan_id, override) → one pair of central queries per unit of work; flushed on `RequestHandled` + `JobProcessing` |
| B5 analytics cold fan-out | `PlatformResourceTotals` (60 s fresh / 600 s TTL) + queued `RefreshPlatformAnalyticsJob`; the controller serves stale and refreshes |
| B6 payroll N+1 | Run loop chunked at 100 employees with `PayrollAssignmentMap` pre-loaded once — structure/assignment queries constant in employee count |
| B7 `me()` caching | **Deferred — see below** |
| B8 board-move amplification | Same-column move = 1 bulk `CASE` statement (was N single-row updates); cross-column = 2. Pinned by `DBPerformanceTest` |
| B9 infra connection pins | `AppServiceProvider::assertInfrastructureConnectionsPinned()` throws at production boot if `SESSION_CONNECTION`/`DB_CACHE_CONNECTION`/`DB_QUEUE_CONNECTION` fall through to the tenant default |
| B10 bare-FK indexes | `offboarding_case_tasks.expense_claim_id`, `payslip_adjustments.reference_id`, `leave_adjustments.reference_id`; `approvals(type,id)` verified pre-existing |
| B11 `attendance_days` unique | Pre-existing — asserted, not duplicated |

### Why B7 was deferred

`me()` costs ~6 indexed queries once per SPA load (React StrictMode doubles it only
in dev). The cache key would have to be invalidated by ~10 mutation paths — role
changes, theme save, onboarding completion, plan/module switches, impersonation start
/stop — and missing any one of them shows a user a permission set they no longer have
(or hide one they were just granted). That failure is worse than the 6 queries. Revisit
only with an invalidation-by-event design, not a TTL.

### Migrations & env

- New tenant migration: `2026_10_13_000036_add_phase2_performance_indexes` (repair-safe
  `hasTable`/`hasColumn`/`indexExists` guards; `$withinTransaction = false` so PG can
  `CREATE INDEX CONCURRENTLY`). Reached by `php artisan tenants:provision`
  (pending-only) on the next run.
- Optional env: `ENABLE_TRGM=true`. No required env changes; Redis stays an
  optional env-driven upgrade (decision #6 — **not blocking**, DB fallback remains
  the default for cache/queue/sessions).

### Verification & how to re-run

- `tests/Feature/DBPerformanceTest.php` — 12 tests, the permanent gate: index columns
  present, trigram only when flagged, memo freshness + flush, board-move statement
  counts (via `DB::getQueryLog`), payroll structure-query count, analytics
  cold/warm/stale-with-queued-refresh/fresh-no-refresh, and the production
  connection-pin guard (reflection + `app['env'] = 'production'` toggling).
- Focused regression batch green alongside it: `TaskTest`, `AnalyticsTest`,
  `NotificationTest`, the three payroll suites, `ModuleGateTest`, `TenantLimitsTest`.
- Query-count evidence: board move 1 statement; payroll 2 structure queries for
  6 employees; warm analytics 1 query.
- **Endpoint p95** (in-process probe, throwaway `ZzPerfProbeTest`, since deleted —
  seeded at the fleet density `tenants:seed-scale` creates: 2 500 tasks / 25 projects,
  20 iters each, sqlite tenant DB on tmpfs):

  | endpoint | p50 (ms) | **p95 (ms)** | p95, index dropped (ms) |
  |---|---|---|---|
  | `/api/dashboard` | 10.0 | **11.8** | 12.1 |
  | project board | 19.4 | **22.7** | 24.1 |
  | `/api/search/tasks?q=task` | 20.6 | **30.2** | 25.9 |
  | `/api/auth/me` | 3.1 | **6.0** | 5.5 |

  All comfortably inside the <3 s exit gate. Dropping the new `(status_id, position)`
  index moves each by 1-4 ms (noise) — at this density these endpoints are bounded by
  row hydration, not the sort, so the index buys headroom at wider boards rather than
  a number here. **Stress case:** 25 000 tasks in a *single* project puts the board at
  p95 **4.1 s** (over budget) — per-project density, not fleet volume; board
  pagination/limits for very large single projects is the follow-up.

### What the work surfaced (two silent-failure bugs)

1. **The new migration first named `payroll_adjustments`** — no such table (it is
   `payslip_adjustments`) — and its own `Schema::hasTable()` guard skipped the index,
   so the migration was green *and empty*. Same defect class as the `document_types`
   catalogue finding: a name that maps to nothing, invisible until a test asserts the
   outcome. `DBPerformanceTest` now asserts the index set by column, not by migration
   exit code.
2. **The B4 memo does not hash plan limits.** `$plan->update(['limits' => …])` keeps
   the same `(tenant, plan_id, override)` key, so the memo kept serving the old merge
   until process recycle — 3 `ModuleGateTest` failures (grant a module → next request
   still 403). Fixed by making the memo explicitly *unit-of-work* scoped
   (`RequestHandled` + `JobProcessing` flushes in `AppServiceProvider`, plus the
   per-test flush in `IsolatesDatabase`), which is also the correct production shape:
   a plan edit must be visible on the very next read.

### Open

- **Board pagination** for single projects far beyond fleet density (the 25k-task
  stress case above is the only number that breaches <3 s).
- Remaining known hot paths are tracked in `.agents/11-testing-ops.md`
  (uncached dashboard/reports/search, 30 s polls, DB-backed infra on one PG).

## Phase 3 report (2026-10-06) — HRMS one-tab IA & parity audit

**Status:** complete. `HrmsNavTest` (4 tests) + `HrmsShellTest` (22, +1) green
alongside the full HRMS feature suites in one focused run (31 passed / 261
assertions), `pint --test` clean repo-wide, `npm run build` clean. Full suite
gate re-verified at the close (count on the `AGENTS.md` `## Commands` line).

### Implemented

| Item | Outcome |
|---|---|
| One-tab IA | `Sidebar.jsx` People section collapsed to ONE `/hrms` entry (inbox badge on the item itself via `useNotifications()`); all 30 HRMS pages now sub-tabs of the `/hrms` hub |
| Hub rail | `resources/js/components/hrms/HrmsLayout.jsx`: sticky grouped desktop rail (w-52) + mobile horizontal strip, tabs filtered by `useAuth().check()` against `resources/js/utils/hrmsNav.js` `capabilities` (module/permission prefixes in the same grammar `hasAccess` reads) |
| Routes unchanged | All 68 route/gate tokens verified identical before/after the `App.jsx` restructure (children keep absolute paths under the nested hub); no redirects, no deep-link breakage |
| Gate parity pin | `HrmsNavTest` parses `App.jsx` + `hrmsNav.js` and asserts each tab's capabilities equal its route gates exactly (stack model for inherited group `<Route element>` gates + inline `permission=`/`module=`) |
| Parity audit | Regularization ✓ · TDS surrender ✓ (Statutory.jsx `canManage` block) · 1:1s ✓ (policy = participant-or-manage) · survey anonymity ✓ (structural) |
| Leave exemption gap | `Leave.jsx` fetched `/hrms/leave/exemptions` unconditionally → a plan without `hrms.leave.exemption` 403'd the **whole** catalogue; now `showExemptions`-gated (tab + fetch) and the admin Exemptions tab gained the filing form (`POST /hrms/leave/exemptions`) with a post-save refetch |
| Plan-doc statuses | `docs/hrms-implementation-plan.md`: umbrella header corrected (was "plan only") + `**Status: done.**` per build phase P3–P21 naming each phase's guard test files |

### Deviations & decisions

- **Sub-tab scheme is path-based, not `?tab=`.** The routes were already
  `/hrms/{section}`; the hub simply nests them. No redirect preserves a query
  design nothing implemented, so none was built. `activeHrmsTab()` is longest-prefix,
  so the hub's active pill is right for `/hrms/*` URLs and defaults to the overview.
- **7 param routes are sub-pages, not tabs** (`/hrms/:section`,
  `/hrms/employees/:employeeId`, `/hrms/engagement/mine/:campaignId`,
  `/hrms/offboarding/cases/:caseId`, `/hrms/onboarding/cases/:caseId`,
  `/hrms/payroll/runs/:runId`, `/hrms/performance/cycles/:cycleId`) — excluded from
  the tab-parity assertions by name.
- **Verdict buttons on shared queues stay visible; a wrong-approver click 403s to a
  toast, not a /403 redirect** — the app-wide convention (only a *load* `fail()`
  navigates to /403). Keeps the audit-trail invariant server-side while a manager
  glancing at a queue isn't bounced off the page.
- **C7 (task→case progress push-back) and C8 (auto-derive-from-work-logs toggle)
  deferred.** C7 reverse of the shipped P20 pull-only sync is a behaviour change to
  approved flows (own task). C8 has no `Attendance settings` page to host the
  toggle; the derive-at-write path itself is already pinned by
  `HrmsDeriveAttendanceTest` and the config flag, so the work is a settings surface,
  not the feature.

### Verification & how to re-run

- `tests/Feature/HrmsNavTest.php` — 4 tests, the permanent IA gate: exactly one
  People→`/hrms` sidebar entry (already pinned by `HrmsShellTest`'s route list);
  route↔tab bidirectionally; per-tab gate equality after stripping the
  `module:`/`permission:` prefixes on both sides; hub-layout regex + the rail
  reading `hrmsNavGroups`/`activeHrmsTab` and forwarding `useNotifications()`.
- `tests/Feature/HrmsShellTest.php` — count is 22: new
  `test_the_leave_page_gates_its_exemption_queue_and_can_file_asks` pins the
  `hasModule('hrms.leave.exemption')` guard, the filtered tab map, the conditional
  fetch and the `POST /hrms/leave/exemptions` filing call in `Leave.jsx`.
- Focused batch: `--filter='HrmsNavTest|HrmsShellTest|HrmsLeaveExemptionApiTest|HrmsLeaveRequestApiTest'`.
- Full suite: `TMPDIR=/dev/shm/flowsync-tmp php artisan test` (see `## Commands` for the gate).
