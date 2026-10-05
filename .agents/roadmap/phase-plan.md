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
**Affected:** `resources/js/pages/hrms/*`, `Sidebar.jsx`, `App.jsx` (sub-tab URL
scheme reusing `?tab=`), controllers/services only for found gaps,
`docs/hrms-implementation-plan.md` status lines, `config/hrms.php` starters.
**DB:** none planned (repairs only).
**API/UI:** sidebar regroup with redirects (bookmarks keep working); sub-tab
permission split mirroring backend policies; follow `skills/hrms-contributor/SKILL.md`.
**Risks:** nav rework breaking deep links — legacy routes become redirects;
sub-tab gates must match server policies row-for-row.
**Tests:** `HrmsNavTest` (gates per hub), existing HRMS feature files for touched
domains, shell JS-string pins updated.
**Exit gate:** single-tab IA live with all features reachable + gated; stale plan-doc
statuses corrected.

## Phase 4 — TMS

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
