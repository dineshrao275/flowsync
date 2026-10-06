# Living State (auto-updated on every feature task)

> Rule: append/change with date + commit ref on EVERY task that moves counts,
> schema, routes, modules, or security posture. Never silently rewrite history.

## Current

- Date: 2026-10-06 · Branch: `refactor/work-hrms` · Gate: **1384 tests / 6862 assertions** (Phase 2 end-gate, single `php artisan test` run over all 145 test files; Phase 1 gate was 1372/6829).
- `.agents/` pack created (16 files). No app code changed in that commit.
- Security criticals C1–C3 **CLOSED** (Phase 1); Phase 2 (database & performance) shipped — see its report below.

## Shipped vs missing (against the 8-phase roadmap)

| Area | State |
|---|---|
| TMS (workspaces/projects/tasks/collab/time/search) | Shipped, complete |
| HRMS contexts (employee→surveys, inbox, analytics, task-links) | Shipped, complete |
| Subscriptions (plans/limits/gates/onboarding/self-service) | Code-complete, no billing |
| Notifications | In-app only; email NOT wired (`MAIL_MAILER=log`, zero `Mail::` in `app/`) |
| Exports | 2 HRMS CSVs only; no TMS/general export |
| Payments | NONE (no Stripe/Razorpay code) |
| Factories | Stock `UserFactory` only |
| Security criticals (`10-security.md` C1–C3) | CLOSED (Phase 1, 2026-10-06) |
| Database & performance backlog (`04-database.md`, plan B1–B11) | Shipped (Phase 2, 2026-10-06) — B7 `me()` caching deliberately deferred |

## Phase 1 report (2026-10-06) — COMPLETE, gate green

- Implemented: C1 full domain stack on HRMS group; C2 named-reader + show-policy
  Gate on all 4 download paths; C3 photo reads to `local` disk; SVG removed +
  tenant-mime clamp; uniform forgot-password + new `password.reset` redirect route;
  `max:72` passwords; throttles on impersonate/users-store/subscription/tenants;
  `db_*` hidden; restrictive `config/cors.php`; `.env.example` session guidance.
- Found while fixing: forgot-password 500 for every real address (missing route —
  fixed); signed asset-doc route lived INSIDE the authed group despite its
  "outside" comment (moved out — would have 401'd all fresh-tab invoice downloads).
- DB migrations: none. Env vars: optional `FRONTEND_URL` (CORS); production
  `SESSION_ENCRYPT/SESSION_SECURE_COOKIE=true` guidance.
- Verification: full suite Unit 66/107 + non-Hrms 394/2900 + Hrms 912/3822 (1
  pre-existing env skip); pint clean on all touched files (3 unrelated
  pre-existing pint failures left alone); no JS changes → no build needed.
- Remaining risks: tenant-aware reset broker; stronger password policy;
  login-enumeration keys; domain write-throttles; shared-PG-role; asset-view vs
  document-view for invoice downloads (open product decision — route unminted).

## Phase 2 report (2026-10-06) — COMPLETE, gate green

- Implemented (plan items B1–B11): `2026_10_13_000036_add_phase2_performance_indexes`
  (board `tasks(status_id,position)`, three bare-FK indexes, opt-in `pg_trgm` GIN
  behind `ENABLE_TRGM`); `PlatformResourceTotals` stale-while-revalidate for the SA
  analytics fan-out + queued `RefreshPlatformAnalyticsJob` (B5); payroll run reads
  chunked 100-at-a-time with a pre-loaded `PayrollAssignmentMap` — structure/assignment
  queries constant in employee count (B6); `TaskService` same-column moves now one
  bulk `CASE` update instead of N single-row writes (B8); `TenantLimits::effective()`
  memoized per unit of work (B4); production boot guard refusing unpinned
  `SESSION_CONNECTION`/`DB_CACHE_CONNECTION`/`DB_QUEUE_CONNECTION` (B9);
  `tests/Feature/DBPerformanceTest.php` (12 tests) as the permanent gate (B10).
- B7 (`me()` response caching) **deferred on purpose**: 6 indexed queries once per
  SPA load, and the staleness surface spans ~10 mutation paths (roles, theme,
  onboarding, plan modules, impersonation). Cost of being wrong is "user can't see
  a permission they were just granted" — worse than the 6 queries.
- Found while fixing: the new migration first targeted `payroll_adjustments`
  (real table: `payslip_adjustments`); its own `Schema::hasTable()` guard swallowed
  the typo, so the file was green and empty. Fixed + outcome-asserted by the index test.
- Found while fixing: the B4 memo's key is (tenant, plan, override) and does **not**
  hash plan limits, so `$plan->update(['limits'=>…])` stayed invisible until process
  recycle → 3 `ModuleGateTest` failures. Resolved by flushing on
  `RequestHandled` + `JobProcessing` in `AppServiceProvider` (memo = one HTTP request
  or one queued job, never a worker lifetime).
- DB migrations: 1 tenant migration (see above). Env vars: `ENABLE_TRGM=true`
  (optional, PG extension) — no required new env.
- Perf numbers: `tests/Feature/DBPerformanceTest.php` query counts (board move = 1
  statement, payroll = 2 structure queries for 6 employees, warm analytics = 1 query)
  **and** endpoint p95 measured in-process on a throwaway probe (since deleted) seeded
  at the fleet density `tenants:seed-scale` creates — 2 500 tasks / 25 projects, 20
  iters, index present: dashboard p50 10.0 / **p95 11.8 ms**, board p50 19.4 /
  **p95 22.7 ms**, search p50 20.6 / **p95 30.2 ms**, `me` p50 3.1 / **p95 6.0 ms**
  — all far inside the <3 s budget. Dropping the new `(status_id, position)` index
  moves each by only 1-4 ms (noise): at this volume the endpoints are bounded by row
  hydration, not the sort. Stress case — 25 000 tasks in ONE project — puts the board
  at **p95 4.1 s**, i.e. over budget; that is per-project density, not fleet volume,
  and points at board pagination as the follow-up (no phase-2 code path regressed).
- Verification: full suite single run green (1384/6862, all 145 files); `pint --test`
  clean repo-wide (the 3 pre-existing `Hrms{Inbox,StatutoryIntegration,PayslipAccess}Test`
  failures were style-only and are now fixed); `npm install && npm run build` green
  (this host had no `node_modules`/`public/build`, which is why `ExampleTest` 500s on
  a missing Vite manifest until built).

## Pending owner decisions

1. New application name (factories/rename phase) + infra-rename scope.
2. Stripe-first? locale→provider rule, prices/currencies, test-vs-live staging.
3. Export Data: 4th plan vs add-on module (recommended: add-on `export.full`).
4. SMTP provider + from-address; immediate vs digest board mail.
5. HRMS single-tab + sub-tabs IA approval.
6. Redis optional (env-driven) vs DB cache/queue only.

## Log

- 2026-10-05 — `.agents/` AI context pack created (README, 00–12, hrms-contributor skill, this file). Verified: routes/web.php group lines, bootstrap/app.php aliases+priority, 12/35 migrations, 137 Feature files, 43 HRMS pages, plans/modules, zero `Mail::`, demo logins, env mail/queue/cache/session keys.
- 2026-10-05 — `.agents/roadmap/phase-plan.md` written (8 phases, sequential gates, per-phase affected files/DB/API-UI/risks/tests/exit gates). No app code changed.
- 2026-10-06 — Phase 1 security shipped (C1–C3 + hardening); full suite 1372/6829 green.
- 2026-10-06 — Phase 2 database & performance shipped (indexes, analytics SWR, payroll chunking, board-move batching, `TenantLimits` memo, prod connection-pin guard, `DBPerformanceTest` gate); full suite 1384/6862 green (single run), endpoint p95 at fleet density 11.8/22.7/30.2 ms (dashboard/board/search). B7 `me()` caching deliberately deferred.
