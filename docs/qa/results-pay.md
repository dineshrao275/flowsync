# HRMS UI QA: Pay & benefits group

Scope: checklist rows 19-24 and 40. Tested through the real UI with Playwright against the local Docker app
(`http://localhost:8000`, tenant **globex**), as the tenant admin (`owner@globex.test`), QA Employee and QA HR Executive.
Never run against production. Test records are prefixed `QA-PY-` or attached to the QA personas.

## Tab results

| # | Tab | Result | Specs |
|---|---|---|---|
| 19 | `/hrms/compensation` | **Pass** after fixes. Pay head CRUD + validation, templates (create, link heads, in-use delete refused, name required), salary assignment, revision, roles | `hrms-19-compensation.spec.js` |
| 20 | `/hrms/payroll` | **Pass.** Validation, duplicate period refused, full lifecycle draft -> review -> approved -> processing -> paid -> locked on a fresh run, run detail, roles | `hrms-20-payroll.spec.js` |
| 21 | `/hrms/payroll/mine` | **Pass** after fix. List, detail, signed download, tampered link refused, cross-user isolation | `hrms-21-my-payslips.spec.js` |
| 22 | `/hrms/statutory` | **Pass** after fixes. Rulebook CRUD, identifier masking/reveal, claim file/submit/verify/reject, TDS projection message, recompute refusal, Employee self-service | `hrms-22-statutory.spec.js` |
| 23 | `/hrms/expenses` | **Partial.** Queue, filter, categories, reject path pass after fixes. **Approve path blocked** (BUG-PY-8, no way to resolve the approval chain) | `hrms-23-expenses.spec.js` |
| 24 | `/hrms/expenses/mine` | **Pass** after fixes. File, validation, receipt rule, submit, roles | `hrms-24-my-expenses.spec.js` |
| 40 | `/hrms/loans` | **Pass.** Validation, request, requester cannot decide own request, manager and admin chain, active state (withdraw and stop not exercised) | `hrms-40-loans.spec.js` |

## Final regression (all seven specs together, no retries, own output dir)

`npx playwright test hrms-19 hrms-20 hrms-21 hrms-22 hrms-23 hrms-24 hrms-40 --output=<own dir> --trace=off`

**47 passed, 12 skipped, 1 failed.** The skips are the idempotency guards (a locked run, an assigned template or a filed claim from an
earlier run is reused rather than recreated). The one failure, `hrms-19 ... a template needs a name`, timed out at the 1-minute limit while
other agents were hitting the single-threaded local server; it passed on its own twice straight afterwards, so it is classed as contention, not a defect.

Other gates: `HrmsCompensationApiTest` 11, `HrmsPayslipAccessTest` 10, `HrmsStatutoryProfileTest` 8, `HrmsExpenseApiTest` 7,
`HrmsCompensationTest` 8, `HrmsPayrollRunApiTest` 7, `HrmsExpenseServiceTest` 9 (all pass, Pint clean); the five `HrmsStatutory*` files pass; Vitest `Input` (4) and
`Statutory` (1) pass; `npm run lint` 0 errors, 122 warnings (unchanged). The full Vitest run had one failure,
`extractedModules.test.jsx > Tenants loads and exposes its components`, which is not in my files and passes alone in 4.98 s against the 5 s default timeout.

## Bug register (confirmed and fixed)

| ID | Tab | Role | Operation | Expected | Actual | Root cause | Fix | Retest |
|---|---|---|---|---|---|---|---|---|
| BUG-PY-1 | 19 | admin | Duplicate pay head code | Refused | Accepted; two heads share a code | `SalaryComponentRequest` had no uniqueness rule; payslip lines resolve a name by code (`PayslipCalculator::componentName`) | `unique` rule on `code` (ignores itself on edit); codeless heads still allowed | PHP `HrmsCompensationApiTest` + e2e |
| BUG-PY-2 | 21 | Employee | Download own payslip | PDF | 403 "not yours to download" | `PayslipPolicy::view` and `PayslipDownload` checked legacy `hrms.payroll.view`; the Employee role holds scoped `hrms.payroll.view_own` | Use `User::granted('hrms.payroll.view_own')` (accepts view_own, view_all and the legacy slug) | PHP `HrmsPayslipAccessTest` + e2e |
| BUG-PY-3 | all forms | all | Label for an `Input` without id/name | Label bound to input | Label not associated (screen readers, click-to-focus) | `Input.jsx` used `id \|\| props.name`; neither given -> no id | `useId()` fallback in `Input` and `DateField` | Vitest `Input.test.jsx` (4 tests) |
| BUG-PY-4 | 19 | admin | Template "Save head list" button | Aligned with modal content | Flush to the modal's left edge | Wrapper lacked `px-6` | `className="mt-4 px-6 pb-5"` | e2e screenshot |
| BUG-PY-5 | 22 | Employee | File a claim | Own record preselected, claim filed | Required picker empty (directory 403), and after filing the page bounced to /403 | Page loaded the manager-only employee directory and refetched the manager-only queue | Non-managers read `/my/hr` for their own id; queue refetch only for managers | Vitest `Statutory.test.jsx` + e2e |
| BUG-PY-6 | 22 | admin | Save one identifier | Others kept | Saving e.g. only bank name erased PAN, Aadhaar, bank account | `StatutoryProfileService::upsert` wrote `null` for every absent key; the form is always blank (masked values are never resubmitted) | Absent key keeps the stored value, explicit `null` clears; SPA sends only filled fields | PHP `HrmsStatutoryProfileTest` + e2e |
| BUG-PY-7 | 22 | admin | PAN / IFSC / UAN format | Validated | `abc` accepted as PAN; any string as IFSC/UAN | Only `max:20` rules | PAN `^[A-Z]{5}[0-9]{4}[A-Z]$`, IFSC `^[A-Z]{4}0[A-Z0-9]{6}$`, UAN `digits:12`, PAN/IFSC upper-cased first | PHP `HrmsStatutoryProfileTest` |
| BUG-PY-9 | 22 | admin | TDS projection with no rulebook | Say why | Generic "may hold no payslip" | `catch` ignored the server's `form` error | Show `fieldErrors(err).form`, fall back to the generic line | e2e |
| BUG-PY-10 | 24 | Employee | File an expense claim | Button shown, categories listed | No button at all (page said "file above"), categories 403 | Page found its employee id by searching the manager-only directory; `ExpenseCategoryPolicy` required legacy `hrms.expenses.view` | `/my/hr` for the id; category read uses `HrmsScope::canRead` (same door as the claim policy) | PHP `HrmsExpenseApiTest` + e2e |
| BUG-PY-11 | 23 | admin | Duplicate category name; negative receipt threshold | Refused | Accepted | No unique rule on `name`; `requires_receipt_above` lacked `min:0` | `unique` on name (ignores itself), `min:0` | PHP `HrmsExpenseApiTest` + e2e |
| BUG-PY-12 | 23 | admin | Decide a claim that is refused | Reason shown | Modal silently did nothing | Only field-level errors rendered; the refusal arrives under `form` | `Alert` for `decideErrors.form` | e2e |
| ENV-1 | all | - | Playwright run | Stable page | Pages reloaded in a loop | Playwright wrote trace `.html` files under `tests/e2e/.results`; Vite's watcher full-reloaded the page under test | `vite.config.js` watch ignores `**/tests/e2e/**` (Vite restarted itself) | Runs stable |

## Open defect, not fixed (needs a product decision)

**BUG-PY-8 (HIGH) Expense claims cannot be approved through the product.** `ExpenseService::approve` requires the approval chain to be
`approved`, and the chain (the claimant's manager, then the first role holding `hrms.expenses.approve`) is only ever resolved by
`ApprovalService::approve()` called from **tests**; there is no controller or route that approves an expense step (leave, resignation,
loans, shifts, comp-off, changes all have one). Any claim from an employee with a manager stays "submitted" forever; the Decide modal
now says why, but nothing lets the manager clear their step. Suggested fix: let `decide` act on the caller's pending step first (and
only set the amount on the final leg), or add `POST hrms/expenses/claims/{claim}/approve-step`. This changes the shared approval engine,
so it is left for a decision.

## Valid business restrictions (documented, unchanged)

- HR Executive has no compensation, payroll-run, expenses or statutory-management permissions: those tabs are refused with the missing permission named.
- A pay-run period is unique; a locked run offers no actions; a template in use by a salary assignment cannot be deleted; starter categories cannot be deleted.
- A reduced expense approval needs a reason; only a resolved chain approves; the claim's owner never decides their own claim.
- A TDS projection needs a rulebook for the employee's jurisdiction.
- `Resolve the approval chain before approving.` is the rule that makes BUG-PY-8 visible; the rule itself is correct.

## Improvements (not defects)

| Item | Business value | Priority |
|---|---|---|
| Inbox "Decide: Expense claim" sends a manager without `hrms.expenses.view` to a 403 page | The assigned approver cannot reach the item | High (with BUG-PY-8) |
| Show a self-service list of the Employee's own statutory claims, plus a Submit action (the policy allows it; the list endpoint is manager-only) | Employees can finish what they start | Medium |
| Pay run transitions show one generic "refused" line; surface the server's reason | Fewer support questions | Medium |
| Compensation/payroll template delete failure hides the server's reason | Same | Low |
| Hide or disable Delete on starter categories and pay heads | Removes a button that can only fail | Low |
| `Expenses.jsx` fires four identical initial claim requests | Less load | Low |
| "paid 31.00 of 31.00" on a payslip has no unit (days) | Clarity | Low |
| `Compensation.jsx` (492 lines) and `Statutory.jsx` (597) exceed the 400-line page ceiling | Standards ratchet | Low |

## Not verified

- Approve path of an expense claim (BUG-PY-8).
- Payroll run: bank file, adjustments, reopen, statutory recompute on a review run; loan instalment deduction inside a payroll run.
- Statutory: declaration proof document, TDS projection with a rulebook, surrender of a shortfall.
- Audit-log and data-access-log rows were not read in the UI; they are covered by existing PHP tests only.
- Notifications fan-out for these flows.
- Tenant isolation was exercised only through the signed-download and by-id payslip checks.

## Shared files changed

| File | Change | Why |
|---|---|---|
| `resources/js/components/ui/Input.jsx` | `useId()` fallback for the label/input id (plain input and date field) | BUG-PY-3: unlabeled inputs |
| `vite.config.js` | `server.watch.ignored` also ignores `**/tests/e2e/**` | ENV-1: Playwright trace files triggered Vite full reloads mid-test |

New test-only files: `resources/js/components/ui/Input.test.jsx`, `resources/js/pages/hrms/Statutory.test.jsx`, `tests/e2e/helpers-pay.js` (own helper; `helpers.js`, `playwright.config.js`, `package*.json`, `api.js`, `App.jsx`, `hrmsNav.js` and the locale files were not touched by this group).

## Test data left behind (local Globex only)

QA Employee: salary basis + two revisions, two locked pay runs (Apr/May 2026) with payslips, statutory profile, claims, an
expense claim (rejected), an advance (active). `QA-PY-Template` (in use). No other tenant, user or credential was changed.


## Update 2026-11-02 — BUG-PY-8 resolved

Expense approval steps now complete over HTTP and the UI (see `docs/qa/follow-ups.md` FU-1). The previously skipped approve path is covered by `tests/e2e/hrms-23b-expense-approval-steps.spec.js` and `HrmsExpenseApprovalStepTest`.
