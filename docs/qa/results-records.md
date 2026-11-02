# HRMS Records group — UI test results (tenant globex, local Docker, Playwright)

Status is set only after the test was executed. Full pass, all ten specs in one run on the restored environment:

    npx playwright test hrms-31 hrms-32 hrms-33 hrms-34 hrms-35 hrms-36 hrms-41 hrms-43 hrms-44 hrms-45 \
      --output=tests/e2e/.results-rc --retries=1 --workers=1 --timeout=120000
    -> 68 passed (5.4m), no retries used

(Own `--output` dir: parallel agents otherwise wipe the shared `.results`.)

| Row | Tab | Spec | Result |
|---|---|---|---|
| 31 | /hrms/assets | hrms-31-assets.spec.js | PASS 10/10 |
| 32 | /hrms/resignation | hrms-32-resignation.spec.js | PASS 7/7 |
| 33 | /hrms/exit-interview | hrms-33-exit-interview.spec.js | PASS 6/6 |
| 34 | /hrms/automation | hrms-34-automation.spec.js | PASS 7/7 |
| 35 | /hrms/careers | hrms-35-careers.spec.js | PASS 6/6 |
| 36 | /hrms/signatures | hrms-36-signatures.spec.js | PASS 7/7 |
| 41 | /hrms/letters | hrms-41-letters.spec.js | PASS 7/7 |
| 43 | /hrms/changes | hrms-43-changes.spec.js | PASS 6/6 |
| 44 | /hrms/assets/mine | hrms-44-my-assets.spec.js | PASS 5/5 |
| 45 | /hrms/approvals/chains | hrms-45-approval-chains.spec.js | PASS 7/7 |

Coverage per tab (what was actually executed through the UI):
- 31 register (native required, duplicate serial), the no-category dead end, search, status filter, assign (combobox), detail ledger, return, repair (description required), Employee refused in UI + 403 on API read and write.
- 44 holder sees awaiting-signature, acknowledge persists across reload, report damage -> triage, HR Executive does not see another person's handovers.
- 32 form bounds (notice 0-365, submit disabled until day+reason), past date refused by server, file / withdraw (confirm) / refile, reject needs a note and is shown to the leaver, approve, non-approver refused.
- 33 no-exit state, required questions named, persist after reload, update, API range/choice 422, no cross-read.
- 34 Save disabled until name+project, server refusal shown, due-in bounds, create/edit/off-toggle/delete (confirm, Cancel keeps), Employee refused UI + API. Rule *execution* is covered by `HrAutomationTest` (5 tests, re-run green), not re-driven in the UI.
- 35 add path (both selects), self-path and duplicate refused visibly, remove, succession position/successor/readiness/remove, over-long title surfaced, Employee read-only with no failing calls.
- 36 documents uploaded through the Documents UI, request, wrong password refused, sign, public receipt verification (no login), decline with in-app reason, cancel, cancelled request not signable, Employee cannot request.
- 41 starters idempotent, unknown document type and empty body refused, custom merge field required, PDF filed in the employee's documents, rename persists, delete confirm, Employee refused UI + API.
- 43 disabled-until-valid, empty transfer refused, promotion requested/persisted, one-pending-per-person rule, requester sees Withdraw not Approve, HR Executive rejects (note required) — result shown to the requester, Employee read-only with no failing calls.
- 45 all chains listed, add/remove/reorder + Cancel discards, role step without role refused, valid edit -> Customised + Reset + persists, Reset confirm then restore default, Employee refused UI + API.

## Bug register

| ID | Tab(s) | Role | Operation | Expected | Actual | Root cause | Fix (files) | Retest |
|---|---|---|---|---|---|---|---|---|
| RC-1 | any page using `<Select label>` without id/name (31 register dialog…) | any | label | control has an accessible name | `<label for>` pointed at nothing | `Select.jsx`: `id \|\| props.name`, no fallback | `useId()` fallback — `components/ui/Select.jsx` | `Select.test.jsx` (2 failed before) |
| RC-2 | 31 | admin | register first asset | a new tenant can register an asset | Category required, none exist, nothing in the SPA creates one | categories are tenant-built by design but only the API existed | new `components/hrms/AssetCategoryQuickAdd.jsx` shown in the register dialog (manage perm); `Assets.jsx`; en+es keys | `AssetCategoryQuickAdd.test.jsx`, Playwright |
| RC-3 | 32, 33 | employee | file / answer | visible label + error | `Textarea` ignored `label`/`error` (leaked onto the DOM) so Reason/free-text fields had no label or error | primitive only supported FormField wrapping | `components/ui/Textarea.jsx` renders label/error with `useId` when given | `Textarea.test.jsx` (1 failed before) |
| RC-4 | 32 | employee | withdraw | confirm says Withdraw | red button said "Delete" | `useConfirm` defaults a danger confirm to "Delete" | `confirmLabel` in `Resignation.jsx` | `Resignation.test.jsx` |
| RC-5 | 33 | employee | submit with nothing filled | per-question "needs an answer" | nothing happened (422 `answers is required`, never shown) | `required` rejects an empty array; page ignored `errors.form` | validator `present`+`array` in `ExitInterviewController` (2 endpoints); `ExitInterview.jsx` shows `errors.form` | `HrmsExitInterviewTest` (failed before), `ExitInterview.test.jsx` |
| RC-6 | 35 | employee (no talent.manage) | load | no failing background calls | `GET hrms/employees` -> 403 on every load | unconditional fetch feeding manager-only forms | gate on `hrms.talent.manage` in `Careers.jsx` | `Careers.test.jsx` (1 failed before), Playwright asserts zero failed responses |
| RC-7 | 35 | talent manager | add key position (over-long title) | visible error | silent failure (unhandled rejection) | `addPosition` had no catch | try/catch + existing Alert in `Careers.jsx` | `Careers.test.jsx` (failed before), Playwright |
| RC-8 | 36 | signer | decline | in-app dialog | native `window.prompt` | direct browser prompt | `usePrompt` in `Signatures.jsx` | `Signatures.test.jsx` (failed before) |
| RC-9 | 41 | admin | delete template | confirmation first | deleted on first click (data loss) | no confirm | `useConfirm` + new en/es keys in `Letters.jsx` | `Letters.test.jsx` (2 failed before), Playwright |
| RC-10 | 43 | employee | load | no failing background calls | `GET hrms/employees` -> 403 on every load | unconditional fetch | gate on `hrms.employees.view` in `EmployeeChanges.jsx` | `EmployeeChanges.test.jsx` (1 failed before), Playwright |
| RC-11 | 45 | admin | close the chain editor (Cancel / X / Esc) | editor closes | whole tab replaced by the error boundary: `Cannot read properties of null (reading 'fields')` | `editing` cleared on close while the Modal still renders its content during the transition; `editing.fields` dereferenced | render body only when `draft && editing` in `ApprovalChains.jsx` | `ApprovalChains.test.jsx` (reproduced the exact TypeError before), Playwright |

## Leads from the Employee route scan: outcome
- careers + changes background `hrms/employees` 403: **confirmed** (RC-6, RC-10).
- shift-swaps / statutory / expenses-mine / improvement-plans / holidays / my-performance leads belong to other groups.

## Valid business behaviour (documented, unchanged)
- Assets: categories are tenant-built, no starters seeded (model docblock). Damage note (`return_note`) is optional by API design.
- Resignation / exit: a non-approver acting on a request gets a 422 domain error from the service (project pattern) and nothing changes.
- Chains / any FormRequest endpoint: validation runs before the controller's authorize (documented order "validate -> authorize -> service"); an unauthorized caller with an invalid body sees 422, with a valid body 403, and nothing is saved.
- Changes: one pending change per person ("This person already has a change waiting for a decision").
- Letters: built-in merge fields are `employee_name` etc. (`config/letters.fields`); any other token is treated as a custom field and asked for at generate time.
- Login endpoint is throttled; specs reuse saved sessions, and `personaPage` re-authenticates once when a saved session has expired.

## Improvements (not defects)
| Idea | Value | Priority |
|---|---|---|
| `useConfirm` defaults every `variant:'danger'` confirm to "Delete" (53 call sites pass no `confirmLabel`): default to "Confirm" and require an explicit destructive label | removes mislabelled destructive buttons platform-wide | Medium |
| Careers: "Remove" on career paths / succession positions / candidates fires without a confirmation | accidental loss of succession data | Medium |
| Require a description when reporting asset damage | triage gets context | Low |
| Asset categories: rename / deactivate / delete in the UI (API exists) | no raw API for catalogue upkeep | Low |
| Letters: "Add starter templates" gives no feedback when it adds nothing; document-type slug is a free-text field (a dropdown of real types would prevent the unknown-type error) | fewer dead ends | Low |
| Approval chains: show "Role" with the role name for permission-based role steps (Expense claims shows a bare "Role") | clarity | Low |
| e2e stability: `artisan serve` is single-threaded and Vite HMR reloads pages mid-test when files change; run e2e against a built bundle + php-fpm | stable e2e | Medium |
| Theme Settings drawer is a `role=dialog` always present in the DOM (a11y: hide from assistive tech while closed) | a11y | Low |

## Shared files changed (and why)
| File | Why |
|---|---|
| `resources/js/components/ui/Select.jsx` | RC-1 (id fallback) |
| `resources/js/components/ui/Textarea.jsx` | RC-3 (label/error support) |
| `resources/js/locales/en/hrms.json`, `resources/js/locales/es/hrms.json` | new keys only, inserted line-wise: `hrms.assets.{noCategories,newCategory,addCategory,categoryAdded}` (RC-2), `hrms.letters.{deleteTitle,deleteMessage}` (RC-9) |
| `app/Http/Controllers/Hrms/Lifecycle/ExitInterviewController.php` | RC-5 (two validators) |
| `tests/Feature/HrmsExitInterviewTest.php` | regression test for RC-5 (Pint also added the strict-types header to this file) |
| Not touched by me: `api.js`, `vite.config.js`, `App.jsx`, `hrmsNav.js`, `playwright.config.js`, `package*.json`, `helpers.js`, the checklist |

Page files changed: `Assets.jsx`, `Resignation.jsx`, `ExitInterview.jsx`, `Careers.jsx`, `Signatures.jsx`, `Letters.jsx`, `EmployeeChanges.jsx`, `ApprovalChains.jsx`.
New components/tests: `components/hrms/AssetCategoryQuickAdd.{jsx,test.jsx}`, `components/ui/{Select,Textarea}.test.jsx`, `pages/hrms/{Resignation,ExitInterview,Careers,Signatures,Letters,EmployeeChanges,ApprovalChains}.test.jsx`, `tests/e2e/hrms-{31..45}-*.spec.js` (my ten), `tests/e2e/helpers-records.js`.

## Gates (after all changes)
- Vitest: 86 files / 364 tests pass (full run). ESLint: 0 errors, 122 warnings (unchanged from baseline; each page I touched has the same warning count as at HEAD). Pint passes on the PHP files I touched. `HrmsExitInterviewTest` 7/7, `HrAutomationTest` 5/5.

## Leftover QA data (not deletable through the UI; nothing was removed from the database directly)
- Users: `QA-RC Resigner <run>` (per run; the current one has an approved resignation and an exit case), plus earlier-run leavers whose resignations are still Pending.
- Assets: `QA-RC-Laptop-*`, `QA-RC-Phone-*`, `QA-RC-Tablet-*` registered and assigned/triaged; categories `QA-RC-Cat-*`, `QA-RC-MyCat-*` (duplicates allowed by the API).
- Documents `QA-RC-NDA-*`, `QA-RC-Policy-*`, `QA-RC-Offer-*` for QA Employee; signature requests (signed, declined, cancelled) and generated letters from the QA-RC letter templates.
- Career paths / positions were removed by the tests; the Loans approval chain was restored to Default.
- HR automation rule, letter template and pending-change requests created by the tests were removed or withdrawn through the UI.

## Unverified / limits
- Automation rule *firing* through the UI (covered by the PHP test only).
- Approve/apply path of a change at its effective date (needs a date in the past; scheduler not driven here).
- Notifications and audit-log rows for these flows were not asserted in the UI.
- `es` locale rendering of the new strings was not browser-tested (parity tests pass).
