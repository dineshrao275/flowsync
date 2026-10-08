# FlowSync — HRMS + TMS Implementation Verification Report

**Source of requirements:** `compare-thisrepo-with-me.md` (the product plan).
**Verified against:** codebase at commit `d5bc70c` (2026-10-08) + all planning documents
(`docs/hrms-implementation-plan.md`, `docs/multi-tenancy-architecture.md`,
`docs/custom-roles-migration-guide.md`, `.agents/roadmap/{phase-plan,member-access-plan,final-report}.md`,
`.agents/00..12-*.md`, `AGENTS.md`, `IMPLEMENTATION_TRACKER.md`).

**Method:** static code inspection (routes, controllers, services, models, migrations, SPA pages, config)
cross-referenced with the planning docs, plus focused test runs. **No code was changed.**

**Focused tests run for this audit (all passing):** `TmsExpansionTest` (6), `ModuleGateTest` (9),
`SubscriptionStateTest` (3), `TenantUsageCollectionTest` (1) — 28 tests / 134 assertions.

**Not run:** the full suite (~14 min). The documented gate *1535 tests / 7882 assertions* is marked
❓ — static count of test methods finds **1469** across 173 files; the delta must come from a live run.

---

## Classification legend (from the product plan §2)

| Symbol | Meaning |
|---|---|
| ✅ | Implemented / verified |
| 🟡 | Partially implemented |
| 🟠 | Implemented but needs hardening / modification |
| 🔵 | Reserved / stub (module key or schema exists, no surface) |
| 🔴 | Missing |
| ⏸️ | Deferred by design (per source documents) |
| ❓ | Requires runtime verification (not provable statically) |

**"Evidence"** cites real file paths. Anything not cited is treated as not present.

---

## 0. Coverage map — required workflow areas at a glance

| Required area | Verdict | Headline |
|---|---|---|
| Tenant (multi-tenancy) | ✅ | DB-per-tenant, provisioning/backup/export/suspend — scheduler + restore automation thin (§1) |
| Roles & permissions | ✅ | RBAC + `own/assigned/all` scoped lattice, project roles, custom roles, impersonation (§2) |
| Subscriptions | 🟠 | Lifecycle + module gates + quotas + real gateways work; invoices/dunning/trial-expiry missing (§3) |
| Employees | ✅ | Directory, hire modes, redaction, status lifecycle, backfill (§5) |
| HR processes | 🟠 | HRMS plan P1–P21 shipped; shifts/talent reserved; F&F, transfers/promotions, e-sign missing (§6–8) |
| Tasks | 🟠 | Core task lifecycle solid; JIRA-class layer (sprints/epics/workflow/automation/views) missing (§4, §10) |
| Projects | ✅ | Workspaces/projects/statuses/roles/members/components/versions (§4) |
| Workflows & approvals | 🟠 | Shared approval engine (HRMS) ✅; TMS transition workflow engine 🔴 (§10) |
| Notifications | 🟠 | In-app + email + prefs + deep links ✅; digest/push/outbound webhooks/watchers 🔴 (§11) |
| Reports & analytics | 🟠 | Distributions + HR analytics + CSV ✅; agile metrics (velocity/burndown/cycle time) 🔴 (§12) |

---

## 1. Platform & tenancy

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| One DB per tenant + central system DB | ✅ | `app/Support/TenantDatabaseManager.php`, `config/tenancy.php`, `SwitchTenant` middleware + priority list in `bootstrap/app.php` | None — architectural anchor; do not replace |
| Tenant routing (`tenant_users`) | ✅ | migration `000015`, `AuthController::loginIsolated` | Lower-case normalization handled; keep |
| Provisioning pipeline | ✅ | `TenantProvisioner::provisionIsolated`, `ProvisionTenantJob`, `tenants:provision` | — |
| Lifecycle state machine | ✅ | `app/Services/TenantLifecycle.php` (pending→…→suspended/expired/…) + audit rows | `expired`/`ended` states have **no code path that sets them** (no scheduled trial/period expiry) |
| Suspend / activate / soft-delete / restore | ✅ | `TenantController` + `TenantLifecycle`, route `->withTrashed()` | Hard purge/erasure path missing (§16) |
| Backup + verify + restore drill | ✅ | `tenants:backup` (`--all --verify --restore-drill`), scheduled `dailyAt 02:00` in `routes/console.php` | Real restore is manual `psql` (runbook §7) — no `restore` command |
| Tenant export (ZIP) | ✅ | `ExportService`, `BuildTenantExportJob`, `export_runs`, `ensure_module:export.full` | Generated ZIPs never cleaned up |
| Tenant cloning | 🔴 | no command/route | Gap register G-19 |
| Scheduler coverage | 🟠 | `routes/console.php` schedules **only** `tenants:collect-usage` + `tenants:backup` | **All 12 `hrms:*` commands are unscheduled** — reminders/rollups/accrual/digests never fire (G-1) |
| Cross-tenant analytics (capped fan-out) | ✅ | `SystemAnalyticsController`, `PlatformResourceTotals` | OK for now; warehouse deferred |
| Runbook | ✅ | `docs/runbook.md` (297 lines) | Must document manual cron for `hrms:*` |

## 2. Identity, roles & permissions

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| Tenant roles from config selectors | ✅ | `config/permissions.php`, `PermissionSelector`, `TenantProvisioner::seed` | — |
| Scoped permission lattice (`own/assigned/all` + legacy alias) | ✅ | `app/Support/PermissionScope.php`, `User::granted()`, `tenants:scope-grants` backfill | — |
| Row-scoping enforcement (TMS) | ✅ | `app/Support/TaskScope.php` + `TaskPolicy` + board query; `TaskScopeAccessTest` | Global reads (search/dashboard/reports) scope by project membership only — documented follow-up |
| Row-scoping enforcement (HRMS) | ✅ | `app/Services/Hrms/HrmsScope.php` for leave/expense/document/comp-off/performance/attendance | — |
| Custom roles (tenant CRUD) | ✅ | `RoleController`, `Roles.jsx`, `PermissionGrid` | — |
| Project roles (35 slugs, scope-aware) | ✅ | `config/project_roles.php`, `ProjectRole::grants()` | — |
| Platform (super-admin) RBAC | 🟡 | `platform_roles`/`platform_permissions` tables exist (migration `000014`); `is_super_admin` is the live gate | Platform RBAC is **schema-only** — no Billing Admin/Support Auditor/Auditor personas; single flag |
| Impersonation + guards | ✅ | `ImpersonationController`, `impersonation_logs`, A5/A6 guards | — |
| Session security | ✅ | DB sessions, `http_only`, `same_site=lax`, 120-min idle | — |
| Rate limiting | ✅ | throttle on auth/forgot/register/billing/webhooks/export | — |
| 2FA / TOTP | 🔴 | no code | Gap G-20 (enterprise expectation) |
| SSO / SAML / OAuth | 🔴 | no code | Gap G-21; plan module `api`/SSO packaging untied |
| Login/logout audit | 🔴 | `AuthController` writes no audit row; no auth-event listeners | Gap G-6 — plan §17 requires it |
| Permission precedence / deny rules | ⏸️ | docs describe grant-only model | Deny rules deliberately absent; document decision |

## 3. Subscription, entitlement & billing

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| Plan catalog + CRUD | ✅ | `subscription_plans`, `PlanController`, `Plans.jsx`, `SubscriptionPlanSeeder` | — |
| Subscription lifecycle states | ✅ | `SubscriptionService` assign/startTrial/cancel/renew/suspend; `SubscriptionStateTest` | `expired`/`ended` never transitioned (needs scheduled expiry) |
| Module gating (fail-closed) | ✅ | `EnsureModule` middleware + `X-Module-Reason`; `ModuleGateTest` (9 tests re-run for this audit) | — |
| Feature/limit overrides | ✅ | `tenants.limits_override` + `tenants.features_override.modules` (additive) | — |
| Quotas (hard-block) | ✅ | `TenantLimits::assertQuota` on users/workspaces/projects/tasks/employees/attachment storage | No grace period / overage — plan's stated default is hard-block; confirm commercial intent |
| Usage metrics | ✅ | `usage_metrics` migration `000022`, `tenants:collect-usage` daily, `GET my-usage` | — |
| Payment gateways (Stripe/Razorpay) | ✅ | `app/Billing/Gateways/*` (raw HTTP + fake driver), `PaymentService`, checkout idempotency, refunds, signature + replay guard; `BillingTest`, `PaymentWebhookTest` | — |
| Failed payment → past_due | ✅ | `PaymentService` webhook → `SubscriptionService::suspend` | — |
| Tenant self-service (view/switch/cancel/renew) | ✅ | `MySubscriptionController`, `Subscription.jsx` | — |
| Invoices / invoice generation | 🔴 | no invoice entity anywhere | Gap G-11 — billing story incomplete without invoices |
| Dunning / grace period | 🔴 | only webhook-driven past_due | Gap G-11 |
| Trial & period auto-expiry | 🔴 | no scheduled job sets `expired` | Gap G-2 (combined with scheduler fix) |
| Seat changes (`EVENT_SEATS_CHANGED`) | 🟠 | constant defined in `Subscription::EVENT_SEATS_CHANGED`, **never emitted** | Dead API — either wire seat count into plan or remove constant |
| Proration | 🔴 | none | Future |
| `api` / `audit_export` plan modules | 🟠 | listed in `config/subscriptions.php`, toggleable in Feature UI, **no route gated on them** | Selling "API access" entitles nothing today — gate or delist (G-9) |
| Storage quota | ✅ | attachment upload enforces storage bytes (`SubscriptionStateTest` re-run) | — |

## 4. TMS core (project & task management)

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| Workspaces + members + roles | ✅ | `WorkspaceService`, `WorkspacePolicy`, `Workspaces.jsx`/`WorkspaceDetail.jsx` | — |
| Projects + members + statuses workflow CRUD | ✅ | `ProjectService`, `StatusController`, `ProjectDetail.jsx` | Statuses configurable per project ✅ |
| Task CRUD, keys, board (Kanban), list view | ✅ | `TaskService`, `KanbanBoard`/`TaskTable`, `TaskController` | — |
| Row-scoped reads (`tasks.view_own/_assigned/_all`) | ✅ | `TaskScope`, `TaskScopeAccessTest` | — |
| Priorities, labels, filters, deep links | ✅ | `FiltersBar`, `deepLinks.js`, task `?task=KEY` | — |
| Parent/child (2-level subtasks) | ✅ | `tasks.parent_id` self-FK | Only 2 levels — no epic/initiative hierarchy (§10) |
| Dependencies (blocks/related) + hard-block | ✅ | `DependencyController`, BFS cycle check, `hasOpenBlockers` | Enum is only `blocks\|related_to`; plan wants `relates\|duplicates\|clones` (G-14) |
| Comments + mentions + @autocomplete | ✅ | `CommentController`, `CommentThread`, mention cap | — |
| Attachments + signed download + quota | ✅ | `AttachmentController`, signed tenant-scoped route | — |
| Activity timeline | ✅ | `ActivityLogger`, `ActivityFeed` | — |
| Watchers (table + CRUD) | 🟡 | migration `000039`, `TaskWatcherController` | **No notification fan-out** to watchers; `present()` omits `watchers`; no UI (G-2a) |
| Issue types (catalog) | 🟡 | `issue_types` table, `config/issue_types.php`, `GET api/issue-types` | **Read-only**: `IssueTypeService::create/update/delete` exist but no routes; config comment promises runtime CRUD (G-3a) |
| Components | 🟡 | `project_components` + `task_component` pivot, CRUD routes, task filters | **No frontend** — invisible to users (G-2b) |
| Versions / releases | 🟡 | `project_versions`, `tasks.version_id`, CRUD routes | **No frontend** (G-2b) |
| Start dates, story points | 🟡 | columns in `000039`, validated + filtered in backend | **No frontend** (G-2b) |
| Work logs + time summaries | ✅ | `WorkLogService`, `WorkLogPanel`, `TimeSummary`, module gate `time_tracking` | No timer start/stop UI; no billable flag; no approval flow (G-15) |
| Task notifications (in-app/email) + prefs | ✅ | `NotificationService`, `TaskNotificationMail`, `notification_preferences` | Watchers excluded (G-2a) |
| Realtime (Reverb/Echo) | ✅ | `TaskSynced`/`CommentSynced`/`NotificationSent`, channel auth | — |
| Global search + command palette | ✅ | `GlobalSearchController` (workspaces/projects/tasks/users/employees), `CommandPalette.jsx` | LIKE-based; optional `pg_trgm`; no saved searches (G-5) |
| Task search (filter-based) | ✅ | `SearchController::tasks`, `Search.jsx` | No JQL parser, no saved/sharable filters (G-4) |
| Dashboard (personal) | ✅ | `DashboardController`, `Dashboard.jsx` (6 widgets + 3 charts) | No team/project/workspace dashboards, no custom widgets (G-8) |
| Reports overview + analytics | ✅ | `ReportsController`, `AnalyticsController`, `Reports.jsx` | Distributions only — no agile metrics (§12) |

## 5. HRMS — people & organization

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| Employee directory + filters/sort/options | ✅ | `EmployeeDirectoryQuery`, `Employees.jsx` | — |
| Employee profile + detail tabs | ✅ | `EmployeeDetail.jsx`, `EmployeePresenter` | — |
| PII redaction + access logging + signed photos | ✅ | `SensitiveFieldRedactor`, `EmployeeAccessLogger`, `EmployeePhotoService` | — |
| Hire flows (no login / new login / link account) | ✅ | `EmployeeUserProvisioner`, create modal | — |
| Employment status lifecycle | ✅ | `EmployeeStatus` enum: active/probation/on_notice/suspended/exited/terminated + history | Confirmation is a date field, not a state (acceptable) |
| Custom employee fields / tags | 🔴 | zero hits for `custom_field`/tags anywhere | Gap G-23 (custom-field engine) |
| Bulk employee import (CSV) | 🔴 | no import endpoints | Gap G-24 |
| Bulk status change | 🔴 | single-row only | Gap G-24 |
| Org: departments/designations/locations + tree chart | ✅ | `app/Services/Hrms/Org/*`, `Org.jsx`, `DepartmentChart` | Tree list with headcounts — not a graphical org chart (G-25, cosmetic) |
| Reporting hierarchy (single manager) | ✅ | `ReportingLine` (cycle-safe) | — |
| Matrix / dotted reporting | 🔴 | single `employees.manager_id` | Deferred decision (plan never specified) |
| Business units / legal entities / cost centers / teams | 🔴 | tables don't exist | Enterprise org modeling gap (G-26) |
| Transfers / promotions / confirmation / probation workflows | 🟡 | dates exist (`probation_end_date`, `confirmation_date`); generic status transition | No transfer/promotion entity, approval, or letter (G-16) |
| Employee self-service ("My*" pages) | ✅ | 10 pages wired: `MyHr`, `MyTeam`, `MyLeave`, `MyExpenses`, `MyPayslips`, `MyAssets`, `MyPerformance`, `MyDocuments`, `MyCompOff`, `MySurvey` — all real API calls | — |

## 6. HRMS — lifecycle (onboarding / offboarding)

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| Onboarding cases + templates + checklists | ✅ | `OnboardingService`, `Lifecycle/*`, 8 tables `000017`, case pages + `TemplateEditor` | — |
| Offboarding cases + clearance gate | ✅ | `OffboardingService`, `exit_clearances` (assets/leave/expenses blockers) | — |
| Document requests (both case types) | ✅ | `DocumentRequestService`, employee upload→HR verify | — |
| Auto-initiate exit on terminate | ✅ | `EmployeeStatusTransition` → `offboarding->initiate` | — |
| Checklist → TMS task conversion (pull-only) | ✅ | `CaseTaskConversion`, `hrms_task_links` | — |
| Automated reminders | 🟠 | `hrms:onboarding-reminders` command implemented | **Unscheduled** — never fires (G-1) |
| E-signature | 🔴 | none | Gap G-17 |
| IT provisioning automation | 🟡 | checklist item category `access` only | Manual to-do — by design until integrations |
| Buddy assignment / orientation | 🔴 | none | Low priority |
| Exit interview | 🔴 | none | Gap G-17 |
| Full & final settlement | 🔴 | clearance counters only; no settlement computation | Gap G-17 — HR-critical |
| Access revocation / account deactivation on exit | 🟡 | status → `exited` stops nothing at auth level | Exited user can still log in unless removed — verify intent (G-27) |
| Offer-to-joining / ATS | ⏸️ | not in HRMS plan | Deferred by design |

## 7. HRMS — attendance, shifts, leave, comp-off, holidays, expenses

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| Clock in/out (web) + punch window + night attribution | ✅ | `PunchClock`, `AttendanceService`, `ClockInWidget` | — |
| IP restriction + geofence | 🟡 | `PunchClock::rangeCheck` (CIDR + haversine), settings per section | **Flag-not-block** (out-of-range recorded, not refused); SPA sends only `{direction}` — geofence unexercised from UI (G-13) |
| Late/early/missing-punch policies | ✅ | `DayComputation` (`late_by_minutes`, `early_by_minutes`), `DayReading` absent | — |
| Overtime | ✅ | `ot_after_minutes`, `overtime_minutes`, payroll `ot_rate` | — |
| Break tracking | 🟡 | shift-level `break_minutes` deduction | No punch-level break in/out |
| Regularization (approval flow) | ✅ | shared approval engine, supersede-not-edit, auto-approve fallback | — |
| Attendance roll-up | 🟠 | `hrms:attendance-rollup` + job | **Unscheduled** (G-1) |
| Attendance calendar / CSV export | ✅ | month grid, `hrms/attendance/export` (throttled + audited) | — |
| Mobile attendance | 🟡 | punch accepts `source`/`device_id` | No mobile client / PWA (§14) |
| Biometric devices | 🔴 | none | Enterprise integration (deferred) |
| **Shifts (patterns/rosters/swap/approval)** | 🔵 | module key + permissions + tables (`attendance_shifts`, `attendance_rosters`, `employees.shift_id`) exist; consumed internally by `DayComputation` | **Zero routes/controllers/pages** — reserved stub (G-28) |
| Leave types/policies/balances | ✅ | `LeaveCatalogService`, `LeaveBalanceService` | — |
| Accrual (idempotent) | 🟠 | `POST hrms/leave/accrue` + ledger | `hrms:comp-off-accrue` etc. unscheduled |
| Carry-forward | 🟡 | columns + ledger kind exist | **No endpoint/command applies it** (G-29) |
| Encashment | ✅ | `LeaveRequestDecisions::encash()` | — |
| Half-day | ✅ | `LeaveHalf` enum | — |
| Hourly leave | 🔴 | `total_days` decimal only | Gap (nice-to-have) |
| Blackout dates / restrictions | 🔴 | none | Gap |
| Approval chain (manager→head→HR) | ✅ | `LeaveApprovalRouting`, no self-approval | **No delegation** of approvers (G-30) |
| Withdrawal / cancellation | ✅ | `LeaveRequestController::destroy` | — |
| Team leave calendar | ✅ | `LeaveRequestDirectory::teamCalendar` | — |
| Leave exemptions | ✅ | `LeaveExemptionService` + routes | — |
| Comp-off: eligibility/accrual/expiry/redemption | ✅ | `CompOffCredits`, `CompOffService`, expiry from `validity_months` | — |
| Holidays: calendars/location/optional | ✅ | `Holiday*` services, `Holidays.jsx`, year seeder | Import is config-seed only (no CSV/ICS) |
| Expense categories/receipts/finance approval | ✅ | `ExpenseService`, `financeStep()` | — |
| Reimbursement → payroll | ✅ | `ExpenseService::reimburse` → `PayrollService::reimburseExpenses` | — |
| Category spend limits / mileage / advances / tax | 🔴 | none | G-31 (limits + advances most commercial) |
| Multi-currency | 🟡 | per-claim `currency` char(3) | No FX conversion |

## 8. HRMS — compensation, payroll, statutory

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| Salary components/structures/assignments | ✅ | `CompensationCatalogService`, 4 tables `000022` | — |
| Salary revisions + threshold approval | ✅ | `SalaryRevisionService`, `RevisionStatus` enum | — |
| Compensation/revision letters | 🟡 | template document type seeded | No letter rendering/generation (G-18) |
| Payroll periods/runs | ✅ | `PayrollService`, `PayrollRunStatus` state machine | — |
| Payslip calculation (LOP, statutory, adjustments) | ✅ | `PayslipCalculator`, `PayslipRenderer` | — |
| Approve/publish/mark-paid/lock | ✅ | `PayrollLifecycle` | — |
| **Reopening (unlock/unpublish)** | 🔴 | no reverse transitions | Gap G-32 — ops will need it |
| Payslip download (signed, masked access rows) | ✅ | `hrms.payslips.download`, `PayslipReading` | HTML only, no PDF (G-33) |
| Payslip disputes | ✅ | inbox source | — |
| Bonuses/recoveries | ✅ | `payslip_adjustments` kinds | — |
| Loan/advance deductions | 🔴 | none | Gap G-31 |
| Bank file generation | 🔴 | none | Gap G-34 |
| Payroll export (CSV) | 🟡 | analytics CSV only | No payroll-specific register export |
| Statutory engine (PF/ESI/PT/TDS/LWF) | ✅ | `StatutoryEngine` + resolver + fixture-tested | — |
| Declarations/exemptions/profile masking | ✅ | `StatutoryDeclarationService`, `StatutoryProfile` reveal-with-audit | — |
| TDS projection/surrender | ✅ | `StatutoryService`, `TdsProjectController` | — |
| Filing / challan / return prep (Form 16, GSTR) | 🔴 | none | ⏸️-adjacent — explicitly future per plan (calc→prepare→file split) |

## 9. HRMS — performance, talent, engagement, documents, assets, analytics

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| Performance cycles (stage machine) | ✅ | `PerformanceCycleService` (goal_setting→…→calibration→completed) | Calibration = stage only, no tooling |
| Goals + evidence from tasks/work logs | ✅ | `PerformanceService`, `hrms:performance-evidence` (unscheduled) | — |
| Goal↔task linking | ✅ | `goal_task_links` (P12.6) + `hrms_task_links` (P20) — **two mechanisms** | Duplication to consolidate eventually (G-35) |
| Check-ins / 1:1s / feedback (manager/peer/report) | ✅ | `CheckInController`, `OneOnOneController`, `FeedbackService` | — |
| Reviews + ratings + self/manager review | ✅ | `review_summaries`, ack | — |
| Competencies / KPI entities / PIP / formal 360 / calibration sessions | 🔴 | none | Talent-layer gaps (G-36) |
| **Talent module (skills, succession, career paths, pools)** | 🔵 | module key + `hrms.talent.*` permissions only | Reserved — no tables/routes/pages (G-37) |
| Engagement: surveys (templates, pulse, eNPS, anonymity, segmentation) | ✅ | `000032` (6 tables), `EngagementService`, `Engagement.jsx`/`MySurvey.jsx` | — |
| Survey open/close scheduling | 🟠 | `hrms:surveys-open-close` | Unscheduled (G-1) |
| eNPS / campaigns / results | ✅ | `aggregateNps`, campaigns open/close/invite/results | — |
| Documents: types/expiry/verification/confidential/requests | ✅ | `DocumentService` + context, signed downloads, `hrms:documents-expiry` | — |
| Document versioning | 🔴 | none | Gap G-38 |
| Assets: categories/inventory/assign/return/maintenance/damage/retire | ✅ | `000030` tables + services, `Assets.jsx`/`MyAssets.jsx` | — |
| Asset replacement flow | 🔴 | no `replaced` state | Minor gap |
| HR analytics (8 readings + CSV + digests) | ✅ | `HrmsAnalyticsService` + `Analytics/*`, `Analytics.jsx` (8 tabs) | Digests unscheduled (G-1) |
| Scheduled reports | 🟠 | `hrms_report_schedules` + `hrms:report-digests` | Unscheduled (G-1) |
| Inbox (merged pending-work read model) | ✅ | `InboxService`, `000031`, `Inbox.jsx` | `hrms.inbox` module key unused as gate |
| **`hrms.exemptions` module key** | 🔵 | in plan lists only | No route/page uses it — delist or build (G-39) |

## 10. Workflows, approvals & automation (shared engines)

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| Generic approvals engine (multi-step) | ✅ | `ApprovalService` + `approvals`/`approval_steps`, used by leave/expense/regularization/comp-off/salary revisions | Sequential manager routing; **no parallel/any-one/all/conditional/delegation/SLA/escalation** modes (G-10) |
| HRMS audit (`hrms_audit_logs`, masked, diffs) | ✅ | `HrmsAuditLogger`, `AuditTrail.jsx` diff UI | — |
| Platform audit feed | 🟠 | `AuditLogsController` merges central audit + impersonation (capped 100/source) | No before/after payloads, no export, no login/logout events (G-6, G-40) |
| Correlation/request IDs | 🔴 | none | Gap G-41 |
| **TMS task workflow engine (transitions/validators/conditions/post-actions)** | 🔴 | only `task_statuses` CRUD (`StatusController`); any status change allowed; no transition table | Gap G-7 — plan §6 workflow-engine requirement entirely unmet |
| **Automation engine (EVENT→CONDITION→ACTION)** | 🔴 | zero matches for "automation" in code; no rule tables | Gap G-42 — plan §15 requirement unmet |
| Overdue-task notifications / due-date nudges | 🔴 | no TMS scheduled work at all | Part of G-1/G-42 |
| Custom field engine | 🔴 | zero hits | Gap G-23; multi-tenancy doc §15 Q5 shows it was consciously deferred |

## 11. Notifications

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| In-app + realtime + unread badge | ✅ | `NotificationService`, `NotificationContext`, `NotificationBell` | — |
| Email (4 TMS events, queued, tenant-stamped) | ✅ | `TaskNotificationMail`, `emails/task-notification.blade.php` | Single template, no localization, no per-tenant templates (G-43) |
| Per-event preferences | ✅ | `notification_preferences`, `GET/PUT api/notification-preferences` | Email-only gating (in-app always) — documented decision |
| HRMS notifications (leave/expense/approvals/assets…) | ✅ | notifiers in each context + deep links | — |
| Watcher/CC notifications | 🔴 | watchers not consulted by `NotificationService` | G-2a |
| Digest / batching / throttling / dedup | 🔴 | one row + one email per event | G-44 (HRMS report digests exist but unscheduled) |
| Push (web push/FCM) | 🔴 | none | "Push-ready" not even architected yet |
| Outbound webhooks (event subscriptions) | 🔴 | `WebhookController` = **inbound payments only** | G-45 — plan §18 channel list unmet |
| Localization | 🔴 | `locale=en`, no `lang/` | Future |

## 12. Reports & dashboards

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| TMS distributions (status/priority/assignee/project) | ✅ | `ReportsController` | — |
| Personal dashboard + 3 charts | ✅ | `DashboardController` + `Dashboard.jsx` | — |
| Analytics overview (progress, hours, created, contributors) | ✅ | `AnalyticsController` | — |
| HR analytics (headcount/attendance/leave/payroll/performance/documents/assets) | ✅ | `HrmsAnalyticsService` + `Analytics.jsx` | — |
| Time summaries (task/project/workspace, group-by, CSV) | ✅ | `WorkLogService` summaries, `TimeSummary.jsx` | — |
| Super-admin platform analytics | ✅ | `SystemAnalyticsController` | — |
| Velocity / burndown / burnup / cycle time / lead time / throughput | 🔴 | zero code matches | G-46 — blocked on sprint data (sprints missing) |
| Workload / capacity / team performance / SLA | 🔴 | none | G-46 |
| Team / project / workspace / custom dashboards | 🔴 | none | G-8 |
| Cross-domain reports (attendance vs delivery, cost analytics) | 🔴 | none — HR and TMS reports are siloed | G-47; needs read-only aggregation strategy per plan §16 |

## 13. Search, API & integrations

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| Global search (6 entity types + SA cross-tenant) | ✅ | `GlobalSearchController` | LIKE-based; `pg_trgm` opt-in; no fuzzy |
| Indexed search (tsvector) | 🔴 | none | G-48 (migration path to OpenSearch noted) |
| Saved/sharable searches | 🔴 | filters live in React state only | G-5 |
| JQL-like query language | 🔴 | none | G-4 |
| REST API for SPA (session + XSRF) | ✅ | 485 routes in `routes/web.php` | — |
| API tokens / Sanctum / OAuth / Bearer | 🔴 | not in composer.json | G-49 — blocks any integration story |
| API versioning / scopes / integration logs / idempotency (generic) | 🔴 | none | Part of G-49 |
| Inbound webhooks (payments) w/ signature + replay | ✅ | `PaymentService`, `payment_events` unique | — |
| Outbound integrations (Slack/Teams/GitHub/Google/SSO) | 🔴 | none | G-50 (Phase 2/Enterprise per plan) |
| Public registration + CMS marketing site | ✅ | `POST api/register`, `PublicSiteController`, `CmsPages.jsx` | — |

## 14. Frontend IA, mobile & UX

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| Consolidated sidebar (5 sections / ~12 items; SA 9) | ✅ | `Sidebar.jsx`, sectioned nav + active pill | Plan §23 requirement met |
| One-tab HRMS hub + rail tabs | ✅ | `HrmsLayout.jsx`, `HRMS_NAV`, `HrmsNavTest` | — |
| Permission/module-aware nav + route gates | ✅ | `AuthContext.can/check/hasModule`, `ProtectedRoute` | — |
| Responsive shell (drawer/hamburger) | ✅ | `Topbar.jsx`, `AdminLayout` | — |
| Mobile-first surfaces / PWA / native app | 🔴 | no manifest, no service worker | G-51 — punch/leave/approvals are the mobile use cases (plan §24) |
| E2E/browser tests (Dusk/Playwright) | 🔴 | none | G-52 |

## 15. Observability & testing

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| Public health + SA platform health | ✅ | `PlatformHealthController`, `PlatformHealthTest` | Queue "probe" only echoes driver — never tests worker (G-53); **no SPA page consumes it** |
| Backups UI / jobs UI / migrations UI | 🔴 | none | SA gap (G-54) |
| Structured logs / correlation IDs / metrics / tracing | 🔴 | plain-text single/daily logs | G-41 |
| Failed-jobs monitoring | 🔴 | `database-uuids` driver configured, nothing reads it | G-54 |
| Test suite | ✅ | 173 files, per-tenant isolation harness, ~1469 static methods | Full-suite total ❓ (needs live run) |
| Performance tests | 🟡 | index/column assertions + query-count checks | No load benchmarks |

---

## 16. Data lifecycle

| Feature | Status | Evidence | Problems / action |
|---|---|---|---|
| Tenant export (ZIP, chunked, signed) | ✅ | `ExportService` + 8 categories | ZIP cleanup missing |
| Retention/purge | 🟡 | `hrms:retention` (HRMS only; audit exempt) | Not scheduled; no platform-wide retention policy |
| Tenant soft delete + restore | ✅ | `TenantController` | — |
| Tenant hard purge / GDPR erasure | 🔴 | no cascade-drop path | G-55 |
| Employee soft delete + terminate | ✅ | `EmployeeService` | — |

---

## 17. Doc-vs-code contradictions found (stale documentation)

These must not be trusted from the docs; the code is authoritative:

1. `AGENTS.md` Pitfalls "No `/register` route, page, or endpoint" — **false**; `POST api/register` + `/register` page exist.
2. `AGENTS.md` "GuestRoute (login/forgot/reset only — **no register**)" — false.
3. `AGENTS.md` entry note re `resources/js/app.js` — file no longer exists.
4. `AGENTS.md` test gate "1535/7882" — statically 1469 methods; ❓ until a live run.
5. `AGENTS.md` "migration numbering pre-assigned … through `000031`" — actual tenant migrations reach `000039`.
6. `.agents/06-tms.md` "email NOT wired / zero `Mail::`" and "Exports: NONE for TMS" — both stale (email + `ExportService` shipped).
7. `.agents/memory/state.md` "Payments: NONE", "Factories: stock only" — stale (`BillingTest`, `PaymentWebhookTest`, `FactoryParityTest` exist).
8. `docs/multi-tenancy-architecture.md` promises `task.watched` notifications — schema shipped, wiring absent.
9. `config/issue_types.php` promises runtime rename/recolor/reorder/add — no routes expose it.
10. `AGENTS.md`/HRMS plan call ~12 `hrms:*` commands "scheduled" — only 2 jobs are in `routes/console.php`; nothing runs `schedule:run` per the repo's own config either (native host relies on systemd; cron for `schedule:run` not documented).
11. `IMPLEMENTATION_TRACKER.md` internally contradicts itself (Phase 16 boxes unchecked while identical Platform Maturity items are checked).
12. `Subscription::EVENT_SEATS_CHANGED` documented in the model — never emitted.
13. Plan modules `api` + `audit_export` sellable — no route gates on them.
14. `routes/web.php:325` labels the new TMS block "Phase 7" — collides with AGENTS' existing Phase 7 (Search & Reporting).
15. Runbook §6 "Queue connection status" — health endpoint only echoes the configured driver.

---

## 18. Architecture verdict (plan §3, condensed)

| Area | Rating | Why |
|---|---|---|
| Backend (Laravel) | **Excellent** | Consistent controllers → FormRequests → policies → services → bounded contexts; 300-line ceilings enforced; enums; presenters; shared engines (approvals, audit, HrmsScope/TaskScope) |
| Frontend (React) | **Good** | Consolidated IA, deep links, module/permission mirrors, portalled overlays; needs the backend-only TMS fields surfaced + mobile story |
| Database | **Excellent** | DB-per-tenant isolation is real and tested; hardening indexes; repair-safe migrations with documented pitfalls |
| SaaS (billing/entitlement) | **Good → Needs Improvement** | Entitlement/quota excellent; billing lacks invoices/dunning/expiry automation |
| Security | **Good → Needs Improvement** | Isolation/RBAC/signed URLs strong; missing 2FA/SSO/login-audit/CSP |
| TMS depth (vs JIRA-class) | **Needs Improvement** | Solid task core; the entire agile layer (sprints/workflow/automation/views/search syntax) is absent |
| HRMS depth (vs KEKA-class) | **Good** | Plan phases all shipped; commercial HRMS staples (transfers, F&F, shifts, talent) are the gaps |

**Verdict: on the right path.** No redesign needed — the gaps are *features and wiring*, not architecture.

---

*Companion documents: `gap-register.md` (prioritized missing/improvement register),
`end-to-end-flow.md` (verified organization journey through the product).*
