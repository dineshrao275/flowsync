# HRMS Time group — UI test results (tabs 10–18)

Environment: local Docker stack, tenant **globex**, Playwright (chromium, headless). Personas: `owner@globex.test` (admin),
`qa.employee@globex.test` (Employee), `qa.hrexec@globex.test` (HR Executive). Only records prefixed `QA-TM` were created.
Run: `npx playwright test hrms-1N --output=tests/e2e/.results-time` (own output dir; other suites share `.results`).

## Final whole-group run (tabs 10–18 together, `npx playwright test "hrms-1[0-8]-"`)

44 tests: **39 passed, 5 skipped, 0 unexpected failures** (5.6 min). The expected-failure test pinning BUG-T6 is counted as passed.
The **5 skips are not verified in this run**: tab 10 "older than 7 days" and "auto-approved correction", tab 11 "manager sees times", "reject needs a note", "approve applies".
They skip themselves because the QA Employee has no still-Absent day left in the 7-day correction window (earlier runs consumed them); they passed on earlier runs
(tab 10 BUG-T1 test passed after the cross-month fix; tab 11's four tests passed before the date rolled over). They become runnable again as dates advance.

## Status per tab (only what was executed and verified)

| # | Tab | Status | Tests |
|---|---|---|---|
| 10 | `/hrms/attendance` | Pass (2 tests skipped in the final run) | 9 tests: Employee view + no admin controls, admin no-employment-record message, admin controls + other employee, settings validation + persistence, correction validation, 7-day window message (BUG-T1), auto-approved correction (skips when no Absent day is left in the 7-day window), clock in/out (or duplicate-press guard), HR Executive controls |
| 11 | `/hrms/attendance/approvals` | Pass earlier; 3 of 4 tests skipped in the final run | 4 tests: Employee refused (names permission), manager sees real clock times (BUG-T2), reject needs a note + Rejected filter, approve applies to the employee calendar |
| 12 | `/hrms/leave` | Pass | 9 tests: Employee refused, leave types CRUD (+validation), system type undeletable, policies CRUD (BUG-T3), blackouts CRUD + validation, adjustments (credit + debit), requests (over-balance, filer cannot decide, employee cannot withdraw HR-filed ask), balances, exemptions empty filing |
| 13 | `/hrms/leave/mine` | Pass | 3 tests: balances/history/no admin controls; validation, over-balance, overlap, own withdraw; blackout blocks the ask |
| 14 | `/hrms/comp-off` | Pass | 3 tests: Employee refused, grant (validation + bank), run-accrual confirm/cancel |
| 15 | `/hrms/comp-off/mine` | Pass | 3 tests: bank + no admin controls; required/bad range/over-bank refused; **valid redemption** (credit granted through the Grant-time form, ask filed, employee withdraws it, a second ask is approved by an admin override with a note, history shows Cancelled + Approved, bank drops by 8h). Dates vary per run because a grant per work date and a redemption per day are unique |
| 16 | `/hrms/holidays` | Pass | 4 tests: Employee has no entry + typed URL refused naming permission (BUG-T4), HR Executive can read (BUG-T5b), calendar CRUD, holiday CRUD |
| 17 | `/hrms/shift-swaps` | Pass (BUG-T6 pinned) | 4 tests: page loads; **colleague picker for an Employee is empty (BUG-T6, expected-failure test)**; HR Executive validation (send disabled, past date, same-shift rule); **full flow** between two rostered QA people: requester withdraws, counterparty must give a note to reject and rejects, counterparty approves and the swap shows Done (roster applied) |
| 18 | `/hrms/shifts` | Pass | 5 tests: Employee sees roster only; catalogue CRUD; roster assign + Employee read-only view + Remove; rotations create/validate/delete; **rotation Apply** writes roster rows for the chosen QA employee only, removable from the roster |

## Confirmed bugs (fixed)

| ID | Tab | Role | Operation | Expected | Actual | Root cause | Fix (files) | Retest |
|---|---|---|---|---|---|---|---|---|
| BUG-T1 | 10 attendance | Employee | Request correction older than 7 days | The rule is shown | Modal stays open, nothing shown | Server answers `work_date` error; modal only rendered time/reason errors | `resources/js/pages/hrms/Attendance.jsx` renders `work_date`/`form` errors in an Alert | Playwright "older than the 7-day window" passes |
| BUG-T1b | 10 attendance | Admin without employee record | Open page | Server's reason | Generic "Unable to load attendance." | `load()` discarded the 404 message | `Attendance.jsx` shows the server message on 404 | Playwright passes |
| BUG-T2 | 11 approvals | Manager | Review a correction | See the proposed clock times | "4 days ago → 4 days ago" | `Timestamp` renders relative time | `resources/js/pages/hrms/AttendanceApprovals.jsx` uses `formatTime` | Playwright passes |
| BUG-T3 | 12 leave | Admin | New leave type / New policy | Create form opens | Nothing happens; creating was impossible | Modal `open={!!editingType}` but "new" sets it to `null` | `resources/js/pages/hrms/Leave.jsx` separate `typeOpen`/`policyOpen` flags | Playwright types + policies CRUD pass |
| BUG-T4 | 16 holidays | Employee | Sidebar entry / typed URL | No entry; 403 naming the permission | Entry shown, page "Access denied" | Nav + route gated only on the module while `HolidayCalendarPolicy` needs `hrms.holidays.view` | `resources/js/utils/hrmsNav.js`, `resources/js/App.jsx` add the permission; `HrmsNavTest` still passes | Playwright passes |
| BUG-T5 | 16 holidays | HR Executive | Open page | Calendars visible | Whole page ejected to /403 | `loadPeople` 403 (assignments/optionals) went through the page-wide handler | `resources/js/pages/hrms/Holidays.jsx` degrades those two panels to empty on 403 | Playwright passes |

## Confirmed defect, NOT fixed (needs a decision)

- **BUG-T6 `/hrms/shift-swaps` (Employee):** the "swap with" picker is fed by `GET /hrms/employees`, which an Employee may not call (403), so the
  picker is empty and the page's main self-service action cannot be used. Fix needs a minimal colleague-directory endpoint (names only) —
  a privacy/product decision. Pinned by `test.fail()` in `hrms-17-shift-swaps.spec.js`; remove the annotation when fixed.
- **Time zone (cross-cutting):** correction times typed as 09:00 are stored in the tenant zone (UTC) and shown in the browser zone
  (02:30 PM in IST). Dates are tenant-zone, times are viewer-zone. Needs a product decision on one display zone.

## Valid business rules (documented, unchanged)

- Corrections are accepted up to 7 days back; an employee with no manager is auto-approved (audited), otherwise the manager decides.
- A second punch of the same kind within 5 minutes is refused ("looks like the same press recorded twice").
- Only the assigned approver decides a request; the filer never can, and only the requester can cancel (admin-filed asks cannot be withdrawn by the employee).
- Leave: balance is per leave year (an ask in 2027 uses the 2027 balance); overlapping asks are refused; blackouts block asks and show their reason.
- System leave types cannot be deleted; leave-type names are not unique by design (slug is server-allocated).
- Shift swap needs different shifts on the day, a date of today or later, and a different person.
- Shift code must match `[a-z0-9_-]+`.

## Improvements (separate from defects)

| Idea | Value | Priority |
|---|---|---|
| Hide **Withdraw** on My leave for asks the employee did not file | removes a button that is always refused | Medium |
| Tell the employee when a correction was auto-approved (toast says "manager will review") | accuracy | Low |
| Enforce unique leave-type names | avoids two identical entries in every picker | Medium |
| "Shift code format is invalid" should state the allowed format | usability | Low |
| Give Employees read access to holidays (own calendar view) | every employee needs to see holidays | High |
| Label the Balances employee picker (placeholder only) | accessibility | Medium |
| Show the shift-swap server error once, not inline and in the alert | polish | Low |
| Month grid marks every day "Absent" when no roster exists, including weekends and pre-joining days | trust in attendance data | High |
| Hide **Delete** for system leave types (always refused) | polish | Low |
| Roster "Remove" confirmation button is labelled "Delete" | consistent wording | Low |
| Rejecting a shift swap without a note shows the generic "The note field is required."; the friendlier service message is shadowed by request validation | clarity | Low |
| Shift swap list shows dates as `11/3/2026` while the rest of HRMS uses ISO dates | consistency | Low |
| Employee can still "Withdraw" an already-Approved comp-off redemption (button stays) — confirm this is intended | clarity | Medium |

## Blockers

- **Resolved:** the environment was fixed and the original `APP_KEY` restored; tab 18's roster test and everything after it then ran.
- **Earlier environment event, ~09:19 IST:** the container `APP_KEY` changed (`.env.docker` rewritten, stack recreated), so tenant DB credentials no longer decrypt and
  `/api/auth/login` returns 500 "The MAC is invalid". No sign-in is possible, so tab 18's roster test (`hrms-18-shifts.spec.js`, "roster:") and the
  successful-swap path of tab 17 could not be run. Keys, containers and the database were not touched.
- Earlier, shared login throttle ("Too Many Attempts") slowed suites; `helpers-time.js` `session()` now re-validates the saved session and retries sign-in with a backoff.

## Not verified (explicit)

- The 5 skipped tests above in the final run (data-dependent, see the final-run block).
- Swap *manager* step (a requester with a manager): only the counterparty step was exercised (the HR Executive has no manager).
- Tab 10 "auto-approved correction" skips when no still-Absent day is left in the 7-day window (verified on earlier runs).
- Exemptions beyond an empty filing; leave year-end rollover; export CSV on attendance; permissions for roles other than Employee / HR Executive / admin.

## Test data left behind (cannot be deleted through the UI/API)

- Comp-off credits for QA Employee (several 16h grants plus 1h) and one approved 8h redemption; withdrawn/cancelled redemptions; shift-swap records (Withdrawn, Rejected, Done) for QA HR Executive ↔ QA Employee; approved/rejected attendance corrections and leave asks as listed below. Roster rows, rotations, shifts, calendars, holidays, leave types/policies/blackouts created by the suite were removed by the suite.

- QA Employee: attendance punches for today, regularized days (several Oct days "Present · R"), a 60-min comp-off credit (2026-10-24),
  leave ledger adjustment rows (net zero), cancelled/withdrawn leave asks, approval records, QA Employee manager = QA HR Executive.

## Shared files changed (and why)

- `resources/js/pages/hrms/Attendance.jsx`, `AttendanceApprovals.jsx`, `Leave.jsx`, `Holidays.jsx` — the fixes above (page files in this group).
- `resources/js/App.jsx` — Holidays route now also requires `hrms.holidays.view` (comment corrected).
- `resources/js/utils/hrmsNav.js` — Holidays entry lists `permission:hrms.holidays.view`.
- `.gitignore` — added `tests/e2e/.results-time/`.
- New files (mine): `tests/e2e/helpers-time.js`, `tests/e2e/hrms-10…18-*.spec.js`, `docs/qa/results-time.md`. Local-only scratch (git-excluded): `tests/e2e/explore-time.mjs`, `probe-time.mjs`, `refresh-auth.mjs`.
- No UI primitives, `api.js`, `vite.config.js` or locale files were changed by this group.
