# HRMS module: build history and design rationale (Phase 15, P1–P16)

> Moved verbatim from `AGENTS.md` (section "HRMS module"). Read it before HRMS work: it records *why* each bounded context is shaped as it is and the gotchas each slice hit. Current-state reference lives in `AGENTS.md` under "HRMS reference".

## HRMS module (Phase 15, in progress — branch `new/hrms-development`)
- **Read `docs/hrms-implementation-plan.md` before any HRMS work.** It is a 22-phase, task-level plan
  (Parts 0-9) written to be executed one small task per commit, and it is the authoritative spec.
- The plan's binding design decisions (all in its Part 2): `hrms.*` **dotted module keys** stored in
  `plans.limits.modules` (so `TenantLimits::hasModule()` + the existing `ensure_module` middleware work
  unchanged); a new `business` plan inserted between `pro` and `enterprise`; per-tenant SA switching via
  `tenants.features_override.modules` (**additive** — it can grant, never revoke); `employees.user_id`
  as a **unique nullable** FK to `users` (a user with no employee row is a service account);
  `config/hrms.php` as the per-tenant defaults catalog seeded by a new
  `HrmsDefaultsProvisioner::provision()` step; and three shared primitives built once in the
  plan's Phase 1 — a generic `approvals`/`approval_steps` engine, an append-only `hrms_audit_logs`
  (separate from `activities`), and a single-row `hrms_settings`.
- **Shipped so far.** P1.1–P1.7 (entitlement foundation: `hrms.*` module keys, 47 permissions, plan
  module lists, per-tenant SA switching, `GET|PUT api/tenants/{tenant}/hrms`); P1.8–P1.10 (the three
  shared primitives: `2026_09_27_000014` migration, `HrmsAuditLogger`, the generic `ApprovalService`,
  and the HRMS defaults seeding hook); P1.11 (the module-gated `/hrms` SPA shell); P2.1
  (`2026_09_27_000015` — `employment_types`, `employees`, `employee_status_history`); P2.2 (the
  employee domain: enums, models, and the service — see the P2.2 notes below); P2.3
  (`EmployeePolicy` + `EmployeePresenter` + the `Requests/Hrms` family + `Hrms/EmployeeController` +
  routes, with signed photo download); P2.4 (`SensitiveFieldRedactor` masking + `EmployeeAccessLogger`
  + `EmployeePhotoService`, with the payload keys identical across both privilege levels); P2.5–P2.6
  (the SPA directory + `EmployeeDetail`/`StatusHistory`/`EmployeeEditModal`); P2.7
  (`EmployeeBackfill` + `hrms:backfill-employees`); P3.1 (`2026_09_27_000016` — `departments`,
  `designations`, `locations` + the three nullable `employees` FKs); P3.2 (the org models and the
  `app/Services/Hrms/Org/` bounded context — see the P3.2 notes below); P3.3 (the org HTTP surface —
  `app/Policies/Hrms/Org/`, `app/Http/Requests/Hrms/Org/`, `app/Http/Controllers/Hrms/Org/`, the
  routes, and the three org FKs finally made writable on the employee — see the P3.3 notes below);
  P3.4 (the org SPA page: `pages/hrms/Org.jsx`, `components/hrms/DepartmentTreeColumn.jsx`, the three
  form modals and the members panel, plus the directory's department filter — see the P3.4 notes);
  P3.5 (the org starters: the HRMS defaults seeder gains `seedOrgCatalogs()` — see the P3.5 notes);
  **P13.1** (`2026_09_27_000016_create_hrms_document_tables` — `document_types` +
  `employee_documents`, plus `tests/Feature/HrmsCatalogTest.php`, the cross-catalogue guard — see
  the P13.1 notes below); and
  **P13.2** (the document bounded context: `Services/Hrms/DocumentService.php` plus `Services/Hrms/Document/`,
  the `DocumentType`/`EmployeeDocument` models, `seedDocumentTypes()`, the signed `hrms.documents.download`
  route, and `hrms:documents-expiry` — see the P13.2 notes below); and **P13.3** (the document HTTP
  surface — `Policies/Hrms/Document/`, `Requests/Hrms/` document pair, `Controllers/Hrms/`
  document pair, and the routes — see the P13.3 notes below); and **P13.4** (the document SPA:
  `pages/hrms/Documents.jsx`, `pages/hrms/MyDocuments.jsx`, `components/hrms/DocumentUploader.jsx`,
  plus the profile `documents` tab — see the P13.4 notes below); P4.1
  (`2026_09_27_000017` — the onboarding/offboarding lifecycle tables, plus
  `tests/Feature/HrmsLifecycleTablesTest.php` — see the P4.1 notes below); P4.2
  (the onboarding/offboarding services: `OnboardingService` + `OffboardingService`
  orchestrating `Services/Hrms/Lifecycle/`, the shared `DocumentRequestService`,
  and the terminate → auto-initiate wiring — see the P4.2 notes below); P4.3
  (the lifecycle HTTP surface: `Policies/Hrms/Lifecycle/` bases + markers,
  ten FormRequests, four controllers, `LifecyclePresenter`, routes — see the
  P4.3 notes below); P4.4 (the lifecycle SPA: shared `Checklist.jsx`, case pages,
  `TemplateEditor.jsx` — see the P4.4 notes below); P4.5 (lifecycle notifications:
  `onboardingTaskDue`, `hrms:onboarding-reminders`, deep links — see the P4.5
  notes below); P5.1 (`2026_09_28_000018` — the attendance tables plus raw-SQL
  `employees.shift_id`, with the CHECK-preservation test — see the P5.1 notes
  below); P5.2 (the punch service: `AttendanceService` orchestrating
  `Services/Hrms/Attendance/` (`PunchClock`, `DayComputation`, `DayReading`),
  the models/enums, and the `attendancePunches()` relation — see the P5.2
  notes below); P5.3 (remote clock-in policy + punch endpoint: module-or-ownership
gates, server-observed IP, per-section settings merge, `DayPresenter` — see
the P5.3 notes below); P5.4 (regularization via the shared approval engine:
manager step from `ReportingLine::managerOf()`, supersede-don't-edit apply,
policy-decides-from-the-step, `Regularization*` bounded context — see the
P5.4 notes below); P5.5 (scheduled roll-up: `hrms:attendance-rollup` +
`AttendanceRollupJob` over one `AttendanceService::rollup()` loop — see the
P5.5 notes below); P5.6a (attendance reads: month grid, today widget payload,
and audited CSV export behind one `AttendanceDayPolicy`); P5.6b (the
attendance SPA: `Attendance.jsx`, `AttendanceApprovals.jsx`, `ClockInWidget`,
`AttendanceCalendar`, regularization notification branches); P6.1
(`2026_09_28_000019` — the leave tables: types/policies/balances/ledger/
requests/days/exemptions, plus `HrmsLeaveTablesTest`); P6.2a (leave enums,
models, conformant catalog, starter seeding + `HrmsCatalogTest` guard);
P6.2b (`LeaveBalanceService`: idempotent accruals, signed rebuild with
cap/floor, availability reserving pending only); P6.2c (`LeaveRequestService`
+ `Decisions` + `Validation` + `LeaveCalendar`: ask/decide/cancel/encash,
attendance `leave` merge); P6.3 (`LeaveApprovalRouting`: manager → head →
HR, no self-approval); P6.4a (catalog HTTP + `hrms.leave` gate, R18 route-group
finding); P6.4b (balances read + managed accrue runs, `LeaveBalanceReading`
split); P6.4c (asks/exemptions HTTP, per-record policies); P6.5 (availability
+ team-calendar reads, `Leave.jsx` admin hub, `MyLeave.jsx`, request modal);
P6.6 (`leaveRequested`/`leaveDecided` notifications + deep links); P7.1
(`2026_09_29_000020` — comp-off tables + `HrmsCompOffTablesTest`); P7.2
(`CompOffCredits` + `CompOffService`, `hrms:comp-off-accrue`, comp-off
settings column follow-up); P7.3 (comp-off HTTP surface + `hrms.comp_off`
gate); P7.4 (`CompOff.jsx` + `MyCompOff.jsx`); P7.5 (comp-off
notifications); P8.1 (`2026_09_29_000021` — holiday tables +
`HrmsHolidayTablesTest`); P8.2 (`HolidayService` + assignments + seeder);
P8.3 (holiday merge into attendance/leave/comp-off, full-suite chunks);
P8.4a/b/c (holiday HTTP + `Holidays.jsx`); P9.1 (`2026_09_30_000022` —
payroll core tables + `HrmsPayrollTablesTest`); P9.2a/b (`Money` + payroll
enums/models/catalogs + `CompensationService`/`SalaryRevisionService`);
P9.3a (run/payslip/adjustment models + attendance/leave read edges +
`HrmsPayrollReadsTest`); P9.3b (`Payroll/` bounded context: `PayslipCalculator`
LOP math + `PayrollService` runs + `PayrollLifecycle` transitions +
`payrollPublished` notifier + `HrmsPayrollEngineTest`); P9.4 (payslip reads:
`PayslipPolicy` + `PayslipReading` with per-read access rows + `PayslipRenderer`
via seeded `payslip_templates` + signed `hrms.payslips.download` +
`HrmsPayslipAccessTest`); P9.5a (compensation writes: `CompensationCatalogService`
+ component/structure policies + `Salary{Component,Structure,Assignment}Request`
+ the three payroll controllers + `HrmsCompensationApiTest`); P9.5b (run writes:
`PayrollRunPolicy` + `Payroll{Run,Adjustment}Request` + run/adjustment controllers
+ self-scoped `my-payslips` + `HrmsPayrollRunApiTest`); P9.6 (`Compensation.jsx`
+ `Payroll.jsx` + `PayrollRunDetail.jsx` + `MyPayslips.jsx` + sidebar/routes); P10.1
(`2026_10_03_000023` — statutory tables + `HrmsStatutoryTablesTest`); P10.2
(`StatutoryEngine` pure table-driven PF/ESI/PT/LWF/TDS + `StatutoryEngineTest`
fixture corpus); P10.3 (`StatutoryResolver` jurisdiction gates + calculator
fold + `recomputeStatutory` + `hrms:statutory-recompute` +
`HrmsStatutoryIntegrationTest`); P10.4 (`StatutoryService` TDS projection +
surrender + `HrmsTdsProjectionTest`); P10.5 (`StatutoryProfile` masking +
`StatutoryProfileService`, array-form policy binding, manage-only reveal with
access rows + `HrmsStatutoryProfileTest`); P10.6a (rulebook + declaration HTTP:
`StatutoryDeclarationService`, both policies, `Statutory{Configuration,Declaration}Request`,
both controllers + `HrmsStatutoryConfigApiTest`); P10.6b (TDS + recompute HTTP:
`TdsProjectPolicy`, `Tds{Projection,Surrender}Request`, `TdsProjectController` +
`Statutory.jsx` + `HrmsStatutoryTdsApiTest`); P11.1 (`2026_10_04_000027` — expense tables +
starter catalogue + `HrmsExpenseTablesTest`); P11.2 (`ExpenseService`
file/submit/decide/reimburse + payroll hook + expense notifications +
`HrmsExpenseServiceTest`); P11.3a (expense HTTP: `ExpenseCategoryService`,
claim + category policies, the four expense requests, both controllers +
`HrmsExpenseApiTest`); P11.3b (`Expenses.jsx` + `MyExpenses.jsx` +
`ExpenseClaimModal.jsx` + notification branches + shell pin); P12.1
(`2026_10_05_000028` — performance tables + `HrmsPerformanceTablesTest`); P12.2
(`PerformanceService` evidence engine + `hrms:performance-evidence` +
`HrmsPerformanceEvidenceTest`); P12.3 (`PerformanceCycleService` stage
machine + feedback generation + completion notifications +
`HrmsPerformanceCycleTest`); P12.4a/b (performance HTTP: cycle/goal/note/
1:1 + feedback/review/evidence surfaces + `HrmsPerformanceApiTest`); P12.5
(`Performance.jsx` + `PerformanceCycleDetail.jsx` + `MyPerformance.jsx` +
`GoalCard`/`CheckInComposer` + nudges); P12.6 (goal↔task attach/detach +
`HrmsGoalTaskLinkTest`); P14.1 (`2026_10_07_000030` — asset tables +
`HrmsAssetTablesTest`); P14.2 (`AssetService` + handover service + exit
gate + `hrms:assets-overdue` + `HrmsAssetServiceTest`); P14.3a/b (asset
HTTP + `Assets.jsx`/`MyAssets.jsx` + nudges + `HrmsAssetApiTest`);
P15.1 (`000031` inbox reads + `InboxService` + `HrmsInboxTest`); P15.2
(inbox HTTP + `HrmsInboxApiTest`); P15.3 (clearance/document/cycle/share
notifiers + `HrmsTaxonomyTest`); P15.4 (`Inbox.jsx` + badge + copy/links +
shell pin); P16.1 (`2026_10_09_000032` — survey tables +
`HrmsSurveyTablesTest`); P16.2 (`EngagementService` + templates +
anonymity + `HrmsEngagementTest`); P16.3a (survey HTTP: both policies,
four requests, both controllers, `hrms:surveys-open-close` +
`HrmsSurveyApiTest`).
Phase 13
  was jumped to *before* Phase 4 because P4.1's `document_requests.document_type_id` is a real FK to
  `document_types`, and no migration created that table until P13.1 — the plan's "documents ship
  first" prerequisite is a hard schema dependency, not a preference.
- **Migration numbering is pre-assigned** in the plan's Part 3.3 (`2026_09_27_000014` for the shared
  tables, through `2026_10_04_000031`; later phases extended the series beyond the pre-assignment —
  the tenant set currently reaches `2026_10_17_000039`), one monolithic tenant migration per phase — except the
  document tables, renamed from their assigned `000026` to `2026_09_27_000016`. Five later files
  (lifecycle, leave, comp-off, payroll, statutory) hold FKs into `document_types`/`employee_documents`
  and PostgreSQL validates the referenced table at `CREATE` time, so the later number breaks every
  fresh provision with `relation "document_types" does not exist"` while SQLite tolerates the forward
  reference — which is why only the PG path ever saw it. The file is `hasTable`-guarded, so an
  already-migrated database re-runs it as a no-op and `tenants:provision` re-records it under the new
  name. Table tests `require` migration files by their **literal filename**, so a rename must update
  them in the same commit.
- **`Schema::getColumns()` spells "no default" two different ways.** SQLite *omits* the `default` key
  for a column that has none; PostgreSQL returns the key with a `null` value. Written as
  `($c['default'] ?? 'sentinel') === null`, every column reads as optional and a "required columns"
  assertion passes while checking nothing. Use
  `! array_key_exists('default', $c) || $c['default'] === null`, and verify on **both** grammars. This
  is the dual of the existing "PostgreSQL-only SQL slips past the sqlite fast-path" note, and it bites
  the tenant test suite specifically because `IsolatesDatabase` is sqlite-only — nothing in
  `php artisan test` ever exercises the PG shape.
- **Chain `unique()` BEFORE `constrained()` on a `foreignId`.** `constrained()` returns through
  `references()->on()->foreign()`, so any modifier chained after it is **silently dropped** — the FK is
  emitted with no unique index and no error. `employees.user_id` shipped with this bug and enforced
  nothing until `HrmsEmployeeTablesTest::test_a_user_can_back_at_most_one_employee_record` caught it.
- **P2.2 (employee domain) — bounded contexts, not top-level files.** The plan's literal
  `app/Models/Hrms/Employee.php` / `app/Services/Hrms/EmployeeService.php` paths are superseded by
  D2.16.2 folder-per-context, matching P1.9: `app/Models/Hrms/Employee/{Employee,EmploymentType,EmployeeStatusHistory}.php`,
  `app/Services/Hrms/Employee/{EmployeeService,EmployeeDirectoryQuery,EmployeeUserProvisioner,ReportingLine,EmployeeStatusTransition}.php`
  + `ValueObjects/EmployeeProfile.php`, `app/Support/Hrms/EmployeeCodeGenerator.php`, and the
  `EmployeeStatus`/`WorkMode` enums. `EmployeeService` stays a thin orchestrator; the rules live in
  `ReportingLine` (self/cycle rejection) and `EmployeeStatusTransition` (record + history row + exit
  date in one transaction).
- **Read `QueryException::errorInfo[2]`, never `getMessage()`, to identify a unique violation.**
  Laravel appends the failing statement to the message, and every insert into `employees` *names*
  `employee_code` — so matching the full text reports a violation on `user_id`/`email` as a code
  collision, retries it five times, and finally blames a code failure that never happened.
  `EmployeeCodeGenerator::isCodeCollision()` reads the driver message only.
- **The inline-hire path is deliberately ordered, not atomic.** `users` is in the tenant DB and
  `tenant_users` routing is central, so no transaction covers both; the login is written *before* the
  employee row because a missing routing row makes a hire un-signable-in and unrepairable from the UI,
  where a spare account is a visible, deletable record. `TenantProvisioner::syncRouting()` is the
  repair path. `EmployeeUserProvisioner` resolves roles *before* inserting the user — validating after
  leaves a real account with no roles and no employee to explain it.
- **Carbon 2 `diffInYears` is signed and returns a float.** `Employee::tenureOn()` wraps it in `abs()`
  *and* casts to `int`; without either, a past joining date returns a negative span and the `?int`
  return type deprecates on PHP 8.3.
- **`exited_reason` is stamped by the offboarding transition, and re-stamped on a
  re-departure.** A rehired-then-dismissed person did not leave on the first
  episode's date; the first departure keeps its own date and reason in
  `employee_status_history`. Reactivation touches neither field — erasing the
  record of the exit is not what "reactivated" means.
- **HRMS HTTP surface lives in `app/Http/Controllers/Hrms/`** (not the plan's `Api/Hrms/`) matching
  this repo's flat controller root, behind `ensure_module:hrms.core` + `permission:hrms.view` with
  `EmployeePolicy` deciding per record. `view` includes **self** — no tenant permission grants
  reading your own record, and every later "my payslip" surface depends on it. `EmployeePresenter`
  **omits** the personal fields for a reader without `hrms.documents.view_sensitive` and sets
  `restricted: true` rather than blanking them. `EmployeeUpdateRequest` **rejects** `status`,
  `manager_id`, `employee_code`, `user_id` and the login fields by name — dropping them silently
  would answer 200 to a client that thinks it changed a status.
- **Never put `sometimes` before a conditional `required`.** `sometimes` means "skip the remaining
  rules for this field when the key is absent", so `['sometimes', Rule::requiredIf(...)]` skips the
  very rule meant to fire — the inline-hire `password` rule was dead and every hire got no password.
- **Every HRMS photo/file link is signed and tenant-scoped**, never `Storage::url()`: unsigned, a
  person's photo is readable by anyone holding the URL and revoking it means unpublishing a file.
  The central `tenant` id rides inside the signature and the lookup happens inside
  `TenantDatabaseManager::using()`; `HrmsEmployeeApiTest` covers the flushSession + `iso_system` case.
- **HRMS PII masking lives in `SensitiveFieldRedactor`** (P2.4) and the *response keys are the same*
  for both payloads — a non-privileged reader gets masked values plus `restricted: true`, never absent
  keys. Its `email()` masks the **domain** too (for a personal address the domain is the provider);
  `dateOfBirth()` takes no argument and returns null (a year alone identifies someone in a small
  company); `phone()` cuts any `ext`/`x`/`#` **before** extracting digits, or the extension shifts the
  mask. `SENSITIVE_COLUMNS` is the single source for both what gets masked and what
  `hrms_data_access_logs.fields` records.
- **The employee directory masks personal fields even for a privileged reader**, and so is
  `photo_url` — a list of 50 rows with home addresses is a bulk-harvest surface no single-record
  screen is. `EmployeeAccessLogger::recordView()` writes the access row only when the personal fields
  were actually visible *and* were populated; a false row in a sensitive-access ledger is worse than
  none. `EmployeePhotoService` holds the photo's three rules (signed, tenant-scoped, download-logged)
  and its URL carries the reader's id **inside the signature** so the download is attributable.
- **HRMS SPA pages live in `resources/js/pages/hrms/`** (`HrmsOverview.jsx` moved there in P2.5) behind
  the `module="hrms.core"` route gate; the directory is `Employees.jsx` on `permission:hrms.employees.view`,
  with the create modal's three login modes (none / new / link) — "no account" is first-class so the
  inline-login fields are never the default state of a new-person form. `work_modes` is **served by
  `filterOptions()`** from the PHP enum, never hardcoded in JS: a JS copy of a PHP enum is drift no test
  can catch. The `department` filter is absent on purpose until P3.1 adds the column. P2.6 added
  `EmployeeDetail.jsx` (URL-driven tab via `HRMS_PROFILE_TABS`, module-filtered, **entries only for
  sections that have shipped**), `StatusHistory.jsx` and `EmployeeEditModal.jsx`; the directory's name
  cell links via `employeeUrl(id, tab)`. **Two product shells (TMS/HRMS separation):** the sidebar is
  product-aware — `Sidebar.jsx` picks `tmsSections` vs `hrmsSections` from `utils/nav.js` by
  `productForPath()` (`utils/product.js`: `/hrms*` + `/my` render the HR shell, everything else the
  task shell). The HR shell lists the HRMS hub groups as **collapsible sections** (derived from
  `HRMS_NAV`, never re-listed; the group holding the current page opens on its own, explicit toggles
  persist per label, collapsed sidebar navigates a group icon to its first page) with My HR leading
  Overview, plus shared Administration (Users/Roles/Settings/Security, no Webhooks) and Billing; the
  TMS shell has no `/hrms` entry and no My HR at all — crossing shells is the topbar
  `ProductSwitcher.jsx`'s job (shown only for the other product the caller can reach: subscribed
  product + a TMS landing permission / the `hrms.core` module; never for a non-impersonating super
  admin). There is no secondary rail: `HrmsLayout.jsx` is deleted and `/hrms` routes render pages
  directly inside an invisible `HrmsErrorScope` (App.jsx) that keeps the route-keyed crash isolation.
  Routes keep their literal `/hrms/{section}` paths and gates — preserve both when adding a page and
  add it to `HRMS_NAV`; `tests/Feature/HrmsNavTest.php` asserts the TMS shell offers no `/hrms` entry,
  HRMS sections derive from the hub catalog, My HR lives in the HR shell only, no rail element/file
  remains, route↔tab parity and per-tab gate equality.
- **Never submit a masked value back, and never build a picker from a report list.**
  `EmployeeEditModal` omits the whole personal block when the record arrived `restricted`, because
  prefilling it from masked values and posting them would store `p***@***` as somebody's real address.
  The `managers` option list is **everyone**, not only people who already have a report: a reports-only
  list cannot seed a reporting line at all, since a new hire reporting to a first-time manager is an
  ordinary assignment and the select comes back empty for exactly that case.
- **A `color()` in this app is a hex, never a Tailwind palette name.** The status pills render
  `backgroundColor: \`${color}22\``, so a palette name silently yields an invalid colour and every
  status looks identical. `EmployeeStatus::color()` returned `emerald`/`sky`/… and was fixed in
  P2.6; `config/task_statuses.php` and `config/priorities.php` already store hex. Wire-tested.
  `WorkMode`, `ApprovalStatus` and `ApprovalStepStatus` carried the same palette names with **no
  consumer yet** — which is the only reason nobody had hit it, and the reason the next person to
  render a work-mode or approval pill would have. All three now return hex, and
  `tests/Unit/Hrms/HrmsEnumColorTest.php` reflects over `app/Enums/Hrms/` to pin the convention for
  every current and future enum. It needs no application boot, so it locates the directory relative
  to the test file rather than calling `app_path()`, and it guards `method_exists` before building a
  `ReflectionMethod` (a missing method throws, and `DataAccessAction` has no `color()`).
- **P3.2 splits the plan's single `OrgService.php` into `app/Services/Hrms/Org/`.** The literal path
  is superseded by D2.16.2 (P1.9 precedent): `DepartmentTree` (cycle check, descendants, path),
  `DepartmentChart` (the nested read model with headcounts), `DepartmentService`, `DesignationService`,
  `LocationService`, `OrgNaming`. Three features follow `parent_id` in both directions, so the walk is
  stated once. `Department::descendants()` became `DepartmentTree::descendantsOf()`; the plan's
  `buildTree()` is `DepartmentChart::build()` — split out separately because guards + nesting in one
  file breached the plan's own 300-line ceiling. `tests/Feature/HrmsOrgServiceTest.php` is the guard
  (37 tests).
- **A subtree headcount starts at `direct_count`, never at 0.** `nest()` accumulates upward, so a
  zero seed reports every parent-only department as 0 staff. `headcount` (subtree) and `direct_count`
  (this node) are separate keys because they are routinely confused.
- **A cycle reader must prune, not re-emit.** `assertCanReparent()` stops new cycles, but imported org
  data can arrive tangled, so `nest()` returns null for a node already on the branch and `build()`
  surfaces unreachable rows (dangling parent, cycle) at the top level instead of dropping them.
  A department that renders nowhere is silent data loss.
- **`is_geo_fenced` is derived from the geometry, never stored as sent** — `applyGeofence()` writes
  `$wantsFence && $location->hasFenceGeometry()`. Setting the flag by asking `isGeoFenced()` is
  **circular** (it reads that very column) and silently stored `false`; the split into
  `hasFenceGeometry()` (stored lat/lng/radius) vs `isGeoFenced()` (flag *and* geometry) is what makes
  the write checkable.
- **A reorder is intersected with the real siblings first** (`OrgNaming::order()`): a reorder names the
  members of one list, and honouring a foreign id lets a payload drag a row out of a *different*
  parent. Omitted siblings keep their relative order at the end.
- **`hrms.org.manage` does NOT imply `hrms.org.view`.** `OrgRecordPolicy` checks the two independently
  and the three subclasses (`DepartmentPolicy`/`DesignationPolicy`/`LocationPolicy`) exist purely so
  Laravel's convention-based discovery binds a *named* policy to each model. A manager-only role gets a
  403 on every show, which is deliberate: `manage` is granted to somebody asked to fix a tenant's org
  chart, and letting it imply reads would hand that person every employee's team. `HrmsOrgApiTest` pins
  both directions.
- **Declare each `reorder` route before its own `{resource}` sibling.** `hrms/departments/reorder`
  declared after `hrms/departments/{department}` binds the literal string "reorder" as a department id
  and 404s a perfectly valid drag-and-drop.
- **A field-shaped rule is keyed to its field, not to `form`.** `head_employee_id` → the head picker
  shows the error; a structural rule (a cycle, a record in use) has no one field to blame and uses
  `form`. Getting this backwards makes a client attach a banner instead of the field's own message.
- **P3.1's org FKs were unreachable until P3.3** — the columns existed and P3.2 read them, but no
  employee *write* path accepted them, so only a seeder or raw SQL could fill them. They now live in
  `EmployeeProfile::WRITABLE`, `Employee::$fillable` + casts, `EmployeeRequestBase::orgRules()` (split
  out of `profileRules()` to stay under the 40-line method ceiling) and the audit snapshot, with
  `department()`/`designation()`/`location()` as the inverse of the Org models' `employees()`.
  `EmployeePresenter` emits the three as **scalars, not nested objects** — the org page already has
  every department from the single `GET api/hrms/org` response, so a per-row join buys nothing.
- **A directory filter on a department is an EXACT match, never a subtree** (`EmployeeDirectoryQuery::
  applyOrgFilters()`): "everyone in Engineering" and "everyone under Engineering" are different
  questions, and answering the second when asked the first understates the headcount badge the org
  page prints. The subtree walk belongs to the org context, the only place holding the tree.
- **`EmployeeDirectorySort` was extracted, not merged, when the class hit 303 lines.** The three org
  filters pushed `EmployeeDirectoryQuery` past the 300-line ceiling; trimming comments treats the
  symptom. The sort whitelist is a self-contained decision (the column list *and* the `ORDER BY` that
  consumes it), so it became its own class. `EmployeeFilterOptions` was then extracted for the same
  reason (the option *catalogues* are data, not a query) — that class is where `departments` /
  `designations` / `locations` live now, and `EmployeeDirectoryQuery` delegates. **The ceiling has been
  reached twice: do not add a fourth concern to either class.**
- **A filter needs its option lists from the same endpoint that offers the filter.** P3.3 taught the
  directory to *filter* by department and returned no departments to filter *by*; a select with no
  options silently sends no filter and the unfiltered list reads as a broken search — exactly the
  failure `Employees.jsx`'s docblock had documented when it omitted the filter. The org catalogues
  deliberately do **not** come from `GET api/hrms/org`: a reader with `hrms.employees.view` but no
  `hrms.org.view` may filter the directory and may not read the chart. Retired rows are included —
  a closed department is the one a reader filters by to see who is still in it.
- **The org page's "members" are employee records, not login accounts.** The plan's `users.view`
  picker is superseded: the panel is gated on `hrms.employees.view` and assigns on
  `hrms.employees.manage` **alone** (the write lands on the employee's record, which is what the API
  checks). Adding `hrms.org.manage` on top would give the UI a rule the server does not have.
- **The org starters are in `config/hrms.php`, and a config key that maps to no column sits
  unreported until the first thing that reads it.** P3.5's first run failed *every tenant's*
  provisioning with "table locations has no column named code": the catalogue had carried
  `'code' => 'head_office'` since P1.10, unconsumed, and `locations` is the one org table P3.1
  gave no `code` to. The starters are keyed on `slug`. The org tables have **no `is_system`
  column** either — what stops a referenced row being deleted is P3.2's service rule, and adding
  the flag would be a second, weaker answer to the same question.
- **The `document_types` catalogue had the same defect, and
  `tests/Feature/HrmsCatalogTest.php` is the gate that now catches it.** It named `code`,
  `is_confidential` and `validity_months` — no such columns — while omitting `category`, the one
  column the compliance story is built on. The blast radius is identical, because the first
  consumer is a `seed*()` step inside `HrmsDefaultsProvisioner::provision()`: the error surfaces as **every
  tenant in the fleet fails to provision**, not as "the document catalogue is wrong". The guard
  asserts both directions (catalogue key → real column, required column → catalogue key) across the
  catalogues that are seeded today. Two details it depends on, each silent when wrong: the
  `default`-key dual above, and the fact that **three seeders derive `slug` themselves**
  (`employment_types` from `Str::slug($name)`, `departments`/`designations` from `code`), so those
  are exempted *by name with the derivation recorded* rather than exempting the `slug` column
  wholesale — which would let a future `slug` with no derivation behind it pass. `document_types`
  joined the seeded list with the P13.2 service, when `seedDocumentTypes()` first existed. `validity_months` was **dropped, not mapped** to `retention_months` — how long a
  passport stays valid is a fact about one row (`employee_documents.expires_at`), retention is how
  long the tenant keeps the bytes;   mapping one onto the other writes a fabricated policy into every
  tenant.
- **P4.1’s sketch omits `document_requests.document_id`, and the column ships
  anyway.** The phase’s own prerequisite note references “a null
  `document_id`”, and without it there is no link between a request and its
  fulfillment — the hinge story collapses to “HR asked, something happened
  somewhere”. Nullable FK to `employee_documents`, nullOnDelete: a deleted
  file orphans the request (“asked, file gone”) instead of un-asking it.
  Likewise case-level `category`/`owner_scope` are plain-string snapshots of
  the template enums (an enum on the snapshot holds new catalogue values
  hostage to a migration on old rows), and `asset_id`/`expense_claim_id` are
  bare nullable integers until P14/P8 exist to constrain them.
- **P4.2 splits the plan’s two services into a `Lifecycle/` bounded context.**
  `OnboardingService` orchestrates `OnboardingTemplates`, `OnboardingCases`
  and `CaseProgress`; `OffboardingService` orchestrates `OffboardingChecklist`;
  both share `DocumentRequestService` (the request state machine both case
  types raise from). `CaseProgress` owns the mandatory definition *and* the
  photograph, so waiving and closing cannot disagree. Mandatory is derived
  from the template link, never stored: ad-hoc items are never mandatory,
  and `removeTemplateTask` refuses while an open case references the item so
  a deleted row cannot quietly unblock a case. Clearance counters are
  task-driven until P14/P6/P8 wire real records (leave/expense read an honest
  0); `clear()` 422s naming the blockers, `complete()` requires the signed
  clearance. `terminate()` auto-initiates an exit run when none is open
  (last working day = stamped exit date); initiating stamps `exit_date` but
  never touches status, so the two cannot loop.
- **P4.3 splits “may this caller open this row” from “which rows may the list contain”.**
  `Policies/Hrms/Lifecycle/` holds the per-record bases (`OnboardingPolicy`,
  `OffboardingPolicy`, `DocumentRequestPolicy`) plus empty marker subclasses
  for convention discovery — one shared class would be discoverable by none
  of its models (the Org precedent). Set-level scoping lives in the services’
  `casesFor`/`indexFor`, reading the same permissions. Nested task routes
  declare both models and verify belonging (a foreign task id 404s). Free
  document asks attach to onboarding cases only; an offboarding ask is raised
  by the exit run itself. The nested `tasks` rules live on the template
  request — without them `validated()` silently drops the checklist and the
  form lies about succeeding.
- **P4.5 notifies asymmetrically by design.** The owner always hears about
  their own item; manage-holders additionally hear about hr-scoped items (a
  pool nobody nudges is a pile nobody works). Creation nudges only items due
  within 7 days; the daily `hrms:onboarding-reminders` covers the rest, at
  most one nudge per item per week. Dedup uses the portable
  `where('data->case_task_id', …)` selector — pluck with a JSON path is not
  portable, so the test maps rows in PHP. The deep link lands on the case
  detail, not the plan’s list-with-param (the list takes no case parameter);
  the JS branches are pinned by string in the shell test since there is no
  JS runner.
- **P5.1 adds `employees.shift_id` with raw SQL, not `constrained()`.** P3.1 proved
  why: `Schema::table()` + `constrained()` rebuilds the table through a `__temp__`
  copy on SQLite, and doctrine introspection drops the column-level CHECKs — the
  test fast-path ended with a weaker schema than production. The raw
  `ADD COLUMN … REFERENCES`, grammar-quoted (never backticks: PostgreSQL 42601s
  those), touches nothing else; the index is separate, and `down()` drops the
  index before the column. `HrmsAttendanceTablesTest` asserts the CHECKs survive
  on both grammars, and the PG path confirms both `employees_*_check` constraints
  intact. Small sketch gaps closed the same way: `lng` mirrors `lat`,
  timestamps everywhere, `approval_id` a real FK to the P1 engine.
- **P5.2 splits the plan’s `AttendanceService` three ways** (`PunchClock`,
  `DayComputation`, `DayReading` under `Services/Hrms/Attendance/`) — the
  punch window is asymmetric (in bounded both sides, out from below only, so
  overtime never 422s), night punches attribute by owning date with a fixed
  noon cutoff, and out-of-range is a flag for IPs and geofences alike, never
  a refusal. Two Carbon-3 facts re-learned: diffs are signed floats (every
  pairing/lateness diff is `abs()`-bounded and int-cast), and the `date`
  cast serializes with a time part on SQLite — so every date lookup is
  `whereDate`, never exact-string `where` or a `Y-m-d` `whereBetween` (which
  misses rows on the fast path while PostgreSQL coerces fine).
- **P5.3 gates by module *or* ownership, never both at once.** The punch
  endpoint takes no permission: it resolves the employment record from the
  login, so there is nothing to authorize against and no login may punch for
  another. The 403 is a *module* 403 from `hrms.attendance.remote`. Settings
  take `hrms.attendance.settings` and merge per section. The IP is
  server-observed (`$request->ip()`), never client-claimed — a `meta.ip`
  would let anyone assert they are at the office.
- **P5.4 splits “may this caller open this row” from “which rows may the list contain”.**
  `Policies/Hrms/Attendance/` holds the per-record policy (self-service
  included) while `Services/Hrms/Attendance/RegularizationDirectory` owns the
  set-level scope — the P4.3/P13.3 shape, one context over. Corrections
  **supersede, never edit**: approval inserts `regularized`-source punches via
  `AttendanceService::applyRegularization()` and recomputes the day, so the
  row always equals its inputs. The manager step resolves through
  `ReportingLine::managerOf()` (the Attendance context never reads the
  employee graph directly); no resolvable manager → the engine auto-approves
  and the correction applies in the same transaction, audited, never stuck.
  The 300-line ceiling forced the split: `RegularizationService` (lifecycle) +
  `RegularizationDirectory` (reads) + `RegularizationNotifier` (event names,
  payloads, skip-self) + `RegularizationInput` (the boundary DTO) +
  `RegularizationPresenter`. Notifications are backend-only — the JS
  describe/link branches land with the P5.6 pages.
- **P5.5 rolls up through one loop, not two.** `AttendanceService::rollup()`
  ensures a day row per `active` employee (exited records are history nobody
  asked to revisit) with no audit rows — idempotent maintenance is not a
  decision, and a per-employee-per-night ledger would bury the decisions.
  The `HrmsAttendanceRollup` command adds the house `--tenant XOR --all`
  scope, a strict date that refuses the future, and a `--dry-run`; the
  `AttendanceRollupJob` carries the central tenant id and wraps its own
  `using()` (proven from the `iso_system` connection), `tries = 1` because
  re-running is the repair path.
- **P4.4 shares one checklist and gates detail pages by module, not permission.**
  `components/hrms/Checklist.jsx` serves both phases (separate `canAct`/
  `canWaive` — offboarding has no waive endpoint); the waive reason comes
  from `window.prompt`, and the backend 422s an empty one either way. Case
  detail routes carry no permission gate so a hire can open their own run —
  the backend policy decides whose case it is; list pages stay view-gated.
  `LifecyclePresenter::person()` carries `user_id` so the client can answer
  “is this me”. Pending asks carry an inline upload-then-submit flow, and the
  exit detail leads with the clearance panel because a blocked exit must read
  as blocked at a glance.
- **P13.2 keeps the plan's `Services/Hrms/DocumentService.php` path, but not as a monolith.** Upload
  rules, lifecycle, download serving and presentation together exceeded the 300-line class ceiling, so
  the service orchestrates `app/Services/Hrms/Document/` (`DocumentUpload`, `DocumentLifecycle`,
  `DocumentDownload`, `DocumentPresenter`) — the P3.2 `OrgService` split, applied to documents. The
  signed URL carries `actor` as well as `tenant`: a session-free request has no other identity source
  for the confidential `hrms.documents.view_sensitive` check and access row. Audit snapshots identify
  the row by employee/type/status and omit the title, which can itself be sensitive.
- **P13.3 splits “may this caller open this row” from “which rows may the list contain”.**
  `Policies/Hrms/Document/EmployeeDocumentPolicy` owns the per-record answers (self-service included);
  the new `Services/Hrms/Document/DocumentDirectoryQuery` owns the set-level one, and both read the
  same three permissions so they cannot disagree. A confidential row is invisible — not merely
  unreadable — to a reader without the sensitive permission, because a list that names it leaks its
  existence. `expiring` is declared before `{document}` (the P3.3 `reorder` lesson), and list filters
  are validated inline so P13.3 ships exactly the two request classes the plan names. A confidential
  *show* writes an access row like the download does: the title and original name can name the
  condition or the account.
- **The document SPA shares one row component, and P13.4 fixed the dead
  `permission:` gates.** `can('permission:…')` never matched anything because
  `hasAccess` compared it verbatim against bare slugs — every HRMS manage
  button, picker and nav item gated that way never rendered for tenant users.
  One strip in `hasAccess` fixed all call sites (bare slugs still match).
  `Documents.jsx` (store + expiring panel + bulk verify), `MyDocuments.jsx`
  (self-scoped, no directory permission), and the profile `documents` tab all
  render `components/hrms/DocumentList.jsx`; `DocumentUploader.jsx` takes the
  employee id as a prop and only renders a picker when given a list. `mine`
  returns `employee_id` (no “my employee” endpoint exists) and `present()`
  names the owner, so neither page needs a second lookup.
- **The starters invent no facts, and a tenant's starter name is not up for grabs.** No
  `parent_id`/`head_employee_id`/`country`/`timezone`; the numeric `designations.level` **is** set.
  `HrmsOrgApiTest::test_a_seeded_department_name_is_not_up_for_grabs` pins that creating a second
  "Engineering" cannot re-point the starter's slug.
- **Seeding org data changes the fixture every org test runs against (20 broke).** Raw-insert
  fixtures in `HrmsOrgTablesTest` must not reuse a seeded natural key — a second `'manager'`
  designation makes the free-text backfill link to the *seeded* row, so the assertion passes for
  the wrong reason. `$tree[0]` is "whichever row sorts first", not "the one I just made" (some
  tests passed only because their fixtures defaulted to `position => 0`); locate by id. And a
  whole-tenant exact-list assertion is only true while the tenant starts empty.
- **`EmployeeDirectoryQuery::orgOptions()` was renamed** — it now lives in `EmployeeFilterOptions` as
  `orgCatalogues()` (private); `filterOptions()` and `managerOptions()` stay public on the query so
  `EmployeeService` and `EmployeeController` are unchanged.
- **`DepartmentService::update()` is a transaction** because the re-parent is validated *after* the
  scalars are applied — otherwise a rename + cyclic parent commits the rename and then 422s.
- **`hrms:backfill-employees` is a data migration, and it infers no facts.** P2.7: `EmployeeBackfill`
  creates an `active` employee for every login that lacks one (code `EMP-{user_id}`, default user
  first, one transaction per user). It deliberately does **not** go through `EmployeeService::create()`,
  which applies the `employees` plan quota — a tenant on a 25-employee plan could not backfill its own
  30 logins — and does not set `joining_date` (from `users.created_at` it is a guess that moves payslip
  proration), `employment_type_id` ("full-time is common" is a guess payroll reads) or `personal_email`
  (deliberately a different address from the login). Plan limits are **reported** in the summary table,
  never enforced. `EmployeeCodeGenerator::retrying()` takes an optional preferred code for callers whose
  code is derived rather than allocated: `EMP-{user_id}` cannot be re-derived, so a collision with a
  service-created employee degrades to an allocated code instead of aborting the run.
- **`HrmsDefaultsProvisioner::provision()` guards each step individually.** A single "the `hrms_settings` row exists,
  nothing to do" early return meant a tenant provisioned before a catalog was added to that list could
  never receive it, since it short-circuited the whole method — and `tenants:provision` is the repair
  path. Adding a catalog to the config means adding its own guarded step, never wrapping the others in
  one.
- **The HRMS starter-data seeding lives in `app/Services/Hrms/Defaults/HrmsDefaultsProvisioner.php`,
  not in `TenantProvisioner`.** It was moved there after P13.1: `TenantProvisioner` had reached 368
  physical lines against the plan's 300-line class ceiling, and `document_types` (the next catalogue)
  would have pushed it further over. The concern is cohesive — insert-only starter rows a tenant may
  then rename — and the move is folder-per-context (D2.16.2) like `Employee/` and `Org/`. The seam is
  one line in `TenantProvisioner::seed()`: `app(HrmsDefaultsProvisioner::class)->provision()`. That is
  the place the next catalogue goes.
- **A refactor commit that also edits behaviour is a refactor nobody can review.** The extraction moved
  the seven methods byte-for-byte; the only deltas were `provisionHrmsDefaults()` becoming the public
  `provision()` entry point and the new class closing brace. Verified by diffing the moved block against
  the pre-commit file, not by reading it.
- **`TenantLimits::currentCount()` counts `employees` via `Employee::count()`** — omitting the mapping
  made `assertQuota('employees')` a silent no-op, since an unknown resource counts 0.
- **`employees.manager_id` is `nullOnDelete`, deliberately not `cascadeOnDelete()`.** Unlike
  `tasks.parent_id` (which cascades), cascade here would delete an entire department when one manager
  is removed. `employee_status_history` is the opposite case: it cascades with its employee, because a
  history row has no meaning without the record, and it `nullOnDelete`s `actor_user_id` so the trail
  outlives a deleted account.
- `TenantDatabaseManager::migrateTenant()` runs **pending-only**, so a new tenant migration reaches all
  102 live dev tenants on the next `php artisan tenants:provision` — and a migration that must backfill
  existing tenants does the backfill inline, repair-safe (`Schema::hasColumn`, `DROP INDEX IF EXISTS`,
  `whereNull` guards).
- Every HRMS file download (documents, payslips, letters, receipts) uses the **signed tenant-scoped**
  pattern documented in the Collaboration section: the central tenant id rides inside the signature, the
  route sits outside `switch_tenant`, and the controller resolves `Tenant::find($request->query('tenant'))`
  + streams inside `TenantDatabaseManager::using()`. Write the `flushSession()` +
  `DB::setDefaultConnection('iso_system')` regression test with it.
- Payroll statutory (PF/ESI/PT/TDS/LWF) is a **separate high-risk phase**, jurisdiction-configured per
  tenant region with no hard-coded thresholds, unit-tested against a fixture corpus, and snapped onto
  each payslip so a config change can never rewrite a locked one.
- Performance integrates with tasks via `ScopesVisibleTasks::visibleTaskQuery()` as **evidence only** —
  automated signals are shown to the reviewer and never converted into a rating or score.
