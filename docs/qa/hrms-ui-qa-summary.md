# HRMS UI QA — consolidated summary (2026-10-30 to 2026-11-02)

Scope: all 47 HRMS sidebar entries (`resources/js/utils/hrmsNav.js`) plus the HR shell, driven through a real browser (Playwright, headless Chromium) against the **local Docker stack, tenant Globex**. Production was never touched. Per-tab rows are in `hrms-ui-checklist.md`; per-group detail, test commands, left-behind data and improvement ideas are in `results-{workforce,time,pay,performance,records,insight}.md`.

## Evidence

| Gate | Result |
|---|---|
| Full PHP suite (ParaTest) | 2,387 tests, 15,221 assertions, 0 failures (run before FU-1; FU-1 added 7 tests, run focused) |
| Pint / ESLint | clean / 0 errors (122 warnings, unchanged from baseline) |
| Vitest | 373 / 373 (91 files) |
| `npm run build` | passes |
| Playwright, all specs together (29.6 min) | 329 passed, 3 failed, 24 skipped, 10 did not run. The 3 failures were re-checked: tab 19 and tab 31 pass in isolation (load/contention); tab 20 was a race in the test's "run already exists" guard, fixed and re-run green. The only expected failure (`✘`) is the pinned BUG-T6 test. |

## Defects fixed (each with a failing-first regression test)

- **Cross-tenant data exposure (security):** analytics, My HR payload, inbox unread count and survey results were cached under keys without the tenant while user ids are tenant-local and the production cache is the shared system DB. Keys are now tenant-qualified (`HrmsCacheTenantKeyTest`, `HrmsAnalyticsApiTest`).
- **PostgreSQL-only failures hidden by the sqlite fast path:** headcount `ORDER BY` with `GROUP BY` (500), audit search `LIKE` built by hand (case/wildcard bugs).
- **Self-service access:** Employees could not read their own payslip, performance cycles or the expense catalogue, nor file statutory or expense claims.
- **Validation:** duplicate pay head codes, unchecked PAN/IFSC/UAN, saving one statutory id wiped the others, negative receipt threshold, duplicate expense category names.
- **Soft-delete bugs:** employee code allocation failed after any delete; onboarding/offboarding lists 500'd for a deleted employee.
- **UI crashes and dead ends:** approval-chain editor crash on close, Leave "New type/policy" did nothing, Overview module count, org-chart collapse, empty exit interview never reported an error, letter template deleted without confirmation, native `prompt()` used for declines.
- **Accessibility:** form fields without ids (label not tied to control), `Textarea` ignoring label/error, closed Theme drawer exposed to assistive tech, always-403 background requests.
- **FU-1 (was HIGH, BUG-PY-8):** expense approval steps now complete over HTTP and the UI (`approve-step` / `reject-step`, `can_act_step`, `HrmsExpenseApprovalStepTest`, Playwright `hrms-23b`).

## Permission and tenant-isolation findings

- Typed-URL gating works on every tab: restricted pages answer a 403 page naming the missing permission; matching APIs answer 403.
- Self-scoped data (documents, goals, payslips) never leaked across Employee / HR Executive in the specs; the one apparent leak (HR Executive seeing an Employee's development plan) was the manager relationship the rules allow.
- The only tenant-isolation defect found was the cache-key leak above (fixed). Isolation was proven at the PHP level; no second-tenant browser session was run.
- Validation runs before authorization on some write endpoints, so an unauthorized caller with an invalid body gets 422 instead of 403 (nothing is saved either way).

## Outstanding

- **BUG-T6 (open, decision recorded):** the Employee's "swap with" picker on Shift swaps is empty because it loads `GET /hrms/employees` (403 for Employees). Needs a names-only colleague endpoint, which is a privacy decision.
- A chain approver who lacks `hrms.expenses.view` cannot open the Expenses page (route gate); they still see the item in the Inbox.
- Attendance correction times are stored in the tenant zone and shown in the browser zone.
- 53 confirm dialogs fall back to the generic "Delete" label; Careers removals have no confirmation.
- Dev tooling: `scripts/dev.sh` / `docker-compose.yml` changes and a rotated `APP_KEY` on 2026-10-31 broke all local logins until the original key was restored (not part of this work; see the conversation notes).

## Not verified (honest coverage gaps)

Payroll bank file, adjustments and reopen; loan instalment deduction inside a payroll run; statutory proofs and shortfall surrender; notifications and audit-log rows in the UI for most tabs; a rule firing from the browser (covered in PHP only); change requests applied on their effective date; Spanish rendering of new strings in the browser (parity tests pass); five Time tests that self-skip once the 7-day attendance window is used up; a second-tenant browser session for isolation.

## Test data left in local Globex

`QA-*` records the product cannot delete: locked pay runs and salary revisions for QA Employee, a statutory profile, a loan, deactivated departments, onboarding templates, leave and comp-off adjustments, approved/rejected expense claims, development plans and OKRs. Personas: `qa.employee@globex.test`, `qa.hrexec@globex.test`, plus two Insight fixtures. Password for all: `password`. Local database only.
