# HRMS P5 slices and F-phase additions

> Moved verbatim from `AGENTS.md`. Sections below keep their original headings.

## HRMS P5 completion slices (track/hrms-p5)
- **Shifts catalogue (P5.1):** `attendance_shifts` (+ `shift_patterns` starter catalogue in `config/
  hrms.php`, `ShiftPatternSeeder`), CRUD behind `ensure_module:hrms.shifts` + `permission:hrms.view`
  (`ShiftController`; schema from `AttendanceShiftPolicy`); `POST hrms/shifts/{shift}/assign` sets a
  shift as a user's default (or the tenant default via `POST hrms/shifts/default/clear`); SPA
  `ShiftCatalog.jsx` + `/hrms/shifts` page + `hrms.shifts` nav item + overview tile (module no longer
  "reserved").
- **Rosters & rotations (P5.2):** `attendance_rotations` (weekly/rotational templates: cycle `[{day:{start,end,break}]`, `night` flag, weekly offs) + `roster_assignments` on `attendance_rosters` (weekly + per-employee). `RosterService`/`RotationService` (+ `apply`, materialising a week of shifts); endpoints nested under `hrms/shifts/…` (`rosters`/`rosters/mine`/`rotations`); SPA `RosterPanel.jsx`/`RotationPanel.jsx` on the Shifts page.
- **Bulk employee CSV import + bulk status (P5.5):** `EmployeeImporter` (validate→preview→commit,
  quota-aware via `TenantLimits`, audited) + `EmployeeBulkStatus` (bulk status change). Routes
  `hrms/employees/import/{sample,preview}` + `POST hrms/employees/import` (throttled) +
  `POST hrms/employees/bulk-status`; SPA `EmployeeBulkModal.jsx` on the directory (`/hrms/employees`)
  with a status bulk-action.
- **Leave completion (P5.9):** `LeaveYearRollover` (carry-forward/lapse — config-driven cap/expiry per
  `config/hrms.php`, `hrms:leave-rollover --all` daily 04:50, `POST hrms/leave/rollover` manual run),
  `LeaveManualAdjustment` (post a ledger row + balance correction, audited; `hrms/leave/ledger`, `POST
  hrms/leave/adjustments`), `LeaveBlackoutService` (`hrms/leave/blackouts` CRUD — a blackout blocks
  asks on those days). All behind `ensure_module:hrms.leave`; writes need `hrms.leave.manage`. SPA:
  `LeaveAdjustPanel.jsx`/`LeaveBlackoutPanel.jsx` on the Leave page.
- **Document versioning (P5.12):** `employee_documents.version_number` + SHA-256 per version in
  `document_versions`; `DocumentVersioning` (`store` bumps the number, keeps prior bytes),
  `POST|GET hrms/documents/{document}/versions` (throttle 30/min); SPA `DocumentVersionsModal.jsx` in
  `DocumentList.jsx`.
- **Geofence block-vs-flag + break punches (P5.15):** `attendance.settings.out_of_range_action`
  (`flag` default = record for review, `block` = refuse punch) — `PunchRangeCheck` replaces the
  old always-flag logic; `attendance_punches.kind = break_start|break_end` allow unpaid-break punching
  (pairing rules for `kind` in `PunchPairing`). SPA: `ClockInWidget` switches on the action + coordinates.
- **Holiday CSV/ICS import + asset replacement (P5.16):** `HolidayImporter` (CSV or ICS feed →
  calendar rows, preview→commit, `hrms/holidays/calendars/{calendar}/import` throttled, `HolidayImportModal.jsx`); `AssetReplacement` (`POST hrms/assets/{asset}/replace` records a replacement state on the asset row, exits-gate-aware, `AssetReplaceModal.jsx`); `hrms:assets-overdue` nudges unchanged.
- Shared: `CsvTable` helper (`app/Support/Hrms/CsvTable.php`) for parsing tenant-uploaded CSVs;
  `resources/js/App.jsx` route additions for `/hrms/shifts` + the two new overview tiles.

## F-phase additions (branch `feat/f0-baseline`; plan: `.agents/roadmap/missing-functionality-phase-plan.md`)
- **Test infra:** `tests/IsolatesDatabase.php` provisions the acme+globex template **once per test process** and copies the sqlite files per test (a 24-test focused run went from ~12 min to ~1.5 min). Never run tests from a git worktree that symlinks `vendor/` (Composer resolves `App\` to the main checkout); copy the repo with `rsync` instead. Full-suite baseline notes: `.agents/roadmap/f0-baseline-report.md`.
- **Audit trace:** `App\Support\AuditTrace::columnsFor($ledger)` stamps `request_id` and (impersonated sessions) `impersonator_id` on `activities`, `hrms_audit_logs`, `hrms_data_access_logs`; queued jobs carry `request_id` in the payload and `SwitchesTenantConnectionForQueuedJobs` restores it (`RequestId::useForJob`). `POST /api/csp-report` collects CSP violations (`csp.violation` log lines); `audit:verify` runs daily and alerts on a broken chain; `hrms:leave-consistency [--repair]`.
- **Notification delivery controls:** `NotificationDispatcher` honours the tenant `notification_policies` (`GET|PUT notification-policies`, `tenant.manage`), the user's `email_mode` (`instant|digest`; digest items in `notification_digest_items`, sent by `notifications:send-digests` daily 07:30), a 60 s dedupe window and an hourly email throttle (overflow goes to the digest).
- **Custom fields (module `custom_fields`, perm `custom_fields.manage`):** `CustomFieldService` (definitions per entity `employee|task|project|asset|expense|document`, typed values with text/number/date shadow columns, `applyFilters`, `enabled()`), routes in `routes/web/platform_engines.php`; controllers opt in with `HandlesCustomFields` (validate before write, store after, `withCustomFields` on read; no-ops when the plan lacks the module). Wired: employees, tasks, projects (assets/expenses/documents are the next entities).
- **Task search:** saved filters (`saved_filters`, `projects/{p}/saved-filters`) and the JQL-style language (`jql`, `?jql=` on the task list; `app/Services/Jql/{JqlLexer,JqlParser,JqlCompiler,JqlSearch}` — fields are a whitelist, values are bound, ANDed onto the caller's row-scoped query so it can narrow but never widen). Views: board, list, **calendar** (`CalendarView`, drag to reschedule), **timeline** (`TimelineView`); project tab **Workload** (`WorkloadService`, `projects/{p}/workload`, module `sprints`); sprint **burnup** chart.
- **Time:** one running timer per person (a work log without `ended_at`; `time/timer`, `time/timer/stop`, `projects/{p}/tasks/{t}/timer/start`), `work_logs.billable`, weekly `timesheets` (`TimesheetService`: submit freezes that week's logs, deciders = tenant admins or a lead of a project with time on the sheet, never the owner; module `timesheets`). `TimerPill` in the Topbar; `/timesheets` page.
- **Payroll reopen:** `PayrollLifecycle::reopen` (approved/processing/paid → review, payslips back to draft, reason ≥10 chars audited; locked never reopens).
- **API:** `docs/openapi-v1.json` is the contract for `/api/v1` (`OpenApiContractTest` fails on drift); `RouteAuthAuditTest` fails when an `api/*` route is reachable without auth unless listed in its reviewed `PUBLIC` array.
- **Later F-slices (all written without running tests — verify at the end gate):** shift swaps (`ShiftSwapService`, `shift_swaps`), task SLAs (`SlaService`, `tasks:sla-sweep` hourly, `sla_policies`/`sla_breaches`), board types + issue scheme (`config/board_types.php`, `project_issue_types`, `ProjectIssueScheme`), approval-gated moves (`approval` entry rule → `TaskMoveRequest`, `MoveApprovals`), unified audit timeline (`GET audit/timeline`, `tenant.manage`), expense category limits (`max_per_line`, `monthly_limit`), bank payout file (`hrms/payroll/runs/{run}/bank-file`), loans (`LoanService`, deductions are `loan_installment` payslip adjustments), improvement plans, letters (`LetterService`, `config/letters.php`), record comments (`record_comments`, types `expense|leave`), role templates (`config/role_templates.php`), upload scanning (`App\Support\UploadScanner`, `security.upload_scan.*`), metrics (`GET api/metrics`, `METRICS_TOKEN`), PWA (`public/sw.js`, `manifest.webmanifest`), PostgreSQL ILIKE + trigram indexes (`Like::any`). New tenant migrations need `php artisan tenants:provision`. Further slices after the first full run: development plans/OKRs/skill expectations/careers (`hrms.performance` + `hrms.talent`), OIDC SSO (`App\Services\Sso\{OidcClient,SsoSettings}`, public `api/sso/{slug}/{start,callback,info}`, `tenants.settings.sso`, `Auth` enforce check in `AuthController::loginIsolated`), `tenants:purge`, e-signatures (`document_signatures`, public `api/signatures/verify/{code}`), tenant activity metrics, report builder (`saved_reports`, `reports:send-scheduled` hourly), GitHub link (`api/integrations/github/{tenant}`, HMAC), calendar feed (`api/calendar/{tenant}/{token}`), duplicates/clones link types, `BoardColumnsChanged`. **Dropped by the owner: hourly leave (F7.2).**
