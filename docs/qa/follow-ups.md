# HRMS QA follow-ups

## FU-1 — Expense claims cannot be approved (BUG-PY-8, HIGH) — DONE 2026-11-02

**Implemented:** `POST hrms/expenses/claims/{claim}/approve-step` and `.../reject-step` (`ExpenseClaimController`, `ExpenseApprovalSteps`, policy ability `decideStep` = submitted + open chain + `ApprovalService::canAct`; override keeps its audited reason rule). The claim list and detail carry `can_act_step`; `Expenses.jsx` shows "Clear my step" / "Reject at my step" to whoever can act, and the priced decision form only to `hrms.expenses.approve` holders. Tests: `HrmsExpenseApprovalStepTest` (7), `Expenses.test.jsx` (2), Playwright `hrms-23b` (employee files -> admin clears both legs via the override -> priced approval). Follow-on (2026-11-02): the Expenses queue and nav accept any viewing scope (`view|view_assigned|view_all`, new any-of gate syntax `a|b|c`), so a Manager can open it from the Inbox link; clearing a step now nudges the next approver.

Original analysis:

- **Found by:** Pay-group QA fork, tab `/hrms/expenses` (see `docs/qa/results-pay.md`).
- **Symptom:** every expense claim from an employee who has a manager stays `submitted` forever.
- **Cause:** no route or controller approves an expense *approval step*. `ApprovalService::approve` is only called directly from tests. The Decide modal now says why it cannot proceed, but nothing completes the step.
- **Chosen fix:** add an explicit endpoint `POST hrms/expenses/claims/{claim}/approve-step` (and a matching reject), rather than overloading the existing `decide` action.
  - Authorization goes through `ApprovalService::canAct` (the single check for approval policies), including the audited override path (`hrms.approvals.override`, reason >= 5 chars). No silent admin bypass.
  - Controller: validate (FormRequest) -> authorize -> one service call -> present. Audit rows via the existing approval audit names.
  - The Decide modal calls the new endpoint for the caller's pending step.
- **Definition of done:** a failing-first feature test per rule (happy path, 403 for a non-approver, 403 for the requester approving their own claim, 422 for a missing override reason, claim state moves to approved/rejected and notifies the requester), a Playwright run through the UI as manager and as admin, `route:list`/API snapshot updated, `lang/es.json` + SPA keys added.
- **Why deferred:** it changes who can act inside the shared approval engine, so it gets its own reviewed commit after the QA pass instead of being folded into it.

## FU-2 — BUG-T6 colleague picker — DONE 2026-11-02

`GET hrms/shift-swaps/colleagues` returns `id` + `name` only (active, with a login, excluding the caller, capped at 200, searchable). `ShiftSwaps.jsx` uses it instead of the employee directory. Tests: `HrmsShiftSwapColleaguesTest` (5), `ShiftSwaps.test.jsx`, Playwright `hrms-17` (the former expected-failure now passes).

## FU-3 — Open improvement: a rejected statutory declaration gives no reason (Medium)

`StatutoryDeclarationService::reject()` takes no reason and the table has no column for one, so the employee sees `rejected` and nothing else (expense, resignation, comp-off and document flows all require a reason). Needs a tenant HRMS migration (`rejection_reason`), a required `reason` on the reject endpoint, the Statutory page prompting for it, and the claimant's page showing it.

## FU-4 — Open improvement: no reversal path for a generated payslip line (Low)

Loan-instalment and paid-claim lines can no longer be deleted by hand (they would desync the loan balance / claim state). If HR genuinely needs to skip an instalment or un-pay a claim, that needs an explicit, audited reversal (instalment back to `due` and balance restored, claim back to `approved`) rather than deleting the line.
