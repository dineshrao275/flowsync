# FlowSync — Missing Functionality Register (delta against the master prompt)

**Purpose.** The master prompt (KEKA-class HRMS + JIRA-class TMS + SaaS platform) was already answered by
[`master-roadmap.md`](master-roadmap.md) (35 sections, phases 0-11). This file is the **delta**: every capability the
prompt asks for that is **not yet built, only partly built, or unproven**, with an ID, priority, dependencies,
acceptance criteria and a build order. Shipped work is deliberately not repeated — see `verification-report.md`,
`AGENTS.md` and `hrms-completion-track.md` for what exists.

**Status:** planning document only. No implementation has been started from it.
**Audited:** 2026-10-16 against the `development` branch. Evidence is file / route / test names; anything I could not
prove from the repo is flagged `❓ VERIFY`.

**Legend (from the prompt):** ✅ implemented · 🟡 partial · 🟠 needs hardening · 🔵 reserved/stub · 🔴 missing ·
⏸️ deferred by design · ❓ requires codebase verification.
**Priority:** P0 security/data-loss · P1 required for an enterprise product · P2 important capability · P3 enhancement ·
P4 future. **Size:** S ≤ 1 day · M 2-4 days · L 1-2 weeks · XL > 2 weeks (split into slices).
**IDs:** `M-` = this file. "Ref" links the older IDs (G-/H-/P-/R-/FB-) in `master-roadmap.md` and `gap-register.md`.

---

## Progress log (branch `feat/f0-baseline`, 2026-10-16/17)
Each row was implemented with its tests; the full-suite gate is still pending (see `f0-baseline-report.md`).

| Register ID | What shipped | Commit |
|---|---|---|
| M-B12 | `hrms:leave-consistency [--repair]`; also fixed `--tenant=ID` in every fleet command (`RunsPerTenant`) | 146d4697 |
| M-K4 | `audit:verify` scheduled daily; critical log + `SECURITY_AUDIT_ALERT_EMAILS` on a broken chain | 7d8198ac |
| M-G5, M-F8 | `impersonator_id` + `request_id` on activities and HRMS ledgers; queued jobs keep the request id | b51120fa |
| M-K2 | CSP violation collector `POST /api/csp-report` + default report-uri (flip to enforce after a clean report window) | d24cceeb |
| M-F4 | Notification delivery controls: tenant email policy, daily digest mode, dedupe window, hourly email throttle, Settings UI | 7dbf5692 |
| M-F2, M-A7 | Custom-field engine + employee integration (typed values, filters, sensitive fields, module `custom_fields`) | 0300dda8 |
| M-F2 (tasks, projects) | Custom fields on tasks and projects, list filter `cf[key]` | 05059d04 |
| M-E1 | Saved/shared task filters (module `saved_filters`) | 3f6053ba |
| M-E2 | JQL-style query language (`jql=` on the task list; module `jql`) — lexer, parser, whitelisted compiler | 045b17ee |
| M-E6 | Timer, billable time, weekly timesheets with submit/approve/send-back (module `timesheets`) | 95ab9469 |
| M-E10 | Sprint burnup chart | (F3.5 commit) |
| M-E4, M-E3 | Due-date calendar view; timeline (roadmap) view | 6ac51725, F4.2 commit |
| M-E5 | Workload vs capacity (weekday capacity − approved leave) | 66c2fb6e |
| M-I1, M-M3 (slice) | OpenAPI spec for `/api/v1` with a route-contract test; API route auth audit | 7d942d2f |
| M-B3 | Payroll reopen with mandatory reason (never from locked) | 89d3effe |
| M-A7 UI | Custom-field management page (`/custom-fields`) + inputs on employee and task forms + employee profile section | 8d7076e5 |
| M-F2 (assets, expenses) | Custom fields on assets and expense claims | (F2.3 assets/expenses commit) |
| M-E8 | Project templates (workflow, transitions, components, starter tasks; module `project_templates`) | f87e8c62 |
| M-J1 (queue) | Platform queue console: depth, oldest job, failed jobs retry/discard (`queue.manage`) | fa028554 |
| M-I5 | Webhook endpoints can post Slack/Mattermost or Teams messages | 1e596f59 |
| M-C1 | Skills catalogue, self-declared + assessed levels, scoped matrix, Skills page | 86004662, c0bae4ef |
| M-H4 | Monthly API-call and automation-run limits (plan limits, 429 `usage_limit`, Subscription page) | eb0c1eb9 |
| F4.5 | Project dashboards (widget catalogue, personal layouts over a team default) | 9ad7d760 |
| M-A12 (F6.4) | Resignation self-service → approval opens the exit case | c09c2620 |
| M-A4 (F6.6) | Exit interview (anonymity-guarded insights) | d71ba01d |
| F5 | HR events on the bus + HR→task automation rules (page) | 3ae5b923, cdf0092a |
| M-A2 (F6.3) | Transfers, promotions, confirmations — effective-dated, approved, applied on date | 1acd53ad |
| M-B1 (F7.1) | Shift swap: counterparty then manager approve, roster rewritten for the day | 589feaeb |
| M-E12 (F4.7) | Task SLA policies + hourly breach sweep (`task.sla_breached`) | c1a3ecae |
| M-E14 (F4.8) | Board types scrum/kanban/basic + per-project issue-type scheme | c5e9ae50 |
| M-F7 (F2.7) | Unified audit timeline (activities + HR ledger), filters, CSV, page | 96953a5c |
| M-L (F12) | Installable PWA (manifest + offline shell, never caches /api) | ab007395 |
| M-B6 (F7.7) | Expense category limits (per line, per month) | bbb2cb26 |
| M-B4 (F7.5) | Payroll bank payout file (generic + NEFT), audited | b9c4d839 |
| M-G6 (F10.6) | Role template gallery | b15f9d2b |
| M-C (F8) | Performance improvement plans | 2cbcf9ae |
| M-B5 (F7.6) | Loans and advances with EMI deduction through payroll | 9a828a61 |
| M-E11 (F4.1) | Approval-gated transitions (`approval` entry rule + move requests) | d360233a |
| M-M3 (F11.4) | Generated permission matrix test | 915af675 |
| M-K1 (F11.8) | Upload content sniffing + optional antivirus hook | d4a20c72 |
| M-J2 (F11.2) | Token-guarded Prometheus metrics + alert catalogue | 2ad47cd2 |
| M-F6 (F2.6) | Shared comment thread on HR records (expense, leave) | 3c520da5 |
| M-F5 (F2.5) | ILIKE + pg_trgm GIN indexes for substring search | fbd592ca |
| M-A6 (F6.2) | Letter templates with merge fields → PDF filed as a document | bc765308 |
| F8 | Individual development plans; OKRs; competency expectations + gaps; career paths + succession | 5d363553, c9821f71, 64ba1858, dcfe52b4 |
| M-E9 | Duplicates / clones link types | 31e2652c |
| F10.2 | OIDC single sign-on (PKCE, RS256 verification, JIT never admin, enforce with break-glass) | b2681ae5 |
| F9.5 | `tenants:purge` (gated, confirmed, audited; billing tombstone kept) | bcedbddf |
| F6.7 | Internal e-signature with SHA-256 receipt + public verification | 208e60e2 |
| F11.10 | Tenant activity analytics + dormant tenants page | f3edec13 |
| F11.9 | Report builder (tasks, expense claims) with saved + scheduled CSV email | 8648a3d8 |
| F10.7 | GitHub task links; personal iCalendar feed | 7fe32cd3, df2eb65d |
| F11.7 | Scaling thresholds runbook + migration repair-safety test | 9cbd0f9c |
| M-L6 | Boards refresh when columns change | 4c2a777c |
| infra | Test DB template per process (a 24-test run: 12 min → ~1.5 min) | 1d5d24d9 |

**Corrections found while building (rows marked missing that already existed):** invoices + PDF (`InvoiceService`, `InvoicePdf`, `InvoiceController`, `InvoiceTest`) → M-H1 ✅; dunning and period-end expiry (`DunningTest`, `PeriodEndTest`) → M-H2 ✅; PDF payslips (`PayslipRenderer` + `SimplePdf`) → M-B8 ✅; export ZIP cleanup (`PruneTenantExports`) → part of M-H6 ✅; approvals v2 modes/conditions/delegation/SLA/editor (`ApprovalStepMode`, `ChainBuilder`, `ApprovalChains.jsx`) → M-F1 only needs the remaining consumers.

**Deliberately deferred:** M-E9 (new link types relates/duplicates/clones) — `task_dependencies.type` is a database enum with a CHECK constraint; widening it safely on both PostgreSQL and the sqlite test path needs its own migration plan (see the CHECK-constraint pitfall in AGENTS.md). M-A8 employee tags — covered by a multi-select custom field.

---

## 0. Read this first — corrections and the one real blocker

### 0.1 Items the master roadmap still lists as open but the repo shows are (at least partly) done — verify, then close
| Item | Evidence | Action |
|---|---|---|
| P2.4 Approvals v2 (templates, delegation, SLA) | `ApprovalStepMode` = sequential / parallel_any / parallel_all; `approval_delegations` + templates in `2026_11_05_000060_create_approvals_v2`; `ChainBuilder`, `ApprovalSla`; `HrmsApprovalTest`; **verified F0.2: engine modes ✅, no "conditional" step type yet** | 🟡 close modes; keep M-F1 for conditional steps + consumer migration + editor polish |
| P2.9 Notification digest/throttle/locale | Only `hrms:report-digests` (HR *report* digest, scheduled 18:00) and migration `2026_11_03_000051_add_notification_delivery_controls`; **verified F0.2: no notification digest scheduler** | 🔴 stays open as M-F4 |
| P4.6 clone / move / bulk edit | Routes `tasks/bulk`, `tasks/{task}/clone`, `tasks/{task}/move-project` exist (`TaskBulkController`); **verified F0.2: no test references them** | 🟡 shipped without tests → add `TaskBulkTest` (F0.2 follow-up) |
| P3.1-3.5 workflow rules, transitions, automation core | `app/Services/Workflow`, `app/Services/Automation`, AGENTS.md "Workflow rules" / "Automation" | ✅ close (only P3.3 editor UI + P3.6 packs remain) |
| P4.2 / P4.5 sprints, backlog, agile reports | `app/Services/Sprints`, AGENTS.md "Sprints" | ✅ close |
| P6.6 packaging option B (TMS and HRMS sold separately) | AGENTS.md "Products" | ✅ close |
| P2.2 / P2.3 correlation id, notification consumers | `AssignRequestId`; request id already on platform + HRMS audit tables | ✅ close; F0.4 adds `activities` + queued jobs |
| P6.2 / M-H2 dunning, period-end expiry | `billing:dunning`, `billing:period-ends` scheduled in `routes/console.php`; `DunningTest`, `PeriodEndTest` | ✅ mostly shipped → M-H2 shrinks to configurable cadence + UI |

### 0.2 The blocker that sits under everything
**A full PHP test run has not completed in a long time.** AGENTS.md quotes 1720 tests / 8815 assertions (2026-10-09) and
says later tracks were verified only by focused runs. ParaTest now works (`php artisan test --parallel`; a 5-test run
completed in this session), so **M-0.1 below is the first task**. The roadmap itself makes "full regression green" the
entry gate for Phase 10 (validation) and Phase 11 (marketing).

### 0.3 Documentation conflicts to resolve (prompt rule 25)
1. `AGENTS.md` still says "Native host, no Docker" in Commands while the Docker stack is what runs in practice and the
   realtime work targeted it. → one canonical "how to run" section.
2. `AGENTS.md` says the sidebar `HrmsLayout.jsx` rail is deleted; older HRMS plan text still describes it. → mark the
   HRMS plan section superseded.
3. The master roadmap's Phase-1 progress line (2026-10-10) is stale versus §0.1. → rewrite the line after M-0.2.
4. `hrms.talent` was listed "sold but unshipped" in `hrms-completion-track.md`; Phase 24 shipped it (calibration only).
   → rename the module's remaining scope ("skills / succession / IDP") so it is not read as complete.

---

## 1. What is NOT missing (do not rebuild)
Keep / extend only: database-per-tenant isolation; `own → assigned → all` scope lattice (`TaskScope`, `HrmsScope`,
`PermissionScope`); module + product entitlement gates (`ensure_module`, `ensure_product`); domain-event outbox
(`DomainEvents`, `ProcessDomainEvent`); outbound webhooks; personal API tokens + `/api/v1` + idempotency; 2FA, platform
personas, consent-based support access, CSP, hash-chained audit, feature flags; Stripe subscriptions; tenant intake and
card capture; support desk; user CSV import; workflow rules + status history; automation engine; sprints and agile
reports; issue hierarchy; WIP limits and swimlanes; checklists; kanban column settings (this session); full HRMS core
through payroll, statutory calculation, performance, engagement, assets, documents; Spanish i18n; Reverb realtime.

---

## 2. Missing-functionality register

### A. HRMS — core and employee lifecycle
| ID | Pri | Feature | Current state | Gap | Depends | Acceptance | Size | Ref |
|---|---|---|---|---|---|---|---|---|
| M-A1 | P1 | **Employee Lifecycle Workflow Engine**: configurable states, effective-dated transitions, dynamic approvers, validation, notifications, audit, tenant templates; used by onboarding, confirmation, transfer, promotion, offboarding | Onboarding/offboarding are case engines; status changes are free-form with history rows | No shared lifecycle engine; transitions are not effective-dated or template-driven | Approvals v2 (M-F1) | A tenant can define "Probation → Confirmed" with approver rule + effective date; a future-dated change applies by schedule; each step audited | XL (3 slices: engine, templates UI, scheduler) | P5.4 |
| M-A2 | P2 | **Transfer / promotion / confirmation** with approval, effective date, letter | Only status + date columns | No entities, no letters | M-A1, M-A6 | Promotion request → approval → employee designation/department/comp updated on effective date; letter PDF stored as a document | L | G-16, P5.6 |
| M-A3 | P2 | **Full & final settlement** | Exit clearances counters exist | No F&F computation, no approval, no payroll posting | M-A1, leave encashment, payroll adjustments | F&F statement computed from pay days + leave encashment − recoveries; approved; posts a payroll adjustment | L | G-17, P5.7 |
| M-A4 | P2 | **Exit interview** | ❓ VERIFY (`exit_interview` string appears in 3 files, likely config only) | Form, scheduling, anonymised analytics | Survey engine | Template + response stored; exit analytics read it | M | G-17 |
| M-A5 | P2 | **E-signature** (internal signed-hash flow first) | None | Signer flow, tamper-evident hash, audit trail, reminder | Documents, M-A6 | Offer/relieving letter sent for signature; signed copy + SHA-256 + IP/time stored; verification page | L | P5.8 |
| M-A6 | P2 | **Letter generation** (offer, confirmation, promotion, relieving, experience) | Payslip templates only | Template engine with merge fields, per-tenant branding, PDF | Documents | Admin edits a template; "Generate" produces a PDF attached to the employee | L | P5.6/5.8 |
| M-A7 | P2 | **Custom fields on employees** (then tasks, projects, assets, expenses, documents) | None (`custom_field` has 0 hits) | Definitions, typed values, validation, filter + export integration, entitlement `custom_fields` | None | Admin adds a "Blood group" select field; it appears on the employee form, the directory filter and the CSV export | XL (employees first) | P2.6 |
| M-A8 | P3 | **Employee tags** | None | Free tags, filter | M-A7 or standalone | Tag filter in directory | S | P5.14 |
| M-A9 | P2 | **Org structure extras**: business unit, legal entity, cost centre, matrix (dotted-line) reporting | One mention of each term; no tables | Entities + employee links; dotted-line edge in `ReportsTo` | None | Employee has a cost centre; payroll and analytics can group by it; a dotted-line manager sees the employee under `_assigned` only if configured | L | P5.13 |
| M-A10 | P3 | **Graphical org chart** | List/tree only | Visual chart with expand/collapse, export | M-A9 | Chart renders 1k employees without freezing | M | out-of-scope list |
| M-A11 | P3 | Offer-to-joining workflow, buddy assignment, orientation tasks | Onboarding cases exist | Offer step, buddy field, auto task pack on `employee.joined` | M-F3 | New hire gets a buddy and an auto-created task pack | M | P3.6 |
| M-A12 | P3 | Notice-period and resignation self-service flow | ❓ VERIFY | Employee-initiated resignation with manager/HR approval feeding offboarding | M-A1 | Employee submits resignation; offboarding case opens on approval | M | — |

### B. HRMS — operations, payroll, compliance
| ID | Pri | Feature | Current state | Gap | Depends | Acceptance | Size | Ref |
|---|---|---|---|---|---|---|---|---|
| M-B1 | P2 | **Shift swap + exceptions** | Shifts, rosters, rotations ✅ | Swap request + approval, one-off exception | Approvals v2 | Employee A asks B to swap; both and manager approve; roster updates | M | P5.3 |
| M-B2 | P3 | **Hourly leave** | Day / half-day only | Hourly unit in policy + balance maths | None | Policy "hours" deducts 2 h from the balance | M | P5.9 |
| M-B3 | P2 | **Payroll reopen** | Lifecycle is forward-only | Reverse transitions with approval + audit | Approvals v2 | Published run can be reopened with reason; payslips marked superseded | M | G-32, P5.10 |
| M-B4 | P2 | **Bank file / payout export** | None (out of scope list) | Configurable bank-file formats | Payroll | CSV in a chosen bank template, audited export | M | P5.10 |
| M-B5 | P2 | **Loans and salary advances** | Payroll adjustments accept new kinds | Loan entity, EMI schedule, auto-deduction | Payroll | Loan creates monthly deductions until closed | L | G-31 |
| M-B6 | P2 | **Expense limits, mileage, advances, project/task link** | Claims + categories ✅ | Per-category/period limits, mileage rate, advance settlement, link to task | Approvals v2, TMS | Claim over limit is flagged or blocked per policy | L | G-31, P5.11 |
| M-B7 | P3 | **Multi-currency expenses**, OCR-ready receipt hook | ❓ VERIFY currency fields | FX rate source, original + base amounts; OCR provider interface | None | Foreign claim stores both amounts and the rate used | M | — |
| M-B8 | P2 | **PDF payslips** | Rendered template download | Real PDF generation | Payroll | Download returns a PDF | M | P6.5 |
| M-B9 | P2 | **Scheduled and custom reports** | 8 analytics readings, CSV, digests | Report builder, saved definitions, schedule + email | Reporting layer (M-G*) | Admin saves a report, schedules weekly email | L | P7 |
| M-B10 | P4 | **Statutory filing** (challan data, return preparation) | Calculation + declarations ✅ | Prepare-stage outputs (challan CSV, return data); filing stays external | Statutory | Challan data export per period; filing integration remains ⏸️ | L | master §5 |
| M-B11 | P3 | Tax exemptions / investment declarations proof workflow | `hrms.exemptions` delisted; declarations under statutory | Proof upload + verification | Documents | Employee uploads proof; HR verifies | M | A2 |
| M-B12 | P2 | `hrms:leave-consistency --repair` | Missing (R5) | Repair command | Leave | Command reports and fixes ledger/balance drift | S | E list |

### C. Talent and performance
| ID | Pri | Feature | Current state | Gap | Depends | Acceptance | Size | Ref |
|---|---|---|---|---|---|---|---|---|
| M-C1 | P2 | **Skills + skill matrix** | `skill` has 0 hits | Skill catalog, employee skills with level, matrix view | M-A7 | Manager sees a team × skill grid | L | P5.17 |
| M-C2 | P3 | Competency framework | Not present | Competencies per designation, used in reviews | M-C1 | Review form shows the role's competencies | M | P5.18 |
| M-C3 | P3 | Career paths, succession, talent pools, high-potentials | Calibration only (`Talent.jsx`) | Entities and views | M-C1 | A successor pool per critical role | L | P5.17 |
| M-C4 | P3 | Individual development plans | None | IDP linked to goals + skills | M-C1 | Employee has an IDP with milestones | M | P5.17 |
| M-C5 | P2 | KPI/OKR entities and alignment tree | Goals exist | Objective → key result hierarchy | Performance | Company → team → individual OKR roll-up | L | P5.18 |
| M-C6 | P3 | PIP and 360 tooling | Peer feedback exists | PIP lifecycle, 360 aggregation with anonymity | Performance | PIP with check-ins and outcome | M | P5.18 |
| M-C7 | P4 | Internal mobility / job board | None | Needs ATS-lite | M-D1 | — | L | — |

### D. HRMS capabilities deferred by design — decision table
| Feature | Why deferred | Build? | Priority | Dependencies |
|---|---|---|---|---|
| ATS / recruitment, job postings, candidates, interviews, applicant portal | Separate product surface; large | **Decision needed** — build "ATS-lite" (requisitions, candidates, interview scorecards, offer → onboarding hand-off) or integrate | P3 | M-A11, M-A5, public site |
| Training / LMS / certifications | Out of scope in plan Part 9 | Integrate first; build a light "training records + certificate expiry" tracker later | P4 | Documents, M-C1 |
| Statutory filing and bank/payroll vendor integrations | High regulatory risk | Keep deferred; ship prepare-stage outputs (M-B4, M-B10) | P3-P4 | Payroll |
| Advanced org chart | Cosmetic until BU/entity exist | After M-A9 | P3 | M-A9 |
| Biometric attendance devices | Hardware | Webhook/CSV ingest endpoint first | P3 | Attendance |
| SSO (OIDC then SAML), SCIM | P8.2 / P8.8 deferred | Enterprise tier; OIDC first | P2 (enterprise) | Identity layer |

### E. TMS — JIRA-class gaps
| ID | Pri | Feature | Current state | Gap | Depends | Acceptance | Size | Ref |
|---|---|---|---|---|---|---|---|---|
| M-E1 | P2 | **Saved / shared filters** | Filters live in React state and URL on Search | Server-side saved filters with owner, sharing, permissions | None | User saves "My open bugs", shares with a project; appears in the filter menu | M | G-5, P4.7 |
| M-E2 | P2 | **JQL-lite query language** | 0 hits | Tokenizer, parser, field registry, permission-aware SQL builder, autocomplete | M-E1 | `project = APP AND status = "In Progress" AND assignee = currentUser()` returns scoped rows; injection-safe; fuzz-tested | XL | G-4 |
| M-E3 | P2 | **Timeline / Gantt / roadmap** | 0 hits for Gantt; versions + start dates exist | Epic/version bars, dependencies, drag to reschedule | Hierarchy ✅ | Roadmap shows epics on a timeline by version | L | P4.4 |
| M-E4 | P2 | **Calendar view** | None | Due-date calendar | None | Tasks render by due date with drag-to-reschedule | M | P4.4 |
| M-E5 | P2 | **Capacity + workload** | 0 hits for capacity | Per-person capacity from shifts/leave; workload view; sprint capacity | Shifts, leave | Sprint planning shows over-allocation | L | P4.3, P7.3 |
| M-E6 | P2 | **Timer, billable flag, weekly timesheet + approval** | Work logs only | Start/stop timer, billable, timesheet submit/approve | Approvals v2 | Timesheet approved weekly; billable hours report | L | P4.12 |
| M-E7 | P2 | **Team / project / workspace dashboards + saved widgets** | Personal dashboard + analytics | Widget model, layouts, permission-aware data | Reporting | Lead builds a project dashboard and shares it | L | G-8, P4.11 |
| M-E8 | P3 | Project / workspace **templates**, project **health** | Clone shipped (verify) | Template library, health score | M-E1 | "New from template" creates statuses, roles, automations | M | P4.8 |
| M-E9 | P3 | Link types relates / duplicates / clones | blocks + related_to only | Extra types, UI, duplicate close action | None | Mark duplicate closes the task and links it | S | P4.9 |
| M-E10 | P3 | **Burnup**, cumulative flow, SLA reports | Burndown/velocity/lead/cycle ✅; `burnup` 0 hits | Add charts | Sprints | Burnup shown beside burndown | S | G-46 |
| M-E11 | P2 | **Approval-gated transitions + workflow editor UI** | Rules + transitions API; UI in Workflow tab (verify depth) | Approver step on a transition, visual editor | Approvals v2 | Moving to "Released" waits for approval | L | P3.3 |
| M-E12 | P3 | **SLA on workflows / requests** (timers, breach, escalation) | Approval SLA only | Task-level SLA policy + breach events | Automation | Breach triggers a notification rule | L | — |
| M-E13 | P3 | **Service-management module** (request types, forms, queues, SLAs) | `service_desk`/`request_type` 0 hits | Decision: separate module or leave to Support Desk | M-A7, M-E12 | Written decision; if built, behind its own module key | XL | prompt §6 |
| M-E14 | P3 | Scrum vs Kanban **board types**, per-project issue-type scheme | Single board; issue types global | Board configuration, schemes | Workflow | Project picks Scrum or Kanban defaults | M | — |
| M-E15 | P4 | Portfolio / cross-project planning | 0 hits | Programme view over projects | M-E3 | Portfolio rolls up epics across projects | L | — |
| M-E16 | P3 | Watch/follow/subscribe polish, bulk transition | Watchers shipped; bulk edit WIP | ❓ VERIFY bulk transition | — | — | S | G-2a |

### F. Shared platform engines
| ID | Pri | Feature | Current state | Gap | Depends | Acceptance | Size | Ref |
|---|---|---|---|---|---|---|---|---|
| M-F1 | P1 | **Finish approvals v2 and migrate all consumers** to template-driven chains (leave, expense, regularization, comp-off, salary revision, document, payroll reopen, timesheet, transition gate) | Engine + templates partly merged | Modes (parallel / any-one / all / conditional), delegation UI, escalation, migration of each consumer | — | One tenant-edited chain changes leave routing without code; all consumers read templates | L | P2.4-2.5 |
| M-F2 | P1 | **Custom-field engine** (generic) | None | See M-A7; extend to tasks/projects/assets/expenses; typed JSONB; filter + JQL integration | — | See M-A7 | XL | P2.6 |
| M-F3 | P2 | **Automation starter packs and HR triggers** (`employee.joined`, `leave.approved`, `payroll.completed`) | Engine + 8 TMS triggers | HR event triggers, cross-domain actions (create onboarding tasks, adjust task on leave), rule packs | Event bus ✅ | Rule pack "New hire" installs and creates tasks on join | M | P3.6 |
| M-F4 | P2 | **Notification delivery controls** (digest, throttling, quiet hours, tenant channel policy, push-ready, webhook channel) | Dispatcher + preferences exist; digest WIP | Finish digest scheduler, tenant policy UI, push subscription model | Dispatcher | Daily digest email batches N events; tenant disables a channel | M | P2.9 |
| M-F5 | P2 | **Unified search** (permission-aware, fuzzy, all entities, saved searches) | Global search fan-out; LIKE queries; `tsvector` 0 hits | PostgreSQL FTS/trigram indexes, documents + comments indexing, OpenSearch adapter seam | M-E1 | Fuzzy "recieve" finds "receive"; results scope-filtered; p95 < 300 ms on 250k tasks | L | P9.7 |
| M-F6 | P2 | **Unified attachments / comments / activity** as shared primitives | Task-specific implementations; HRMS has its own audit | Extract polymorphic comments + files for HRMS entities | — | Expense and leave get comments through the shared component | L | master §32 |
| M-F7 | P3 | Unified audit read model across TMS `activities`, HRMS audit, platform audit | Three stores | One query layer for "everything this user did" | Audit | Tenant admin sees a single timeline with filters and export | M | P2.2 |
| M-F8 | P3 | `request_id` stored in audit rows and queued jobs | Echoed in logs only | Persist + propagate | AssignRequestId | Audit row carries the request id; job payload carries it | S | E list |

### G. Roles, permissions, scopes
| ID | Pri | Feature | Current state | Gap | Depends | Acceptance | Size | Ref |
|---|---|---|---|---|---|---|---|---|
| M-G1 | P2 | **Additional scopes: department, location, team, project** as resolvers behind `HrmsScope` / `TaskScope` | own / assigned / all only | Catalog suffix + resolver + policy test row per scope; never widen existing grants | Scope lattice | HR executive limited to one location sees only that location's leave | L | master §8 |
| M-G2 | P2 | **Deny rules + precedence document** | Allow-only | Explicit decision (add deny or document "no deny") | — | Written precedence rules with tests | S | prompt §9 |
| M-G3 | P2 | Roles-page **"why can't they?" panel** | API `GET users/{user}/access` ✅ | SPA panel | Roles page | Admin picks a user + permission and sees the reasoning | S | R14 |
| M-G4 | P2 | **Generated permission matrix (R15)** and route×role test | `FrontendPermissionLiteralsTest` guard only | Generated matrix doc + matrix test | — | CI fails when a route lacks a permission mapping | M | R15 |
| M-G5 | P1 | **Impersonation attribution** on `ActivityLogger` and `HrmsAuditLogger` rows | Only tenant-admin audit rows carry `impersonator_id` | Add to both writers | — | Task edited while impersonating shows both actors | S | R7 |
| M-G6 | P3 | Role templates beyond the four defaults, role inheritance | Clone only | Template gallery | Roles | — | M | R6 |
| M-G7 | P3 | Project-level role editing UI polish and per-workspace roles | Project roles ✅ | ❓ VERIFY workspace roles beyond owner/admin/member | — | — | S | — |

### H. Tenant, subscription, billing
| ID | Pri | Feature | Current state | Gap | Depends | Acceptance | Size | Ref |
|---|---|---|---|---|---|---|---|---|
| M-H1 | P1 | **Invoices + PDF + invoice list** | Payment rows; Stripe portal | Invoice entity generated on payment events; PDF; tenant list | Billing | Tenant downloads an invoice per payment | L | P6.1, FB-6 |
| M-H2 | P1 | **Dunning sequence + grace period + scheduled suspension** | Partly shipped (past_due on failed payment; ❓ VERIFY retry schedule) | Retry cadence, emails, grace days, auto-suspend | Billing | Failed payment sends 3 reminders then suspends on day N | L | P6.2 |
| M-H3 | P2 | **Proration, seat changes, overage policy** | In-place plan change at Stripe | Seat true-up, overage rules (owner decision) | Billing | Adding seats mid-cycle produces a prorated charge | L | P6.4 |
| M-H4 | P2 | **Usage metering** (API calls, automation runs, storage) | Quotas for users/workspaces/projects/tasks/storage | Meter + limit for API/automation | API tokens ✅ | Plan limit of N automation runs/month enforced with a clear 422 | M | master §10 |
| M-H5 | P2 | **Tenant restore, clone, hard purge, GDPR erasure** | Backup command + restore drill; soft delete | Real restore command, clone, purge job, erasure workflow (legal first) | Retention decision | `tenants:restore` rebuilds a tenant from a snapshot; purge is audited | L | P9.3 |
| M-H6 | P2 | **Retention policy table + TMS retention + export ZIP cleanup** | HRMS retention only | Per-entity policy and purge jobs | Legal decision | Old exports deleted after N days; TMS data purge honours policy | M | P9.6, P1.5 |
| M-H7 | P3 | Grandfathered plans, plan versioning | ❓ VERIFY | Immutable plan versions | Billing | Existing tenants keep price after plan edit | M | — |
| M-H8 | P3 | Refunds UI for SA | Refund path in `BillingTest` | SA refund screen | Billing | — | S | — |

### I. API and integrations
| ID | Pri | Feature | Current state | Gap | Acceptance | Size | Ref |
|---|---|---|---|---|---|---|---|
| M-I1 | P2 | **v1 API breadth** | 5 token endpoints (me, projects, tasks, search, employees) | Comments, work logs, leave, attendance read; webhooks management; OpenAPI spec | Published OpenAPI + contract tests | L | P2.7 |
| M-I2 | P3 | OAuth2 / OIDC provider for third parties | Personal tokens only | Decision + implementation if marketplace is planned | Written decision | M | — |
| M-I3 | P2 | **OIDC SSO** then SAML | Deferred | Login provider config per tenant, JIT provisioning, enforce-SSO | Tenant logs in via Google/Microsoft | L | P8.2 |
| M-I4 | P4 | SCIM provisioning | Deferred | Users + groups endpoints | Okta creates and disables users | L | P8.8 |
| M-I5 | P3 | Slack / Teams notifications | `slack` appears only in config | Outbound channel adapters | Task events post to a Slack channel | M | prompt §20 |
| M-I6 | P3 | GitHub / GitLab link (branch / PR ↔ task) | None | App install, webhook ingest, smart commits | PR merged moves task | L | — |
| M-I7 | P3 | Google / Microsoft calendar sync (leave, due dates) | None | Sync adapter | Approved leave appears on the calendar | L | — |
| M-I8 | P4 | Zoom, accounting, payroll vendors, biometric | None | Adapters on demand | — | — | — |

Classification for the prompt's integration list: **MVP** — Slack/Teams notifications, webhooks (shipped), OpenAPI; **Phase 2** —
OIDC SSO, Google/Microsoft calendar; **Enterprise** — SAML, SCIM, biometric ingest; **Future** — GitHub/GitLab, Zoom,
accounting, payroll vendors.

### J. Super Admin, ops, observability
| ID | Pri | Feature | Current state | Gap | Acceptance | Size | Ref |
|---|---|---|---|---|---|---|---|
| M-J1 | P1 | **SA health / queues / failed jobs / backups pages** | `/api/platform/health` exists, no dedicated UI | Pages + actions (retry failed job, run backup) | SA sees queue depth and retries a failed job | M | P9.1 |
| M-J2 | P2 | Real queue probe | Health ping only | Probe that round-trips a job | Alert when the probe is late | S | P9.2 |
| M-J3 | P2 | **Metrics, traces, alert catalogue** | Logs with request id | Prometheus-style metrics, error tracker, defined alerts (billing failure, provisioning failure, queue lag, DB latency) | Runbook lists every alert and owner | M | prompt §28 |
| M-J4 | P2 | **Restore drill on a schedule + DR doc** | Manual drill command | Scheduled drill + report | Monthly drill result stored | S | runbook |
| M-J5 | P3 | Summary-table refresh jobs | Not built | Pre-aggregated tables for cross-domain reports | Reports read summaries, not live joins | M | P9.4 |
| M-J6 | P3 | Tenant activity analytics for SA | Resource totals only | Active users, feature usage per tenant | SA sees last-active per tenant | M | FB-5 |
| M-J7 | P2 | Support desk: attachments, email notification, SLA timers | Core shipped | Listed "not done" in FB-7 | — | M | FB-7 |
| M-J8 | P3 | Email invites for imported users | Not done | Invite / reset-link mail | Imported user receives an invite | S | FB-8 |

### K. Security work
Threat-model rows from prompt §25. "Covered" means a guard test exists per AGENTS.md; ❓ items need a test written.
| Threat | Status | Next step |
|---|---|---|
| Cross-tenant access / IDOR | ✅ per-tenant DB + route×tenant tests | M-M3 full matrix |
| Privilege escalation / role manipulation | ✅ `GrantCeiling`, system roles read-only | keep |
| Subscription bypass | ✅ `AdminEntitlementTest`, `ModuleGateTest` | extend matrix |
| Signed URL abuse | ✅ signed + tenant in signature + flushSession tests | add expiry-tamper test for new downloads |
| File upload attacks | 🟠 type + size checks | add content sniffing and optional AV scan hook (M-K1) |
| XSS | ✅ DOMPurify, CSP report-only | **flip CSP to enforce** after a clean report period (M-K2) |
| CSRF / session hijack | ✅ session + XSRF; 2FA | review cookie flags per environment |
| SQL injection | ✅ bound params; `Like` helper | add lint for raw interpolation (roadmap §17) |
| SSRF / webhook attacks | ✅ `WebhookUrlGuard`, HMAC, replay window | keep; add DNS-rebinding re-check at send (done) |
| Replay (payments, webhooks) | ✅ Stripe replay window + event dedupe | — |
| API token theft | ✅ hashed, abilities, expiry, rate limit | add anomaly alert (M-K3) |
| Audit manipulation | ✅ hash chain | schedule `audit:verify` + alert (M-K4) |
| Sensitive data leakage | 🟠 masking in HRMS | audit logs and exports for PII (M-K5) |

| ID | Pri | Task | Size |
|---|---|---|---|
| M-K1 | P2 | Upload content sniffing + antivirus hook | M |
| M-K2 | P1 | CSP report-only → enforce, with a violation review | S |
| M-K3 | P3 | Token anomaly alerts (new IP, burst) | S |
| M-K4 | P2 | Scheduled audit-chain verification with alert | S |
| M-K5 | P2 | PII-in-logs sweep and export review | M |

### L. UX, navigation, mobile
| ID | Pri | Feature | Current state | Gap | Acceptance | Size |
|---|---|---|---|---|---|---|
| M-L1 | P2 | **Navigation IA regroup** (Work / HR / Projects / Reports / Admin / Settings) driven by role, module and plan | Two product shells; HR nav grouped | Top-level regroup, My-work hub | Max 7 top-level items per persona | M |
| M-L2 | P2 | **PWA + mobile-first flows**: punch, leave, approvals, my tasks, payslips | Responsive SPA only | Manifest, service worker, offline queue for punches, install prompt | Employee punches in from a phone, offline then syncs | L (P9.5) |
| M-L3 | P3 | Push notifications | None | Web Push + subscription table | Browser push for an assignment | M |
| M-L4 | P3 | UI debt: 14 raw `<table>`s, `PageHeader` on every page, Dashboard/Reports on `DataTable`, card multi-select + `BulkBar`, TaskDetail inline save | Warned by lint | Migrate page by page | Lint warnings at 0 | L |
| M-L5 | P3 | Splitting `Leave`, `Attendance`, `Analytics`, `Holidays`, `Statutory`, `Compensation` pages into sections | Large pages | Tabs / sub-routes | Each page < 400 lines | L |
| M-L6 | P3 | Live refresh when shared board columns change; keyboard walkthrough of dnd board; 360/768/1280 visual QA | Not done | Event + QA pass | Checklist signed off | S |

### M. Scale and testing
| ID | Pri | Task | Size |
|---|---|---|---|
| M-M0 | P0 | **Complete a full ParaTest run, fix failures, re-baseline the counts in AGENTS.md** | M |
| M-M1 | P2 | Playwright E2E for the 8 core journeys (login, create project, move task, apply leave, approve, run payroll, plan upgrade, impersonate) | L |
| M-M2 | P2 | k6 load profile (board load, search, punch burst) against the 100-tenant seed | M |
| M-M3 | P1 | **Route × tenant × plan × role × scope × module matrix test**, generated from the route list | L |
| M-M4 | P3 | Partitioning plan for `attendance_punches`, `work_logs`, `activities`, `domain_events`; read-replica and PgBouncer thresholds written down | M |
| M-M5 | P3 | Migration test: upgrade every migration set on a populated PG snapshot | M |

---

## 3. Cross-cutting scale answers (so the roadmap stays honest)
| Tenants | Verdict for database-per-tenant |
|---|---|
| 10-100 | Fine (100 seeded on the dev host). |
| 1,000 | Needs PgBouncer / connection reuse, parallel migration runner with progress, tenant-DB placement across clusters. |
| 10,000 | Needs sharded clusters (tenant → cluster map in the system DB), queue partitioning, zero-downtime migration orchestration. |
| 100,000 | Do not stay with one-DB-per-tenant for small tenants; introduce a pooled "small tenant" tier (schema-per-tenant or shared DB with row guards) and keep dedicated DBs for large ones. Decide before 5,000 tenants. |
Cross-tenant analytics must read **summary tables** (M-J5) or a warehouse, never joins across tenant DBs.

---

## 4. Build order (dependency-ordered; each task independently shippable)
Rules for every task: module key + route gate in the same commit; audit for sensitive writes; tenant context in jobs;
migrations repair-safe with `down()`; focused ParaTest file; Spanish strings via the parity guards; one commit per task.

**Phase M0 — Truth and safety (do first)**
M-M0 full test run · §0.1 verification closures · M-G5 impersonation attribution · M-K2 CSP enforce · M-K4 audit verify alert ·
M-F8 request id in audit/jobs · M-B12 leave consistency repair · doc conflicts §0.3.

**Phase M1 — Shared engines (unblocks most of the rest)**
M-F1 approvals v2 finish + consumer migration → M-F2/M-A7 custom fields (employees first) → M-F3 automation packs → M-F4
notification controls → M-F5 search → M-F6 shared comments/files.

**Phase M2 — TMS to JIRA-class**
M-E1 saved filters → M-E2 JQL-lite → M-E6 timer/timesheet → M-E11 gated transitions + editor → M-E7 dashboards → M-E3/E4
timeline + calendar → M-E5 capacity → M-E8 templates/health → M-E9/E10 small items → M-E12 SLA → decide M-E13.

**Phase M3 — HRMS completion**
M-A1 lifecycle engine → M-A2 transfer/promotion/confirmation + M-A6 letters → M-A3 F&F + M-A4 exit interview → M-A5
e-signature → M-B1/B2/B3/B4/B5/B6/B8 operations → M-A9 org extras → M-C* talent depth → M-B9 report builder.

**Phase M4 — Platform business**
M-H1 invoices → M-H2 dunning → M-H3 proration/seats → M-H4 metering → M-H5/H6 restore, purge, retention (after legal
decisions) → M-J1/J2/J3 ops pages and observability.

**Phase M5 — Integration and enterprise**
M-I1 OpenAPI + v1 breadth → M-I3 OIDC SSO → M-I5 Slack/Teams → M-G1 extra scopes → M-I4 SCIM → M-I6/I7 developer and
calendar integrations.

**Phase M6 — Experience and scale**
M-L1 navigation → M-L2 PWA → M-L3 push → M-L4/L5 UI debt → M-M1 E2E → M-M2 load → M-M3 matrix → M-M4 scale plan.

(M-M3 and M-M1 should start earlier in parallel once M0 is done; they protect every later phase.)

---

## 5. Top 10 immediate priorities (prompt §46)
| # | Item | Why now | Depends | Risk if postponed | Size | Phase |
|---|---|---|---|---|---|---|
| 1 | **M-M0 full test run + re-baseline** | No trustworthy regression signal | none | Silent breakage across 15 merged tracks | M | M0 |
| 2 | **M-G5 + M-F8 attribution and request id** | Audit gaps weaken the support-access and compliance story | none | Unattributed actions during impersonation | S | M0 |
| 3 | **M-K2 CSP enforce** | Report-only protects nothing | clean report | XSS blast radius | S | M0 |
| 4 | **M-F1 approvals v2 + consumer migration** | Unblocks timesheets, gated transitions, F&F, lifecycle, payroll reopen | merged WIP | Every later workflow builds its own chain | L | M1 |
| 5 | **M-E1 → M-E2 saved filters then JQL-lite** | Highest-visibility JIRA gap; cheap first half | none | Power users bounce | M + XL | M2 |
| 6 | **M-F2 custom fields** | Required by HR and TMS buyers; many features hang off it | none | Per-tenant customisation requests become code changes | XL | M1 |
| 7 | **M-E6 timer + timesheet approval** | Feeds billing, payroll hooks, utilisation | M-F1 | No billable-time story | L | M2 |
| 8 | **M-H1 + M-H2 invoices and dunning** | Revenue operations | none | Manual billing, churn on failed cards | L + L | M4 |
| 9 | **M-A1 lifecycle engine** | Basis for confirmation, transfer, promotion, exit | M-F1 | Four one-off workflows later | XL | M3 |
| 10 | **M-M3 route×tenant×plan×role matrix test** | Enforces rules 4-8 of the prompt automatically | none | Authorization regressions | L | M0-M6 |

### The single next phase to hand to a coding agent
**Phase M0 — Truth and safety.** Tasks, in order: (1) run `php artisan test --parallel` to completion, fix or list every
failure, update the totals in `AGENTS.md`; (2) verify and close the §0.1 items; (3) M-G5; (4) M-F8; (5) M-K2 (flip CSP only
after reviewing the report log); (6) M-K4; (7) M-B12. Definition of done: full suite green, `npm test` + `npm run lint` + build
green, roadmap progress lines rewritten, every closed item cites its test.

---

## 6. Decisions only the product owner can make
1. **Data retention periods** per entity (blocks M-H5, M-H6).
2. **Option-B pricing numbers** and overage policy (M-H3).
3. **Metering**: are automation runs and API calls metered or only module-gated (M-H4)?
4. **Geofence default**: flag or block (shipped as `flag`; confirm).
5. **E-signature legal weight**: internal signed-hash only, or integrate a certified provider (M-A5).
6. **Statutory filing scope**: prepare-only outputs, or a filing integration (M-B10).
7. **ATS and LMS**: build light versions, or integrate third parties (§D).
8. **Service management**: separate module, or fold into the Support Desk (M-E13).
9. **Small-tenant tier** at the 5,000-tenant mark (§3).
10. **OIDC first** for SSO, and which providers (M-I3).

---

## 7. How to keep this file true
- When an item ships, change its row to ✅ and cite the test and commit; do not delete rows.
- New work added to `master-roadmap.md` must get an `M-` row here or a link, so the two cannot drift again.
- Any row marked ❓ is not allowed to enter a sprint until someone has verified it in the code.
