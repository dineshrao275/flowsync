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
- **FU-1 (was HIGH, BUG-PY-8):** expense approval steps now complete over HTTP and the UI (`approve-step` / `reject-step`, `can_act_step`, `HrmsExpenseApprovalStepTest`, Playwright `hrms-23b`); clearing a step now nudges the next approver.
- **BUG-T6 (second pass):** the Employee shift-swap picker uses a names-only colleague endpoint (`HrmsShiftSwapColleaguesTest`, Playwright `hrms-17`).
- **Chain approvers could not reach the Expenses queue:** any viewing scope (`view|view_assigned|view_all`) now opens it; gates accept `a|b|c` any-of (`permissions.test.js`, Playwright `hrms-23c`).
- **Payroll:** a payslip line written by a loan instalment or a paid claim could be deleted by hand, desyncing the loan balance or claim state; it is now refused and the page hides Remove (`HrmsPayrollRunApiTest`, `PayrollRunDetail.test.jsx`).
- **Notifications UI:** a claim number such as `EXP-2026-000002` was split into a task-key chip plus plain text (`HighlightedContent.test.jsx`).

## Permission and tenant-isolation findings

- Typed-URL gating works on every tab: restricted pages answer a 403 page naming the missing permission; matching APIs answer 403.
- Self-scoped data (documents, goals, payslips) never leaked across Employee / HR Executive in the specs; the one apparent leak (HR Executive seeing an Employee's development plan) was the manager relationship the rules allow.
- The only tenant-isolation defect found was the cache-key leak above (fixed). Isolation was proven at the PHP level; no second-tenant browser session was run.
- Validation runs before authorization on some write endpoints, so an unauthorized caller with an invalid body gets 422 instead of 403 (nothing is saved either way).

## Outstanding

- Open improvements recorded in `follow-ups.md`: FU-3 (a rejected statutory declaration gives no reason) and FU-4 (no audited reversal for a generated payslip line).
- Attendance correction times are stored in the tenant zone and shown in the browser zone.
- 53 confirm dialogs fall back to the generic "Delete" label; Careers removals have no confirmation.
- Dev tooling: `scripts/dev.sh` / `docker-compose.yml` changes and a rotated `APP_KEY` on 2026-10-31 broke all local logins until the original key was restored (not part of this work; see the conversation notes).

## Verified in the second pass (2026-11-02)

Payroll payout (bank) file: runner-only, approved-only, decrypted account + audited export, NEFT template, missing-bank count, formula-cell neutralisation (`HrmsPayrollBankFileTest`). Payroll adjustments and reopen: already pinned in `HrmsPayrollRunApiTest`. Loan deduction in a run: already pinned in `HrmsExpenseServiceTest` (deducted once, balance reduced, recalculation idempotent). Statutory declaration proofs: a foreign proof file is refused (`HrmsStatutoryConfigApiTest`). TDS shortfall surrender: pinned at API level (`HrmsTdsProjectionTest`, `HrmsStatutoryTdsApiTest`). Audit rows and the claimant's notification after the expense flow are visible in the UI (`hrms-48`). No raw translation key on any of the 47 pages in English or Spanish (`hrms-49`). A second tenant sees none of Globex's data and vice versa, in a real browser (`hrms-50`). An HR automation rule fires from the browser (`hrms-51`). A change request applying on its effective date: pinned in `HrmsEmployeeChangeTest` (the nightly command applies a future-dated change).

## Still not verified

Surrender and declaration review driven through the browser (needs a configured tax rulebook and salary data to produce a shortfall); loan instalments on a payroll run driven through the browser; the five Time tests that self-skip once the 7-day attendance window is used up; Spanish wording quality (the sweep checks for leaked keys, not translation quality, and the strings await native review).
