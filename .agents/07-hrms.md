# 07 — HRMS

Module keys (`config/subscriptions.php`, mirrored 1:1 in
`resources/js/utils/hrmsModules.js` — shell test fails on drift): `hrms.core` (gate
for the whole surface) + `hrms.onboarding|offboarding|attendance|attendance.remote|
shifts|leave|leave.exemption|comp_off|holidays|expenses|compensation|payroll|
payroll.statutory|exemptions|performance|talent|engagement|documents|assets|analytics|
inbox`. `hrms.shifts`/`hrms.talent` reserved (permissions exist, no pages).

## Shared primitives (built once, reused everywhere)

- Generic `approvals`/`approval_steps` engine (`ApprovalService`); manager step
  resolves via `ReportingLine::managerOf()`; no resolvable manager → auto-approve
  (audited, same transaction).
- Append-only `hrms_audit_logs` + `hrms_data_access_logs`, written ONLY via
  `HrmsAuditLogger` (field NAMES travel, values masked at writer; reads via
  `accessed()` with `DataAccessAction` view/download/export — salary/bank/statutory/
  document reads and every export write one).
- Single-row `hrms_settings` (`HrmsSetting::current()`, `setting('a.b.c')` dotted
  reader); defaults catalog `config/hrms.php`, seeded by
  `HrmsDefaultsProvisioner::provision()` — ONE guarded step per catalog, never a
  single early-return.
- Rolled-up reads via `…Reading` classes; CSV exports are session routes with
  per-domain permission + `throttle:30,1` + one `accessed(…, Export)` row
  (attendance, analytics). No `docs/hrms-architecture.md` — plan Parts 1–3 + AGENTS.md rule.

## Employee core (P2)

`employees` (+`employment_types`, `employee_status_history`), bounded context
`Services/Hrms/Employee/`: Service (thin orchestrator), DirectoryQuery/Sort/
FilterOptions, UserProvisioner, ReportingLine (self/cycle rejection),
StatusTransition (record + history + exit date, one transaction), PhotoService,
AccessLogger, Redactor, Presenter. `employees.user_id` unique-nullable (service
accounts have no row); `manager_id` `nullOnDelete` (NOT cascade — cascade would
delete a department with its manager); history cascades with employee,
`actor_user_id` nulls so the trail outlives accounts. Inline-hire ordered: roles
resolved BEFORE insert; login written BEFORE employee row (missing routing row =
un-signable-in; spare account is visible/deletable); goes through
`EmployeeService::create()` EXCEPT `hrms:backfill-employees` (data migration,
infers nothing — no joining date/type/personal email — and bypasses the
`employees` plan quota, which is reported not enforced). `EmployeeCodeGenerator::
retrying()` takes an optional preferred code (`EMP-{user_id}` fallback).
Directory masks personal fields + `photo_url` even for privileged readers; access
row only when personal fields were actually visible AND populated. Update REJECTS
`status/manager_id/employee_code/user_id`/login fields by name (never silent-drop).

## PII & file rules (non-negotiable)

`SensitiveFieldRedactor`: SAME response keys both privilege levels (masked values
+ `restricted: true`, never absent keys); email masks domain too; `dateOfBirth()`
takes no arg, returns null; phone cuts `ext/x/#` before digit extraction.
`SENSITIVE_COLUMNS` single-sources masking + access-log fields. Confidential rows
are INVISIBLE (not unreadable) without `hrms.documents.view_sensitive` — a list
that names them leaks existence. Confidential show/download writes an access row.
All HRMS file links signed + tenant-scoped (see `02-backend-conventions.md`).

## Contexts (services → policies → requests → controllers → SPA)

- **Org:** `DepartmentTree` (cycle check/descendants/path), `DepartmentChart`
  (nested read; `headcount` subtree vs `direct_count` node; cycle reader prunes +
  surfaces unreachable rows top-level), subtree headcount seeds at `direct_count`;
  `applyGeofence()` derives `is_geo_fenced` from geometry (never stored-as-sent);
  reorder intersected with real siblings (`OrgNaming::order()`); `hrms.org.manage`
  does NOT imply `hrms.org.view` (separate named policies for discovery); starters
  invent no facts (no parent/head/country/timezone; `designations.level` IS set).
  Employee org FKs writable via `EmployeeProfile::WRITABLE` + `orgRules()`; presenter
  emits scalars; directory filter is EXACT match (not subtree); filter options come
  from the SAME endpoint offering the filter (org chart `GET` NOT reused — different
  permission).
- **Lifecycle (onboarding/offboarding):** `OnboardingService` + `OffboardingService`
  over `Lifecycle/` (Templates/Cases/CaseProgress/Checklist + shared
  `DocumentRequestService` state machine). Mandatory derived from template link,
  never stored; `removeTemplateTask` refuses while open cases reference it;
  clearance counters task-driven (leave/expense read honest 0 until wired);
  `terminate()` auto-initiates exit run (stamps `exit_date`, never touches status).
  One shared `Checklist.jsx`; case detail routes carry no permission gate (policy
  decides); notifications asymmetric (owner always; manage-holders for hr-scoped;
  ≤1 nudge/item/week; portable `where('data->case_task_id')` dedup).
- **Attendance:** `PunchClock` (asymmetric window: in bounded both sides, out from
  below only; night punches attribute by owning date, noon cutoff; out-of-range =
  flag incl. IP/geofence, never refusal) + `DayComputation` + `DayReading`. Punch
  endpoint: no permission (login ⇒ record), module-or-ownership 403 from
  `hrms.attendance.remote`, server-observed IP only. Regularization supersedes via
  shared approval engine (inserts `regularized` punches, recomputes day).
  `AttendanceService::rollup()` one loop (day row per ACTIVE employee, no audit rows);
  `hrms:attendance-rollup` has `--tenant XOR --all`, strict non-future date,
  `--dry-run`; `AttendanceRollupJob` carries central tenant id + own `using()`, `tries=1`.
- **Leave/comp-off/holiday:** idempotent accruals, signed rebuild with cap/floor,
  availability reserves pending only; ask/decide/cancel/encash; manager→head→HR
  routing, no self-approval; attendance `leave` merge; comp-off `hrms:comp-off-accrue`;
  holiday merge into attendance/leave/comp-off; `HrmsCatalogTest` guards
  catalogue-key↔column both directions (the `code`/`is_confidential`/
  `validity_months` lesson — a bad catalog fails EVERY tenant's provisioning).
- **Payroll/statutory:** `Money` + enums/models/catalogs; `PayslipCalculator` LOP math
  + `PayrollService` runs + `PayrollLifecycle` transitions + `payrollPublished`
  notifier; payslip reads via `PayslipReading` (per-read access rows) + `PayslipRenderer`
  (seeded templates) + signed download; compensation writes via catalog service +
  component/structure policies. Statutory = SEPARATE high-risk layer:
  table-driven `StatutoryEngine` (PF/ESI/PT/TDS/LWF, no hard-coded thresholds,
  fixture corpus `StatutoryEngineTest`), `StatutoryResolver` jurisdiction gates,
  snapped onto each payslip (config change never rewrites a locked one); TDS
  projection + surrender; manage-only reveal with access rows.
- **Expenses/performance/assets/surveys/inbox/analytics/task-links:** claim
  file/submit/decide/reimburse + payroll hook + notifications; cycle stage machine
  + evidence engine (task evidence via `visibleTaskQuery`, shown to reviewer, never
  auto-rated) + `hrms:performance-evidence`; asset handover + exit gate +
  `hrms:assets-overdue`; anonymous surveys + `hrms:surveys-open-close`; inbox reads
  + unread cache; `hrms_task_links` (employee/task/kind, unique; kind `goal` feeds
  evidence with auditable `task_ids`) + `tasks.hrms_employee_id` (HR asks on HR home);
  checklist→task conversion is pull-only sync; work-log-derived attendance opt-in
  (`attendance.auto_derive_from_work_logs`, default false).

SPA: `resources/js/pages/hrms/` (43 pages incl. `Employees`, `Org`, case pages,
`Attendance`, `Leave/MyLeave`, `CompOff/MyCompOff`, `Holidays`, `Compensation`,
`Payroll/RunDetail`, `MyPayslips`, `Statutory`, `Expenses/MyExpenses`,
`Performance/CycleDetail`, `MyPerformance`, `Documents/MyDocuments`, `Assets/MyAssets`,
`Engagement/MySurvey`, `Inbox`, `Analytics`, `AuditLog`, `MyTeam`), `HrmsOverview`
tiles wired through `HRMS_MODULE_ROUTES` (tile without route fails shell test).
Roadmap Phase 3: collapse ~20 sidebar items into ONE tab with sub-tabs (redirects
keep old links).
