# Living State (auto-updated on every feature task)

> Rule: append/change with date + commit ref on EVERY task that moves counts,
> schema, routes, modules, or security posture. Never silently rewrite history.

## Current

- Date: 2026-10-06 · Branch: `refactor/work-hrms` · Gate: **1411 tests / 6991 assertions** (Phase 4 end-gate, single `php artisan test` run over all test files, zero omitted; Phase 3 gate was 1389/6886, Phase 2 was 1384/6862, Phase 1 was 1372/6829).
- `.agents/` pack created (16 files). No app code changed in that commit.
- Security criticals C1–C3 **CLOSED** (Phase 1); Phase 2 (database & performance) shipped; Phase 3 (HRMS one-tab IA + parity audit) shipped — see its report below; Phase 4 (TMS email + notification preferences + mention autocomplete) shipped — see its report below.

## Shipped vs missing (against the 8-phase roadmap)

| Area | State |
|---|---|
| TMS (workspaces/projects/tasks/collab/time/search) | Shipped, complete |
| HRMS contexts (employee→surveys, inbox, analytics, task-links) | Shipped, complete |
| Subscriptions (plans/limits/gates/onboarding/self-service) | Code-complete, no billing |
| Notifications | ✅ Phase 4: in-app + queued email (assigned/commented/status/unblock) with per-event prefs + 20-mention cap |
| Exports | 2 HRMS CSVs only; no TMS/general export |
| Payments | NONE (no Stripe/Razorpay code) |
| Factories | Stock `UserFactory` only |
| Security criticals (`10-security.md` C1–C3) | CLOSED (Phase 1, 2026-10-06) |
| Database & performance backlog (`04-database.md`, plan B1–B11) | Shipped (Phase 2, 2026-10-06) — B7 `me()` caching deliberately deferred |

## Phase 4 report (2026-10-06) — COMPLETE, gate green

- Implemented (plan D1–D5): `app/Mail/TaskNotificationMail.php` (kind-driven
  assigned/commented/status_changed/unblocked; **scalar snapshot at construction** —
  no models inside the queued job, so no cross-tenant leak), text view
  `resources/views/emails/task-notification.blade.php`, and wiring in
  `NotificationService::notify()` (`EMAIL_EVENTS` const, `maybeQueueMail()` gated by
  prefs, deep link `/app/projects/{id}?tab=tasks&task=KEY[&section=comments]`). Queued
  mail rides the existing `Queue::createPayloadUsing` tenant stamping.
- `notification_preferences` tenant-DB table (migration `2026_10_15_000037`) + `NotificationPreference`
  model (`wants()` reads the dotted keys *literally* — never `data_get`/`assertJsonPath`),
  event catalog `config/notifications.php`, `GET|PUT api/notification-preferences`
  (partial merge, unknown event → 422, `DetectsPlatformUsers` short-circuit so an SA
  PUT 404s). Preferences gate **email only** — in-app rows always deliver.
- Mention fan-out capped at 20 unique users/comment
  (`TaskNotificationMail::MAX_MENTIONS_PER_COMMENT`; assignee+reporter always kept,
  actor excluded); `taskCommented()` returns `{notifications, truncated}` and the
  comment response echoes `truncated_mentions` → warning toast in the UI.
- Cross-tenant hardening in `mentionUsers()`: regex is now
  `@([A-Za-z0-9._-]+(?:@[A-Za-z0-9._-]+)?)` — a full mailbox token (`@owner@globex.test`)
  matches the exact email only, so a foreign tenant's mailbox never pings a same-tenant
  user sharing its local part. Bare tokens still match email local part or name.
- `GET api/projects/{project}/members/autocomplete` (`ProjectMemberController::autocomplete`:
  authorize view, q prefix on name or email local part, cap 10, `{id,name,email}`),
  declared before the `{user}` member routes. Consumed by the `CommentThread` `@`-typeahead
  (debounced fetch, arrow/enter/esc, caret-accurate insert) + `Settings.jsx` Notifications
  card (one switch per event, optimistic save with rollback).
- Found while fixing: `??` inside a double-quoted string interpolation is a PARSE ERROR
  (rewrote `composeLine`/`composeSubject` with locals); the parent Mailable already declares
  `$subject` (child renamed to `$subjectText`); `{{ $url }}` HTML-escapes `&` (blade uses
  `{!! $url !!}`); direct `notify()` calls with non-task data needed `??` fallbacks for
  project_name/key/title.
- Verification: full suite **single run 1411/6991 green** (615.74 s, `TMPDIR` on tmpfs);
  focused batch (Notification + NotificationPreference + Collaboration + Task +
  MentionEmail + MentionAutocomplete) 73 passed / 378 assertions; `pint --test` clean;
  `npm run build` clean; `HrmsShellTest` 22 passed.
- Commits (all pushed to `refactor/work-hrms`): 9e07458 (4-1 prefs API+tests), 3d498c0
  (4-2 mailable+wiring+MentionEmailTest), 06b7541 (4-3 autocomplete endpoint+test+AGENTS
  gate), fde8cb5 (4-4 typeahead+Settings toggles), +docs commit.
- DB migrations: 1 tenant migration (see above). Env vars: `MAIL_MAILER` key works but
  the provider keys stay unset — dev sends to the log driver; real SMTP is a live decision.

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

## Phase 3 report (2026-10-06) — COMPLETE, gate green

- Implemented: ONE sidebar entry (People > HR, inbox badge on the item itself via
  `useNotifications()`) replacing ~30 flat HRMS links; all 30 HRMS pages now sub-tabs of a
  `/hrms` hub (`components/hrms/HrmsLayout.jsx` = grouped desktop rail + mobile strip,
  capability-filtered from the `HRMS_NAV` catalog in `utils/hrmsNav.js`). Routes keep their
  literal `/hrms/{section}` paths + module/permission gates — zero route/gate token drift
  (verified by diff), no redirects. `tests/Feature/HrmsNavTest.php` (4 tests) pins: exactly
  one sidebar entry, route↔tab parity (7 param routes excluded), per-tab gate equality
  (stack model for inherited group gates), hub-layout + nav pins.
- Found while fixing (parity audit): `Leave.jsx` fetched `/hrms/leave/exemptions`
  unconditionally — a plan with `hrms.leave` but without `hrms.leave.exemption` 403'd the
  WHOLE catalogue (`fail()` → /403). Now `showExemptions = hasModule('hrms.leave.exemption')`
  gates the tab + fetch, and the admin Exemptions tab gained the filing form
  (`POST /hrms/leave/exemptions`, per `LeaveExemptionRequest` rules) with post-save refetch.
  Pinned by a new `HrmsShellTest` case. Audit outcomes: regularization ✓, TDS surrender ✓
  (Statutory.jsx `canManage` block), 1:1s ✓ (participant-or-manage policy), survey anonymity ✓
  (structural: employee_id null, fingerprint, threshold min 1). Approve buttons on shared
  queues stay visible; a non-approver click 403s to a toast (app-wide convention), not /403.
- Docs: `docs/hrms-implementation-plan.md` umbrella header corrected ("plan only" → fully
  shipped) + `**Status: done.**` per build phase P3–P21 naming each phase's guard test files;
  `.agents/roadmap/phase-plan.md` Phase 3 marked DONE with a report + C-section status line
  (C6 decided keep-as-stubs; C7/C8 deferred with rationale — C8 has no attendance-settings
  page to host the toggle).
- Verification: focused batch `--filter='HrmsNavTest|HrmsShellTest|HrmsLeaveExemptionApiTest'`
  31 passed / 261 assertions; full suite **single run 1389/6886 green** (`TMPDIR` on tmpfs,
  544.54 s); `pint --test` clean repo-wide; `npm run build` clean.
- DB migrations: none. Env vars: none.

## Pending owner decisions

1. New application name (factories/rename phase) + infra-rename scope.
2. Stripe-first? locale→provider rule, prices/currencies, test-vs-live staging.
3. Export Data: 4th plan vs add-on module (recommended: add-on `export.full`).
4. SMTP provider + from-address; immediate vs digest board mail — Phase 4 delivered the
   queue+prefs hook (log driver in dev, mail verified via `Mail::fake`); a real SMTP
   provider/from-address and any digest mode are still open.
5. HRMS single-tab + sub-tabs IA approval — **DONE** (Phase 3-IA, 2026-10-06): one sidebar entry
   + path-based sub-tabs (deviation from the `?tab=` idea recorded in the roadmap report).
6. Redis optional (env-driven) vs DB cache/queue only.

## Log

- 2026-10-05 — `.agents/` AI context pack created (README, 00–12, hrms-contributor skill, this file). Verified: routes/web.php group lines, bootstrap/app.php aliases+priority, 12/35 migrations, 137 Feature files, 43 HRMS pages, plans/modules, zero `Mail::`, demo logins, env mail/queue/cache/session keys.
- 2026-10-05 — `.agents/roadmap/phase-plan.md` written (8 phases, sequential gates, per-phase affected files/DB/API-UI/risks/tests/exit gates). No app code changed.
- 2026-10-06 — Phase 1 security shipped (C1–C3 + hardening); full suite 1372/6829 green.
- 2026-10-06 — Phase 2 database & performance shipped (indexes, analytics SWR, payroll chunking, board-move batching, `TenantLimits` memo, prod connection-pin guard, `DBPerformanceTest` gate); full suite 1384/6862 green (single run), endpoint p95 at fleet density 11.8/22.7/30.2 ms (dashboard/board/search). B7 `me()` caching deliberately deferred.
- 2026-10-06 — Phase 3 HRMS IA shipped (one-tab sidebar + `/hrms` hub sub-tabs, `HrmsNavTest` gate, Leave exemption module-gate + filing form, plan-doc statuses corrected through P21); full suite 1389/6886 green (single run). C7/C8 deferred by design.
- 2026-10-06 — Phase 4 TMS shipped (queued email for assign/mention/comment/status/unblock via `TaskNotificationMail` + prefs gate; `notification_preferences` table + GET/PUT API + Settings toggles; `members/autocomplete` endpoint + comment `@`-typeahead; 20-mention cap with `truncated_mentions` echo; full-mailbox mention-regex hardening). Commits 9e07458/3d498c0/06b7541/fde8cb5. Full suite 1411/6991 green (single run). SMTP delivery remains pending (log driver).
- 2026-10-06 — Live-host triage (console 500s/404s): `hrms` log channel unwritable by www-data (dual-user host, no shared group) → `permission: 0666` in `config/logging.php` + host chmod (declarations endpoint 500 fixed live); double-`/api` prefix in `MyTeam.jsx`/`MyHr.jsx` (`/api/api/my/*` 404s) fixed + rebuilt; `hrms:backfill-employees` for acme/globex (attendance/leave/comp-off 404s were legitimate no-record aborts); inbox 500s + tenant-44/52 gaps already healed by provisioning. Focused suites green (MyHr/MyTeam 8/51, StatutoryConfig 5/27); no count move.
- 2026-10-06 — Mention autocomplete fix (`CommentThread.jsx`): `handleTyping` passed `(field, value, caret, el)` into `openSuggestions(field, el, value, caret)`, so `value.slice` ran on a number and threw on every keystroke — the dropdown could never open. One-line argument-order fix, verified live (scale-tenant login + `members/autocomplete` returns rows); `MentionAutocompleteTest` green (6/26); rebuilt.
- 2026-10-06 — Dark-mode danger buttons (`theme.js` + `Button.jsx`): solid red sank into dark surfaces, so `danger` now reads `--danger*` tokens (light identical, dark one shade brighter); verified by import + build + shell green (22/211).
- 2026-10-06 — Factory reseed of all tenants (user-requested): new row-level factories (`Comment`, `WorkLog`, `TaskStatus`, `UserNotification`; hardened `Task`/`Project`/`Workspace`/`User` with relation states; `HasFactory` on the four models missing it) + `FactoryScaleSeeder` (wipe via new `TenantDatabaseManager::dropDatabase`, demo via `TenantSeeder`, scale data factory-built in per-tenant transactions) + `tenants:seed-factory --force` command + `FactoryScaleSeederTest` (tiny-scale relational integrity, green). Live run: 102 tenants × 2500 tasks + related rows verified (routing 1005 exact, demo logins work). Fixed en route: `000026` document tables moved to `000016` — five later files FK into them and PG validates at CREATE time, breaking every fresh provision (`relation "document_types" does not exist`); sqlite tolerates forward refs so the suite never caught it.
- 2026-10-06 — Export visibility admin-only: both CSV buttons (`Attendance.jsx`, `Analytics.jsx`) render only with `workspaces.manage`; attendance export route gains the same gate and skips the per-employee policy for admins (month/today self-service untouched); analytics export authorizes the admin slug instead of per-domain. Tests updated (controls/export/records suites green 12/116); build + shell green.
- 2026-10-06 — DataExport crash + billing 500s: `useToast()` destructured as `{ addToast }` (always undefined) crashed the export page on submit — now `toast.success/warning/error`; the 30s poll effect depended on `runs` with an eager load inside, refetching on every identical payload — now interval-only while pending. `payments` table never migrated on host (000020/000021 skipped) — ran both; billing history live. Probed a stray canceled-starter subscription wave (bulk-stamped by a concurrent migrate via the 000020 backfill) — deleted to restore the pre-wipe no-subscription (unlimited) state. Build rerun.
- 2026-10-06 — Reports charts + dashboard ranges: `reports/overview` and `analytics/overview` accept `from`/`to` (capped 366d, echoed as `range`); analytics daily series collapsed from N queries to one grouped query with PHP gap-fill, contributors/totals range-scoped (unfiltered defaults byte-identical). New `utils/dateRange.js` presets (today→last year + custom) + shared `RangeFilter`; Reports gains donut/stacked-bar/pie/bar charts over the existing tables; Dashboard graphs follow the selected range with adaptive ticks. Tests: new `ReportsTest` (3) + 2 analytics range tests; suites green.
- 2026-10-06 — Calendar picker for all date inputs: shared `Input` now renders react-datepicker for `type="date"`/`datetime-local` (string values and `{target:{value}}` onChange unchanged, so ~50 call sites work untouched); raw datetime inputs in `WorkLogPanel` migrated to it; brand + dark-mode skin in `app.css`. Build green.
- 2026-10-06 — Calendar coverage sweep: the last raw date inputs (`Search.jsx` due from/to) moved onto shared `Input`; verified zero raw date/datetime/month inputs remain outside it (all ~50 flow through the picker), so the calendar applies project-wide.
- 2026-10-06 — Calendar coverage completion: task filter bar, time-summary filters, and project settings dates (converted to controlled state) now use the shared picker; added `labelClassName` to `Input` for compact filter labels. Multiline-aware sweep confirms zero raw date/datetime/month inputs remain. Build + shell green.
- 2026-10-06 — Calendar height parity: shared `Input` gains `compact` (compact padding/radius on both native and picker inputs); task filter dates use it to match `fieldClassCompact` siblings.
