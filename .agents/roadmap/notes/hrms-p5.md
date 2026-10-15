# Track notes: HRMS P5 completion slices (branch `track/hrms-p5`)

Tasks: **P5.1** shifts catalogue, **P5.2** rosters & rotations, **P5.5** bulk CSV import + bulk
status, **P5.9** leave completion (rollover/adjustments/blackouts), **P5.12** document versioning,
**P5.15** geofence block-vs-flag + break punches, **P5.16** holiday CSV/ICS import + asset
replacement state. Merged as `8dcc438` ("Merge track/hrms-p5 (...)"); the 7 track commits
`91f5605, e1d3e37, 6c8952e, f6fd9a9, 9756656, f0e829d, 07f50d8` are in main via the merge (clean,
no conflicts). Tests were written but not executed at merge time; the first sandbox runs of the HRMS
P5 suites found that several **403-assertion tests posted empty/invalid bodies and got a 422** —
validation fires before `authorize()` — fixed in main commit `80826be` by sending valid payloads.
Final sandbox runs, all green: `HrmsShiftApiTest` 7, `HrmsRosterApiTest` 6, `HrmsEmployeeBulkTest`
7, `HrmsHolidayImportTest` 4, `HrmsAssetReplacementTest` 4, `HrmsDocumentVersionTest` 5,
`HrmsPunchPolicyTest` 6, `HrmsLeaveCompletionTest` 148-line suite.

## What shipped

- **P5.1 shifts catalogue:** `attendance_shifts` (+ `shift_patterns` starter catalogue in
  `config/hrms.php`, `ShiftPatternSeeder`), CRUD via `ShiftController` behind
  `ensure_module:hrms.shifts` + `permission:hrms.view` (schema from `AttendanceShiftPolicy`);
  `POST hrms/shifts/{shift}/assign` (per-user default) + `POST hrms/shifts/default/clear`.
  Migration `000101_add_catalog_columns_to_attendance_shifts` brings the catalogue columns.
  SPA `ShiftCatalog.jsx` + `/hrms/shifts` page + `hrms.shifts` nav item + overview tile
  (`hrms.shifts` is no longer "reserved" — the module key now ships, `hrms.talent` stays reserved).
- **P5.2 rosters & rotations:** `attendance_rotations` (cycle `[{day:{start,end,break}]`, `night`,
  weekly offs) + `roster_assignments`; `RosterService` (weekly + per-employee rosters, `mine`) &
  `RotationService` (+ `apply`, materialising a week). Migration `000102_create_attendance_rotations`.
  Endpoints nested under `hrms/shifts/…` (`rosters`/`rosters/mine`/`rotations`/`apply`); SPA
  `RosterPanel.jsx`/`RotationPanel.jsx`. Literals (`mine`, `default/clear`) declared before
  `{roster}`/`{shift}` siblings (the P3.3 lesson).
- **P5.5 bulk employee operations:** `EmployeeImporter`
  (validate→preview→commit, quota-aware via `TenantLimits`, audited) + `EmployeeBulkStatus`
  (bulk status change) under `app/Services/Hrms/Employee/Bulk/`. Routes
  `hrms/employees/import/{sample,preview}` + `POST hrms/employees/import` (throttled) +
  `POST hrms/employees/bulk-status` via `EmployeeBulkController`; SPA `EmployeeBulkModal.jsx`
  opened from the directory.
- **P5.9 leave completion:** `LeaveYearRollover` (carry-forward/lapse, config-driven cap/expiry,
  `hrms:leave-rollover --all` daily 04:50 + manual `POST hrms/leave/rollover`), `LeaveManualAdjustment`
  (ledger row + balance correction, audited: `GET hrms/leave/ledger`, `POST hrms/leave/adjustments`),
  `LeaveBlackoutService` (blocked ask days: `hrms/leave/blackouts` CRUD). All `hrms.leave`-gated;
  writes need `hrms.leave.manage`. Migration `000103_create_leave_blackouts`. SPA `LeaveAdjustPanel`
  + `LeaveBlackoutPanel` on the Leave page.
- **P5.12 document versioning:** `employee_documents.version_number` + `document_versions`
  (SHA-256 per version, prior bytes kept); `DocumentVersioning` (`store` bumps, keeps history);
  `POST|GET hrms/documents/{document}/versions` (throttle 30/min) via `DocumentVersionController`;
  migration `000104_add_versioning_to_employee_documents`. SPA `DocumentVersionsModal.jsx` in
  `DocumentList.jsx`.
- **P5.15 geofence block-vs-flag + break punches:** `attendance.settings.out_of_range_action`
  (`flag` default = record for review, `block` = refuse the punch) — `PunchRangeCheck` replaces the
  old always-flag logic; `attendance_punches.kind = break_start|break_end` for unpaid-break punches
  (`PunchPairing` pairs them; migration `000105_add_kind_to_attendance_punches`). SPA `ClockInWidget`
  behaves by the configured action and ships coordinates. Test guard `HrmsPunchPolicyTest`.
- **P5.16 holiday CSV/ICS import + asset replacement:** `HolidayImporter`
  (`hrms/holidays/calendars/{calendar}/import[/preview]` throttled, sample route; SPA
  `HolidayImportModal.jsx`) + `AssetReplacement` (`POST hrms/assets/{asset}/replace`, exits-gate-aware,
  SPA `AssetReplaceModal.jsx`; migration `000106_add_replacement_to_assets`); `hrms:assets-overdue`
  nudges unchanged.
- **Shared:** `App\Support\Hrms\CsvTable` (tenant-upload CSV parsing) used by both importers.
- **SPA glue:** `App.jsx` gained `/hrms/shifts`; `hrmsModules.js` `HRMS_MODULE_ROUTES` gained
  `hrms.shifts` (drift-guarded by `HrmsShellTest`); overview tiles for the two new surfaces.

## Files changed (merge diff)

107 files +6384/−152. 6 tenant migrations (`database/migrations/tenant*/2026_11_01_00010{1..6}`),
new `routes/hrms_p5.php` (required from `routes/web.php` inside the tenant HRMS stack), controllers
under `app/Http/Controllers/Hrms/{Asset,DocumentVersion,Employee/Bulk,Holiday,Leave,Shift}/`,
services under `app/Services/Hrms/{Asset,Document/Bulk→DocumentVersioning,Employee/Bulk,Holiday,
Leave,Shift}/`, SPA pages/components listed above, tests `HrmsShiftApiTest`, `HrmsRosterApiTest`,
`HrmsEmployeeBulkTest`, `HrmsLeaveCompletionTest`, `HrmsDocumentVersionTest`, `HrmsPunchPolicyTest`,
`HrmsHolidayImportTest`, `HrmsAssetReplacementTest` + `tests/Feature/Concerns/HrmsP5Helpers.php`
(shared fixtures; `HrmsAttendanceTablesTest` + `HrmsShellTest` got small asserts/drift updates).
Post-merge fix (patch to `80826be`): valid bodies in the 403-assertion tests (shift/roster/bulk/
holiday payloads), `HrmsShiftApiTest::makeEmployee`-based ids.

## AGENTS.md / roadmap deltas applied by the integrator

- New AGENTS.md section `## HRMS P5 completion slices (track/hrms-p5)` (before Pitfalls); module-gate
  lines updated (`hrms.shifts` now ships, `hrms.talent` stays reserved); `## Commands` test line
  gains the eight P5 focused test files.
- `master-roadmap.md`: progress line 484 adds `P5.1/5.2/5.5/5.9/5.12/5.15/5.16 ✅ (track/hrms-p5
  merged)`; §8 gap list trims shifts/bulk import/leave rollover/doc versioning from the "HRMS
  staples" gap.