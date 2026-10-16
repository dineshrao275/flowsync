# FlowSync — Phase-wise Delivery Plan for the Missing Functionality

Turns [`missing-functionality.md`](missing-functionality.md) (the register; IDs `M-*`) into executable phases.
**Planning only — nothing here is started.** Each phase is shippable on its own and ordered by dependency, not by
HRMS-vs-TMS. Phase names use `F` (this plan) to avoid clashing with the older `P*` numbering in `master-roadmap.md`.

## 0. Rules that apply to every task in every phase
1. **One task = one commit**, with a focused ParaTest file (`php artisan test --parallel tests/Feature/<File>.php`), plus
   `npm test`, `npm run lint`, `npm run build` when JS changed. The full suite runs at each phase's exit gate (and
   from F0 onward is the only trusted regression signal).
2. **Entitlement in the same commit:** a new capability gets a module key in `config/subscriptions.php`, the
   `ensure_module` route gate, the SPA `hasModule` gate, and a `ModuleGateTest` row.
3. **Authorization is server-side:** policy or scope check on every route; new permission slugs go in
   `config/permissions.php` (+ scope variants), then `tenants:provision` backfills; custom roles are never widened.
4. **Migrations** are repair-safe (`hasTable`/`hasColumn`), have `down()`, live in the right set (`tenant`, `tenant_tms`,
   `tenant_hrms`, `tenant_integration`, `system`), and avoid `constrained()` inside `Schema::table()` on sqlite.
5. **Events + audit:** every state change records a domain event (`DomainEvents`) and an audit row
   (`HrmsAuditLogger` / `ActivityLogger` / `PlatformAudit`). Queued jobs carry tenant context.
6. **i18n:** every user-facing string in `resources/js/locales/{en,es}` and `lang/es.json`; parity tests stay green.
7. **Docs:** update `AGENTS.md` and flip the register row to ✅ with the commit and test cited.
8. **Exit gate for every phase:** full ParaTest green · vitest green · lint at 0 errors · build ok · migration run on a
   PG tenant (`tenants:provision`) · rollback rehearsed · acceptance list below demonstrated.

Size key: S ≤ 1 day · M 2-4 days · L 1-2 weeks · XL split into slices.

## Phase map
| Phase | Theme | Register IDs | Depends on |
|---|---|---|---|
| F0 | Truth & safety baseline | M-M0, §0.1 closures, M-G5, M-F8, M-K2, M-K4, M-B12 | — |
| F1 | Approval & workflow foundation | M-F1, M-F4 | F0 |
| F2 | Custom fields, search, shared primitives | M-F2/M-A7/M-A8, M-F5, M-F6, M-F7 | F0 |
| F3 | TMS query & time layer | M-E1, M-E2, M-E6, M-E9, M-E10 | F1 |
| F4 | TMS planning layer | M-E11, M-E3, M-E4, M-E5, M-E7, M-E8, M-E12, M-E14 | F1, F3 |
| F5 | Automation & HR↔TMS integration | M-F3, M-A11, integration reads (P7) | F1, F4 |
| F6 | HR lifecycle | M-A1, M-A2, M-A6, M-A3, M-A4, M-A5, M-A12 | F1, F2 |
| F7 | HR operations & payroll depth | M-B1…B8, M-B11, M-A9, M-A10 | F1, F6 |
| F8 | Talent & performance depth | M-C1…C6 | F2, F7 |
| F9 | Billing & tenant lifecycle | M-H1…H8 | F0 |
| F10 | Integrations, API & identity | M-I1…I8, M-G1, M-G2, M-G3, M-G4, M-G6 | F2, F9 |
| F11 | Ops, observability & scale | M-J1…J8, M-M1…M-M5, M-K1/K3/K5, M-B9 | F0 |
| F12 | Experience & mobile | M-L1…L6 | F3, F6 |
| F13 | Deferred-by-design decisions | §D table (ATS, LMS, filing, SCIM, service desk) | owner decisions |

Parallelism: F9, F11 and F2 can run beside F1 (different code areas). F3→F4 is serial. F6 and F7 can overlap after F1.

---

## F0 — Truth & safety baseline
**Objective.** Make the regression signal trustworthy and close the audit/security gaps that every later phase assumes.
**Why now.** No full PHP run has finished in a long time; 15 tracks merged on focused runs only.
| Task | Deliverable | Tests / acceptance | Size |
|---|---|---|---|
| F0.1 (M-M0) | Run `php artisan test --parallel` to completion; fix or ticket every failure; re-baseline counts in `AGENTS.md` | Suite green or every red test has an owner and reason | M |
| F0.2 | Verify §0.1 items in the register (approvals v2 modes, digest scheduler, bulk/clone/move) against code and tests; close or re-scope rows | Each closed row cites test + commit | S |
| F0.3 (M-G5) | `impersonator_id` on `ActivityLogger` and `HrmsAuditLogger` rows | A task edited while impersonating shows both actors | S |
| F0.4 (M-F8) | `request_id` persisted in audit rows and job payloads | Audit row carries the id; job logs match | S |
| F0.5 (M-K2) | CSP report-only → enforce after reviewing violations | No violations in a 7-day report window; `SecurityHeadersTest` updated | S |
| F0.6 (M-K4) | Scheduled `audit:verify` with alert on chain break | Tampered row triggers the alert in a test | S |
| F0.7 (M-B12) | `hrms:leave-consistency --repair` | Detects and fixes ledger/balance drift on a fixture | S |
| F0.8 | Resolve the four doc conflicts (§0.3) | One canonical run guide; roadmap progress line rewritten | S |
**Exit:** green full suite; roadmap and `AGENTS.md` reflect reality.
**Rollback:** all tasks are additive except CSP (env switch `SECURITY_CSP_MODE` reverts instantly).

## F1 — Approval & workflow foundation
**Objective.** One approval engine used by every module; delivery controls for notifications.
**Why now.** Timesheets, gated transitions, F&F, lifecycle, payroll reopen and shift swap all need it.
| Task | Deliverable | Notes / tests | Size |
|---|---|---|---|
| F1.1 | Engine modes: sequential, parallel, any-one, all, conditional; escalation and delegation honoured | Unit tests per mode; delegation window test | L |
| F1.2 | Migrate leave, expense, regularization, comp-off, salary revision, document requests to template-driven chains | Behaviour-identical by seeded templates; golden-path tests for each consumer | L |
| F1.3 | Tenant chain editor completion (`ApprovalChains.jsx`) incl. conditions and SLA | Edit a chain, next request routes differently | M |
| F1.4 | SLA reminders/escalation scheduler + notifications | Scheduler registration test (`ScheduleRegistrationTest`) | M |
| F1.5 (M-F4) | Notification digest scheduler, throttle, quiet hours, tenant channel policy UI, push-ready subscription model | Digest batches N events; tenant disables a channel | M |
**Entitlement:** existing `hrms.approvals.*`; add module `notifications.digest` only if sold separately.
**Migration/rollback:** templates seeded from today's hard-coded chains so behaviour is unchanged; feature flag per consumer to fall back.
**Exit:** every approval consumer reads templates; a tenant-edited chain changes routing with no code.

## F2 — Custom fields, search, shared primitives
**Objective.** Generic extensibility and one search/files/comments layer.
| Task | Deliverable | Notes / tests | Size |
|---|---|---|---|
| F2.1 (M-F2) | Custom-field definitions + typed values (JSON/JSONB), validation, entitlement `custom_fields` | Cross-entity API; type validation tests | L |
| F2.2 (M-A7) | Employees use custom fields: form, directory filter, CSV export, redaction rules | "Blood group" field appears everywhere | L |
| F2.3 | Extend to tasks, projects, assets, expenses, documents (one entity per commit) | Filter integration tested per entity | L |
| F2.4 (M-A8) | Employee tags | Tag filter | S |
| F2.5 (M-F5) | PostgreSQL FTS/trigram indexes; index documents and comments; permission-aware results; adapter seam for OpenSearch | Fuzzy match test; p95 under 300 ms on the 250k-task seed | L |
| F2.6 (M-F6) | Shared polymorphic comments + attachments used by HRMS entities | Expense and leave get comments | L |
| F2.7 (M-F7) | Unified audit read model across `activities`, HRMS audit, platform audit | One timeline with filters + CSV | M |
**Exit:** an admin adds a field without code; search is fuzzy and scoped.

## F3 — TMS query & time layer
**Objective.** Power-user search and time tracking.
| Task | Deliverable | Tests / acceptance | Size |
|---|---|---|---|
| F3.1 (M-E1) | Saved/shared filters (owner, project/workspace sharing, permission checks) | Shared filter hidden from non-members | M |
| F3.2 (M-E2) | JQL-lite: tokenizer, parser, field registry (incl. custom fields), SQL builder reusing `ScopesVisibleTasks`, autocomplete | Fuzz + injection tests; scope never widened | XL (parser → builder → UI) |
| F3.3 (M-E6) | Timer, billable flag, weekly timesheet, submit/approve via F1 | Timesheet approval flow; billable report | L |
| F3.4 (M-E9) | Link types relates/duplicates/clones; duplicate closes task | Small slice | S |
| F3.5 (M-E10) | Burnup and cumulative flow charts | Matches recorded scope events | S |
**Entitlement:** modules `saved_filters`, `jql`, `timesheets`.
**Exit:** `project = APP AND status = "In Progress" AND assignee = currentUser()` returns scoped rows.

## F4 — TMS planning layer
**Objective.** Roadmaps, capacity, dashboards, templates.
| Task | Deliverable | Tests | Size |
|---|---|---|---|
| F4.1 (M-E11) | Approval-gated transitions + visual workflow editor | Gated move waits for approval | L |
| F4.2 (M-E3) | Timeline/roadmap by version and epic with dependencies | Drag reschedule updates dates | L |
| F4.3 (M-E4) | Calendar view | Drag to reschedule | M |
| F4.4 (M-E5) | Capacity and workload (shift + leave aware) | Over-allocation warning in sprint planning | L |
| F4.5 (M-E7) | Team/project/workspace dashboards with saved widgets | Permission-aware data | L |
| F4.6 (M-E8) | Project/workspace templates + project health | New-from-template | M |
| F4.7 (M-E12) | Task SLA policies and breach events | Breach emits event | L |
| F4.8 (M-E14) | Scrum/Kanban board types, per-project issue-type scheme | Defaults per type | M |
**Exit:** a lead plans a sprint with capacity, sees the roadmap, and shares a dashboard.

## F5 — Automation & HR↔TMS integration
| Task | Deliverable | Tests | Size |
|---|---|---|---|
| F5.1 (M-F3) | HR triggers (`employee.joined`, `leave.approved`, `payroll.completed`) and cross-domain actions | Event → action tests | M |
| F5.2 | Starter rule packs (new hire, critical priority, blocked task, leave-adjust) | Pack installs idempotently | M |
| F5.3 (M-A11) | Auto-create onboarding task pack; buddy assignment | New hire gets tasks | M |
| F5.4 | Auto-membership policy (employee → project member by department/role) | Policy off by default | L |
| F5.5 | Leave/holiday awareness when assigning; workload read model | Warning on assign to person on leave | M |
| F5.6 | Goal ↔ epic/project links; timesheet → payroll component | Read-only evidence, never auto-rating | L |
| F5.7 | Summary tables for cross-domain reports (M-J5) | Refresh job test | M |
**Rule:** integrations are event-driven and one-way unless stated; no cross-tenant joins.

## F6 — HR lifecycle
**Objective.** One lifecycle engine for probation, confirmation, transfer, promotion and exit.
| Task | Deliverable | Tests | Size |
|---|---|---|---|
| F6.1 (M-A1) | Lifecycle engine: states, effective-dated transitions, dynamic approvers, scheduler | Future-dated change applies on date | XL (engine → templates → scheduler) |
| F6.2 (M-A6) | Letter templates with merge fields and branding → PDF | Generated letter stored as document | L |
| F6.3 (M-A2) | Transfer, promotion, confirmation requests | Effective date updates org and comp | L |
| F6.4 (M-A12) | Resignation self-service feeding offboarding | Approval opens a case | M |
| F6.5 (M-A3) | Full & final settlement | Posts payroll adjustment | L |
| F6.6 (M-A4) | Exit interview | Anonymised analytics | M |
| F6.7 (M-A5) | E-signature (internal hash flow) | SHA-256 + IP/time stored; verification page | L |
**Exit:** an employee goes hire → confirm → promote → resign → settle, all audited.

## F7 — HR operations & payroll depth
| Task | Deliverable | Size |
|---|---|---|
| F7.1 (M-B1) | Shift swap and exceptions | M |
| F7.2 (M-B2) | Hourly leave | M |
| F7.3 (M-B3) | Payroll reopen with approval and superseded payslips | M |
| F7.4 (M-B8) | PDF payslips | M |
| F7.5 (M-B4) | Bank/payout export templates | M |
| F7.6 (M-B5) | Loans and advances with EMI deductions | L |
| F7.7 (M-B6, M-B7) | Expense limits, mileage, advances, task link, multi-currency, OCR hook | L |
| F7.8 (M-B11) | Exemption/investment proof workflow | M |
| F7.9 (M-A9) | Business unit, legal entity, cost centre, dotted-line reporting | L |
| F7.10 (M-A10) | Graphical org chart | M |
Each task: module key, policy, audit rows, payroll/leave regression tests.

## F8 — Talent & performance depth
M-C1 skills and matrix (L) → M-C2 competencies (M) → M-C5 KPI/OKR alignment tree (L) → M-C4 IDP (M) → M-C3 careers,
succession, pools (L) → M-C6 PIP and 360 tooling (M). Module `hrms.talent` is extended; anonymity and "evidence only,
never auto-rating" rules stay (`HrmsTalentGuardrailTest`).

## F9 — Billing & tenant lifecycle
| Task | Deliverable | Size |
|---|---|---|
| F9.1 (M-H1) | Invoice entity on payment events, PDF, tenant list | L |
| F9.2 (M-H2) | Dunning cadence, grace days, scheduled suspension | L |
| F9.3 (M-H3) | Proration, seat true-up, overage policy (needs owner decision) | L |
| F9.4 (M-H4) | Usage metering for API calls and automation runs | M |
| F9.5 (M-H5) | `tenants:restore`, clone, hard purge, erasure workflow (needs legal decision) | L |
| F9.6 (M-H6) | Retention policy table, TMS retention, export cleanup | M |
| F9.7 (M-H7, M-H8) | Plan versioning/grandfathering; SA refund UI | M |
**Safety:** purge and erasure ship behind a feature flag with dry-run and audit; restore rehearsed on staging first.

## F10 — Integrations, API & identity
| Task | Deliverable | Size |
|---|---|---|
| F10.1 (M-I1) | OpenAPI spec + v1 breadth (comments, work logs, leave, attendance read) + contract tests | L |
| F10.2 (M-I3) | OIDC SSO with JIT provisioning and enforce-SSO | L |
| F10.3 (M-I5) | Slack/Teams notification adapters | M |
| F10.4 (M-G1) | Department/location/team scope resolvers | L |
| F10.5 (M-G2, M-G3, M-G4) | Deny/precedence doc, "why can't they" panel, generated permission matrix | M |
| F10.6 (M-G6) | Role template gallery | M |
| F10.7 (M-I2, M-I6, M-I7) | OAuth2 decision, GitHub/GitLab link, calendar sync | L each, on demand |
| F10.8 (M-I4) | SCIM (enterprise, after SSO) | L |

## F11 — Ops, observability & scale
| Task | Deliverable | Size |
|---|---|---|
| F11.1 (M-J1, M-J2) | SA health/queue/failed-job/backup pages; real queue probe | M |
| F11.2 (M-J3) | Metrics, traces, alert catalogue and runbook | M |
| F11.3 (M-J4) | Scheduled restore drill and report | S |
| F11.4 (M-M3) | Route × tenant × plan × role × scope × module matrix test (generated) | L |
| F11.5 (M-M1) | Playwright E2E for 8 core journeys | L |
| F11.6 (M-M2) | k6 load profiles on the 100-tenant seed | M |
| F11.7 (M-M4, M-M5) | Partitioning and replica thresholds; migration-on-populated-snapshot test | M |
| F11.8 (M-K1, M-K3, M-K5) | Upload sniffing/AV hook, token anomaly alerts, PII-in-logs sweep | M |
| F11.9 (M-B9) | Report builder with saved/scheduled reports | L |
| F11.10 (M-J6, M-J7, M-J8) | Tenant activity analytics, support-desk attachments/email/SLA, import invites | M |
F11.4 and F11.5 should start right after F0 and run alongside later phases.

## F12 — Experience & mobile
M-L1 navigation regroup driven by role/module/plan (M) → M-L2 PWA with offline punch queue, mobile leave/approvals/my
tasks/payslips (L) → M-L3 web push (M) → M-L4 UI debt (14 raw tables, `PageHeader` everywhere, `BulkBar`, TaskDetail
inline save) (L) → M-L5 split oversized HR pages (L) → M-L6 board live-refresh on column edits, keyboard dnd
walkthrough, 360/768/1280 QA (S).

## F13 — Deferred by design (decision phase)
No build until the owner decides: ATS-lite vs integrate (M-D1), LMS/training tracker, statutory filing integration,
SCIM timing, service-management module vs Support Desk (M-E13), small-tenant tier before 5,000 tenants. Output of this
phase is a written decision per item; a build phase is added only for items answered "build".

---

## Owner decisions needed per phase
| Before | Decision |
|---|---|
| F5.4 | Auto-membership default on or off |
| F6.7 | E-signature: internal hash only or certified provider |
| F9.3 | Overage policy and option-B price points |
| F9.4 | Which usage items are metered |
| F9.5, F9.6 | Retention periods and erasure rules (legal) |
| F10.2 | First SSO providers |
| F13 | ATS/LMS/filing/service-desk build-or-integrate |

## Suggested sequencing for a single team
`F0 → F1 ‖ F2 ‖ F9 → F3 → F4 ‖ F6 → F5 ‖ F7 → F8 → F10 ‖ F11 → F12`, with F11.4/F11.5 started right after F0.
First deliverable to hand to a coding agent: **F0 (tasks F0.1 to F0.8)**.
