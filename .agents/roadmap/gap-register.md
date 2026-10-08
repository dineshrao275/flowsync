# FlowSync — Gap Register (Missing & Needs-Improvement)

**Basis:** `verification-report.md` (code audit at commit `d5bc70c`). Companion:
`end-to-end-flow.md`. This register is the raw material for the development plan — it does
**not** prescribe phase ordering; see "Top 10" at the end for a recommended start.

**Priority key (from product plan §30):** P0 = security / data-loss / blocking ·
P1 = required for an enterprise product · P2 = important product capability ·
P3 = enhancement · P4 = future/optional.

**Classification:** 🔴 missing · 🟠 exists but needs modification · 🟡 partial ·
🔵 reserved/stub · ⏸️ deferred by design.

---

## A. Missing features (🔴)

| ID | Pri | Domain | Gap | Why it matters | Depends on |
|---|---|---|---|---|---|
| G-1 | P1 | Platform ops | **No scheduler registration for the 12 `hrms:*` commands** (rollup, comp-off accrual, onboarding reminders, document expiry, report digests, survey open/close, performance evidence, statutory recompute, retention) — `routes/console.php` schedules only usage + backup — **✅ RESOLVED 2026-10-08** (commit `e60333b`: 10 `hrms:*` entries + `tenants:expire-trials` + `flowsync-scheduler.timer` + runbook §2.5; `hrms:backfill-employees`/`hrms:statutory-recompute` excluded by design as operator-only) | Entire classes of shipped HRMS features silently never run; tenants see stale balances/reminders | Fix: add `Schedule::` entries + document cron/systemd timer for `schedule:run` (operational doc exists: `docs/runbook.md`) |
| G-2 | P1 | Billing | **No trial/period auto-expiry** — nothing ever transitions a subscription to `expired`/`ended`; no grace period, no dunning sequence — **PARTIALLY RESOLVED 2026-10-08** (trial half: `tenants:expire-trials` scheduled daily, commit `e60333b`; period-end auto-expiry + grace/dunning still open, see G-11) | Revenue leak: trials run forever; failed payments only suspend via webhook | G-1 (same scheduling mechanism) |
| G-6 | P1 | Security/audit | **Login/logout not audited** — no `AuditLog` writes in `AuthController`, no auth-event listeners | Plan §17 requires login tracking; compliance question #1 auditors ask | — |
| G-7 | P1 | TMS | **No task workflow transition engine** — only per-project status CRUD; any→any status allowed, no validators/conditions/post-actions/transition permissions/SLA | Plan §6 core requirement; JIRA-class differentiator; blocks agile reports that depend on transition history | Statuses exist; needs `status_transitions` model + enforcement in `TaskService::update/move` |
| G-42 | P1 | Platform | **No automation engine** (EVENT→CONDITION→ACTION) shared by HRMS+TMS | Plan §15; recurring requirement (due-date nudges, auto-assign, on-join task creation) | Domain events exist (TaskSynced etc.) — needs a rule store + dispatcher |
| G-11 | P1 | Billing | **No invoices, no dunning, no grace period, no proration** | Enterprise billing expectation; plan §11 core | Gateways (✅) → invoice entity + generation on payment events |
| G-49 | P1 | API | **No API tokens / Sanctum / OAuth / versioning / scopes** — session-only SPA API | Every integration, partner, and "API access" plan module is blocked | Decide token model first (Sanctum recommended) |
| G-27 | P1 | Security | **Exited/terminated users can still authenticate** (no auth-level deactivation on status change) — *verify then fix* | Offboarding's "access revocation" is a checklist to-do today | Confirm behavior with a test, then block in `loginIsolated` |
| G-4 | P2 | TMS search | **No JQL-like query parser**; task search is 10 fixed filter params | Power-user expectation; plan §6 | Saved filters (G-5) share parser groundwork |
| G-5 | P2 | TMS search | **No saved/sharable filters** (filters live in React state) | Daily-driver feature; cheap win | — |
| G-2a | P2 | TMS | **Watchers: no notification fan-out, `present()` omits them, no UI** | Table+CRUD shipped but half-wired — worse than absent | `NotificationService` recipients + `TaskService::present` + TaskDetail UI |
| G-2b | P2 | TMS | **Backend-only task attributes invisible to users**: `start_date`, `story_points`, `issue_type_id`, `version_id`, `components` — no field in any SPA form/card/filter; `filtersPayload()` emits option lists no client consumes | Users cannot set what the backend validates; dead payload | CreateTaskModal, TaskDetail, TaskCard/Table, FiltersBar |
| G-3a | P2 | TMS | **Issue-type CRUD unrouted** — `IssueTypeService::create/update/delete` exist, controller exposes only `index`, while config promises runtime rename/recolor/reorder/add | Documented capability is unreachable | Routes + policy + UI (project settings) |
| G-8 | P2 | Reporting | **No team/project/workspace dashboards, no custom widgets, no saved layouts** | Plan §6 dashboards section | — |
| G-46 | P2 | Reporting | **No agile metrics**: velocity, burndown/burnup, cycle/lead time, throughput, workload, capacity, SLA | JIRA-class reporting; management buy-in surface | Sprint data (see G-60) or transition timestamps for cycle time |
| G-10 | P2 | Approvals | **Approval engine lacks**: parallel / any-one / all / conditional modes, approver **delegation**, escalation, SLA/reminders | Plan §8; leave delegation is a common enterprise ask | `ApprovalService` extension + `approval_steps` shape review |
| G-16 | P2 | HRMS | **No transfer / promotion / confirmation workflows** (entity, approval, effective date, letter) — only free-form status changes + date columns | KEKA-class HR lifecycle expectation | Status history ✅ is the substrate |
| G-17 | P2 | HRMS | **No full & final settlement, no exit interview, no e-signature** | Offboarding completeness; F&F is finance-critical | `exit_clearances` counters (✅) feed F&F |
| G-24 | P2 | HRMS | **No bulk employee import (CSV) or bulk status change** | Onboarding >25 employees by hand is a deal-breaker | Employee code generator + validation pipeline reusable |
| G-31 | P2 | HRMS | **Expense category spend limits, mileage, advances** (and payroll **loan/advance deductions**) | Common finance asks | Payroll adjustments (✅) accept new kinds |
| G-32 | P2 | HRMS | **Payroll cannot be reopened** (no un-lock/un-publish transitions) | Ops will hit a mistake; currently requires DB surgery | `PayrollLifecycle` reverse transitions + audit |
| G-34 | P2 | HRMS | **No bank file generation** (salary disbursement upload) | Payroll run is unusable without manual transfer list | `net_pay` + bank fields exist on employee? verify |
| G-37 | P2 | HRMS | **Talent module 🔵 reserved**: no skills, skill matrix, career paths, succession, talent pools | Key differentiator vs KEKA; module key already sold-able | Decide build vs delist (`hrms.talent`) |
| G-28 | P2 | HRMS | **Shifts 🔵 reserved**: tables + `employees.shift_id` + settings exist, zero routes/pages/rosters/swap/approvals; `config/hrms.php 'shift_patterns'` unseeded | Attendance model already consumes shifts internally — surface is missing | Schema (✅) → directory/service/routes/SPA |
| G-51 | P2 | Mobile | **No PWA/service worker/mobile app** — punch, leave, approvals, my-tasks are phone-first use cases | Plan §24; remote punch exists server-side with nothing to send from a phone | Responsive shell ✅ → manifest + shell caching at minimum |
| G-20 | P2 | Security | **No 2FA/TOTP** | Enterprise checklist | Session model ✅ |
| G-21 | P2 | Security | **No SSO/SAML/OIDC** | Enterprise checklist; ties to plan `api`/SSO packaging | G-49 (API identity work) |
| G-45 | P2 | Integrations | **No outbound webhooks** (`WebhookController` = inbound payments only) | Plan §18/§20; automation + integrations hinge on it | G-42 event bus |
| G-44 | P3 | Notifications | **No digest/batching/throttling/dedup** for platform notifications | Notification fatigue at scale | HRMS `hrms_report_schedules` pattern reusable |
| G-47 | P3 | Reporting | **No cross-domain reports** (attendance vs delivery, cost analytics, utilization) | The product's core thesis (HRMS+TMS synergy) is unproven in data | Read-only aggregation strategy; never cross-tenant joins in tenant DBs (plan §16) |
| G-23 | P3 | Platform | **No custom-field engine** (employees/projects/tasks/expenses/assets/documents) | Consciously deferred (multi-tenancy §15 Q5); tenants ask for it first | JSONB column strategy + per-entity forms + filter integration |
| G-38 | P3 | HRMS | **No document versioning** (replace-only) | Audit trails on contract changes | `employee_documents` (✅) |
| G-48 | P3 | Search | **No `tsvector` full-text; LIKE only** (opt-in `pg_trgm`) | Scale + relevance | Per-tenant PG migration path |
| G-54 | P3 | SA platform | **No SA UI for platform health, backups, failed jobs, queue monitoring** (`GET /api/platform/health` exists, no page consumes it) | Ops visibility per plan §21 | `PlatformHealthController` ✅ |
| G-41 | P3 | Observability | **No structured logs, no correlation/request IDs, no metrics/tracing** | Plan §28 | Log channel config change + middleware |
| G-40 | P3 | Audit | **Platform audit feed: no before/after payloads, no export, 100-row caps, in-memory sort** | Plan §17 field list (actor/IP/UA/before/after) unmet centrally | HRMS `AuditTrail` diff UI is the template |
| G-52 | P3 | Testing | **No browser/E2E tests** (Dusk/Playwright), no load tests | UI regressions invisible to CI | — |
| G-19 | P3 | Tenancy | **No tenant cloning** (sandbox/demo provisioning) | Sales demos | `provisionIsolated` reusable |
| G-55 | P3 | Data lifecycle | **No tenant hard-purge / GDPR erasure path** (soft-delete only) | Legal retention questions | Needs legal retention policy decision first |
| G-12 | P3 | Billing | **`EVENT_SEATS_CHANGED` never emitted; seat limits not tracked as seat counts** — **✅ RESOLVED 2026-10-08** (emitted from `SubscriptionService::assign()` with `data.seats_from`/`seats_to`; seats already counted by `TenantLimits`/`my-usage`) | Dead API in model | Wire seat usage into `TenantLimits` + emit event |
| G-9 | P3 | Billing | **Plan modules `api` + `audit_export` gate nothing** — sellable entitlements with zero routes — **✅ RESOLVED 2026-10-08** (delisted from `config/subscriptions.php` + Feature UI + docs; H-2 option B) | Entitlement integrity: plan §12 says every capability maps to a gate | Either gate routes or delist from `config/subscriptions.php` |
| G-7b | P4 | TMS | **No sprints/backlog/estimation views, epics/initiatives hierarchy, milestones, cross-project roadmaps, portfolio view** | Largest single TMS build; plan §6 | Issue types (✅) → hierarchy needs `parent_type` or epic link; sprints are a new aggregate |
| G-50 | P4 | Integrations | **No Slack/Teams/GitHub/Google/payroll-vendor/biometric integrations** | Plan §20 phased (MVP→Enterprise) | G-45 webhooks + G-49 tokens |
| G-43 | P4 | Notifications | **Single email template, no localization, no per-tenant templates, no push** | Polish | — |
| G-25 | P4 | HRMS | **Org chart is a tree list, not a graphical chart** | Cosmetic | `DepartmentChart` data ✅ |
| G-26 | P4 | HRMS | **No business units / legal entities / cost centers / teams** | Enterprise org modeling | Decide if departments+locations suffice for target ICP |
| G-15 | P4 | TMS | **Work logs: no start/stop timer UI, no billable flag, no approval flow, no timesheet periods** | Time data exists; workflow polish | Approval engine reusable |
| G-14 | P4 | TMS | **Dependency enum is `blocks\|related_to` only** — plan promised `relates\|duplicates\|clones` | Cheap enum + presentation work | — |
| G-13 | P4 | Attendance | **Geofence/IP rules are flag-not-block, and SPA punch sends no lat/lng** — the feature is invisible | Server capability unused | `ClockInWidget` payload + policy decision (flag vs refuse) |
| G-30 | P4 | Leave | **No approval delegation** ("while I'm out, X approves") | Meta-problem: approvers go on leave | G-10 |
| G-29 | P4 | Leave | **Carry-forward has columns + ledger but no endpoint/command to apply it**; manual balance adjustment also unrouted | Year-boundary operation missing | `leave_adjustments` (✅) |
| G-33 | P4 | Payroll | **Payslips render HTML, not PDF** | Download format expectation | `PayslipRenderer` |
| G-36 | P4 | Performance | **No competencies, KPI entities, PIP, formal 360, calibration tooling** | Performance depth | Cycles/stages (✅) substrate |
| G-39 | P4 | HRMS | **`hrms.exemptions` module key routes nowhere** (leave exemptions ride `hrms.leave.exemption`) | Either wire it or remove from plan catalog | — |
| G-35 | P4 | HRMS/TMS | **Two goal↔task link mechanisms** (`goal_task_links` + `hrms_task_links`) | Consolidation debt | Pick one, migrate |
| G-18 | P4 | HRMS | **No compensation/revision/promotion letter generation** (template doc type only) | Nice-to-have | Document renderer + signed download pattern |
| G-53 | P4 | Observability | **Queue health probe only echoes driver name** | Misleading health output | `PlatformHealthController:162` |
| G-56 | P4 | Payroll | **No payroll-specific CSV/register export** | Analytics CSV exists but isn't a payroll register | — |
| G-57 | P4 | HRMS | **Hourly leave, blackout dates, holiday CSV/ICS import, break punch in/out, biometric** | Long-tail HR features | — |

---

## B. Exists but needs modification / hardening (🟠🟡)

| ID | Pri | Item | What must change |
|---|---|---|---|
| H-1 | P1 | **Scheduler wiring** (overlaps G-1) | Add all 12 `hrms:*` + trial-expiry job (G-2) to `routes/console.php`; document `schedule:run` cron in runbook + `docker`/systemd unit — **✅ RESOLVED 2026-10-08** (10 `hrms:*` scheduled, operator-only pair excluded by design, systemd timer + runbook §2.5, commit `e60333b`) |
| H-2 | P1 | **`api` / `audit_export` modules** (G-9) | Pick one: gate real routes on them (API work is G-49) **or** remove from `config/subscriptions.php` + Feature UI so plans don't sell dead entitlements — **✅ RESOLVED 2026-10-08** (option B: removed everywhere) |
| H-3 | P1 | **Exited-user authentication** (G-27) | Verify with test; block login for `exited`/`terminated` (or tenant-configurable grace) in `loginIsolated` |
| H-4 | P2 | **Watcher half-wiring** (G-2a) | 1) `NotificationService` adds watchers as recipients (dedup vs assignee/mentions); 2) `TaskService::present()` emits `watchers`; 3) TaskDetail shows watcher list + watch toggle |
| H-5 | P2 | **Surface the TMS expansion in the SPA** (G-2b) | Add issue type / version / components / start date / story points to CreateTaskModal + TaskDetail; render issue-type chip on TaskCard/TaskTable; wire `issue_types`/`versions`/`components` option lists from `filtersPayload()` into FiltersBar; use story points for a future velocity view |
| H-6 | P2 | **Route issue-type CRUD** (G-3a) | Expose create/update/delete (+ reorder/recolor) behind project workflow permission, matching `config/issue_types.php`'s promise; add tests |
| H-7 | P2 | **Platform audit feed** (G-40) | Persist before/after diffs (follow `HrmsAuditLogger` masking rules), raise/remove the 100-row in-memory cap with real pagination, add export, add login/logout rows (G-6) |
| H-8 | P2 | **Queue health probe** (G-53) | Actually ping the worker (e.g., push a no-op job with timeout) instead of echoing `config('queue.default')` |
| H-9 | P2 | **Docs contradicting code** (verification-report §17) | Correct: AGENTS register/GuestRoute lines, `.agents/06-tms.md` mail/export claims, `.agents/memory/state.md` payments/factories rows, multi-tenancy `task.watched` claim, tracker self-contradiction, "Phase 7" route-label collision, runbook §6 — **✅ RESOLVED 2026-10-08** (all 15 §17 items corrected; the test-count claim reconciles with the phase's final full-suite run, H-18) |
| H-10 | P2 | **Dependency types** (G-14) | Extend `TaskDependencyType` to `relates\|duplicates\|clones` (plan §multi-tenancy already documents intent) + UI labels |
| H-11 | P2 | **Geofence punch path** (G-13) | `ClockInWidget` sends `lat/lng/device`; decide flag-vs-block policy in settings; test the range logic from the UI path |
| H-12 | P2 | **Storage/attachment ZIP hygiene** | Delete generated export ZIPs after signed download or TTL; `hrms:retention` covers rows but not files |
| H-13 | P3 | **`HrmsScope` global reads** | Fold `TaskScope` row-scoping into search/dashboard/reports (documented follow-up in AGENTS Phase C/E matrix) |
| H-14 | P3 | **Tenant restore** | `tenants:backup --restore` (currently drill-only; real restore is manual `psql`) |
| H-15 | P3 | **`hrms.inbox` gate** | Routes sit in plain `auth` group while other HRMS sits behind `ensure_module` — either gate consistently or document the exception |
| H-16 | P3 | **`hasModule` unused keys** (`hrms.onboarding`, `hrms.offboarding`, `hrms.inbox`) | Features ship but keys never gate — align module catalog with reality (mirror in `hrmsModules.js` + shell test) |
| H-17 | P3 | **Over-limit behavior** | Quotas hard-block with 422 and no grace/overage — confirm commercial policy, then either document or add grace window (multi-tenancy §15 Q3) |
| H-18 | P3 | **Full-suite gate re-verification** | Run `php artisan test` once; reconcile documented 1535/7882 with statically counted 1469 methods (❓) |
| H-19 | P3 | **Platform RBAC** | `platform_roles`/`platform_permissions` tables are schema-only — either implement Billing Admin/Support Auditor personas or remove to avoid dead schema |
| H-20 | P4 | **Two goal↔task link tables** (G-35) | Consolidate |
| H-21 | P4 | **Localisation** | `locale=en` hardcoded; notification templates single-language (G-43) |

---

## C. Reserved / stub (🔵) — do NOT count as implemented

| Item | What exists today | What "implemented" requires |
|---|---|---|
| `hrms.shifts` | Module key, `hrms.shifts.view/manage` permissions, `attendance_shifts`/`attendance_rosters` tables, `employees.shift_id`, unseeded `shift_patterns` config, internal consumption by `DayComputation` | Catalog CRUD, assignments/rosters, swap + approval flows, SPA page, `HRMS_NAV` entry, module-gated routes, seeding |
| `hrms.talent` | Module key, `hrms.talent.*` permissions, access check in `FeedbackService` | Skills/matrix, career paths, succession, talent pools — or delist |
| `hrms.exemptions` | Module key in plan lists only | Wire leave-exemption routes to it or remove |
| Platform RBAC | Tables `platform_roles`/`platform_permissions` + pivots | Personas + enforcement beyond `is_super_admin` |

---

## D. Deferred by design (⏸️) — source documents say *not now*

| Feature | Where deferred | Should build? |
|---|---|---|
| ATS / recruitment / job postings / candidates / interviews / applicant portal | HRMS plan scope (offer-to-joining absent); roadmap §5 | Decide — usually the next big HRMS module after talent |
| Training / LMS / certifications | HRMS plan scope | P4 for target ICP |
| Statutory **filing** (challan/returns/Form 16/GSTR) + external filing integrations | HRMS plan: calc → prepare → file split | P4; calculation/preparation ✅ already |
| Payroll vendor / bank / accounting integrations | plan §20 phases | P4 after G-34 bank file |
| Custom fields beyond Phase-17 deferral | `multi-tenancy-architecture.md` §15 Q5 | P3 — revisit; tenants ask first |
| Hard tenant purge / data warehouse / OLAP | plan §10/§16 | P4 (thresholds not yet measured) |
| Microservices split | Critical rule #21 (modular monolith) | Keep monolith |
| Biometric attendance devices, push notifications, localization | plan §20/§18/§24 | P4 |

---

## E. Stale-documentation register

Verification-report §17 lists 15 doc-vs-code contradictions (AGENTS register/GuestRoute lines,
`.agents/06-tms.md` mail/export claims, `.agents/memory/state.md` payments rows, `task.watched`
promise, `config/issue_types.php` CRUD promise, test-count ❓, migration numbering ceiling,
tracker self-contradiction, Phase-7 label collision, runbook queue line, dead seat event,
delisted-but-sellable `api`/`audit_export` modules…). **Fix these in a docs-only pass (H-9)
before starting new feature work** — they are the fastest way to mis-scope a task.

---

## F. Top 10 immediate development priorities

Ordered by dependency + business value; each is independently actionable.

| # | Work | Why now | Depends on | Pri | Complexity |
|---|---|---|---|---|---|
| 1 | **H-1 + G-1/G-2: schedule the `hrms:*` commands + trial expiry job** ✅ *done 2026-10-08* | Un-ships nothing but activates ~12 already-built features and stops revenue leak; one file + runbook | Nothing | P1 | S |
| 2 | **H-9: docs-truth pass** (fix the 15 contradictions) ✅ *done 2026-10-08* | Prevents every future task from being mis-scoped; pure docs | Nothing | P1 | S |
| 3 | **G-6 + H-7: login/logout audit + audit payload hardening** | Compliance baseline; cheaper before more features land | Nothing | P1 | M |
| 4 | **H-3: block authentication for exited users** | Offboarding is not real until this holds | Verify-first test | P1 | S |
| 5 | **H-2: resolve `api`/`audit_export` dead modules** ✅ *done 2026-10-08 (delisted)* | Entitlement integrity — plans currently sell nothing | Decision only (or G-49) | P1 | S |
| 6 | **H-4 + H-5 + H-6: finish the shipped TMS expansion** (watcher notify, SPA fields, issue-type CRUD) | Code exists, users can't see it; high perceived velocity, low risk | Nothing | P2 | M |
| 7 | **G-49: API token layer (Sanctum)** | Unblocks `api` module, integrations, webhooks (G-45), SSO groundwork (G-21) | H-2 decision | P1 | L |
| 8 | **G-7: task workflow transition engine** (per-project from→to rules, permissions, audit) | Biggest JIRA-class architectural hole; prerequisite for cycle-time reports | Statuses ✅ | P1 | L |
| 9 | **G-11: invoices + dunning** | Billing completeness for enterprise | Gateways ✅ | P1 | L |
| 10 | **G-42: automation engine v1** (event→condition→action, ~5 starter rules incl. overdue nudge) | Reusable primitive; plan §15; feeds webhooks (G-45) | Event bus (exists), G-1 scheduling | P1 | L |

**Recommended single next phase for an AI coding agent:** **#1 + #2 (scheduler wiring + docs-truth
pass)** — two small, fully-verifiable tasks (focused tests for expiry, runbook update, doc edits)
that activate shipped functionality and de-risk every subsequent task. Then #3–#5 as a
"billing & compliance hardening" phase, then #6 as a UI-completion slice before starting the
larger engines (#7–#10).
