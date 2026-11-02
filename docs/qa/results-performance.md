# HRMS UI QA — Performance group (results)

Tenant: globex (local Docker, `http://localhost:8000`). Personas: owner (admin), `qa.employee@globex.test` (Employee), `qa.hrexec@globex.test` (HR Executive).
Run: `npx playwright test hrms-25 hrms-26 --output <private dir>` (a private `--output` dir is required while several agents run Playwright: it wipes `outputDir` on start).

## Status

| Row | Tab | Status | Executed |
|---|---|---|---|
| 25 | `/hrms/performance` | **Pass** | 8 UI tests, 3 consecutive green runs (incl. idempotent rerun) |
| 26 | `/hrms/performance/mine` | **Pass** (after fix BUG-PF-1) | 6 UI tests, 3 consecutive green runs |
| 27 | `/hrms/engagement` | **Pass** (after fix BUG-PF-3) | 9 UI tests, 3 consecutive green runs |
| 28 | `/hrms/engagement/mine` | **Pass** (after fix BUG-PF-4) | 7 UI tests (5 on reruns: 2 first-pass-only skip), green on reruns |
| 29 | `/hrms/skills` | **Pass** (no defect) | 8 UI tests (7 + 1 first-pass-only skip on reruns) |
| 30 | `/hrms/talent` | **Pass** (after fix BUG-PF-5) | 6 UI tests; rerun from the sealed state: 4 passed, 2 skipped |
| 37 | `/hrms/okrs` | **Pass** (no defect) | 10 UI tests; two consecutive reruns: 8 passed, 2 skipped (first-pass-only) |
| 38 | `/hrms/development-plans` | **Pass** (after fix BUG-PF-6) | 7 UI tests; two consecutive reruns together with tab 37: 14 passed, 3 skipped (first-pass-only) |
| 39 | `/hrms/improvement-plans` | **Pass** (after fix BUG-PF-7; lead confirmed) | 7 UI tests; rerun: 5 passed, 2 skipped (first-pass-only) |

## Bug register

| ID | Tab | Role | Operation | Expected | Actual | Root cause | Fix | Retest |
|---|---|---|---|---|---|---|---|---|
| BUG-PF-1 | 26 My performance | Employee (`hrms.performance.view_own`) | Open the page | Own goals, check-ins, 1:1s and feedback | Redirected to `/403`; `GET hrms/performance/cycles` and `GET hrms/employees` both 403 | (a) `PerformanceCyclePolicy` admitted only `hrms.performance.view`/`manage`, ignoring the `_own`/`_assigned` scopes every sibling policy honours via `HrmsScope`; (b) `MyPerformance.jsx` found the caller's record by scanning the paged `/hrms/employees` directory, which needs `hrms.employees.view` | Policy reads now answer to `HrmsScope::canRead` (writes stay manage-only); page resolves the record from `/my/hr` (same as `PerformanceCycleDetail`) | `HrmsPerformanceSelfServiceTest` (3), `MyPerformance.test.jsx`, Playwright tab 26 |
| BUG-PF-2 | 26 My performance | admin without employee record | Draft a goal | Readable message | "No employment record…" text flush against the modal edge (no padding) | missing `px-6 py-5` on the `<p>` | className added in `MyPerformance.jsx` | Visual (screenshot) + tab 26 test asserts the text |

Regression runs after BUG-PF-1 (all green): `HrmsPerformanceCycleTest` 4, `HrmsPerformanceApiTest` 4, `HrmsScopeEmployeeIdTest` 7, `HrmsScopeAccessTest` 7, `MemberAccessMatrixTest` 6; Vitest `resources/js/pages/hrms` 4 files / 6 tests; ESLint 0 errors on touched files; Pint pass.

## Lead resolution

- *My performance full-page 403 for Employee* — **confirmed defect** (BUG-PF-1).
- *Background `hrms/employees` 403 on improvement-plans* — **not yet examined** (tab 39 not run).

## Valid business restrictions (leave unchanged)

- Cycles are create-only and never deleted; stages only move forward through confirmed buttons.
- HR Executive holds `hrms.performance.view` but not `manage`: sees cycles, gets no New cycle / advance controls, API POST → 403.
- An Employee cannot open `/hrms/performance` (cycle admin): 403 page naming `hrms.performance.view`.
- A login without an employment record (the seeded Globex owner) cannot draft goals: "No employment record found for this login."
- An Employee reading another employee's goals via `employee_id` gets `[]`; POSTing a goal for another employee → 403.
- Goal titles and cycle names are not unique (no rule); weights are only checked to total 100 at stage transitions.

## Integration verified

- Cycle creation and stage move write `hrms_audit_logs` rows (`performance.cycle_created`, `performance.cycle_check_in_opened`).

## Improvements (not defects)

| Idea | Value | Priority |
|---|---|---|
| My performance shows "No goals in this cycle yet." while the caller's record is still resolving (flicker); show the skeleton until `employeeId` resolves | Avoids a false empty state | Low |
| Date fields keep the calendar popup open after typing; it covers the submit button (one extra click or Tab) | Faster form completion | Low |
| `Draft a goal` is offered to a login with no employment record, then refuses | Hide/disable with explanation | Low |
| The Theme Settings drawer is an always-mounted `role=dialog` (also `getByRole('dialog').last()` is the wrong dialog); expose it only while open | Screen-reader noise, test robustness | Medium |
| Cycles/goals have no delete or archive path; test data cannot be removed through the product | Operational hygiene | Medium |

## Test data created (all `QA-PF-` prefixed, Globex only)

Cycles `QA-PF-Cycle-Goals` (goal setting), `QA-PF-Cycle-Flow` (advanced to check-in); goals `QA-PF-Goal-Self` (Employee), `QA-PF-Goal-HrExec` (HR Executive). Duplicates produced by two of my own test races were removed directly in the Globex tenant DB (cycles 3-5, goals 2-10, 12; QA-PF- names only, no dependent rows). No pre-existing record was touched.

## Files

Created: `tests/e2e/hrms-25-performance.spec.js`, `tests/e2e/hrms-26-my-performance.spec.js`, `tests/e2e/helpers-performance.js`, `tests/Feature/HrmsPerformanceSelfServiceTest.php`, `resources/js/pages/hrms/MyPerformance.test.jsx`, this file.
Modified: `app/Policies/Hrms/Performance/PerformanceCyclePolicy.php`, `resources/js/pages/hrms/MyPerformance.jsx`.


## Tabs 27-30 and 37 (second session)

### Bug register (additions)

| ID | Tab | Role | Operation | Expected | Actual | Root cause | Fix | Retest |
|---|---|---|---|---|---|---|---|---|
| BUG-PF-3 | 27 Engagement | admin | New template with an over-long question (>2000 chars) | A message next to that question | Nothing: modal stays open, no feedback | `saveTemplate` only mapped `name/type/frequency/audience` errors; server keys such as `questions.0.text` were never read, and `form`/`questions` errors had no display. Blank questions are also dropped before sending, so the server index differs from the on-screen index | `Engagement.jsx`: map `questions.N.*` back to the on-screen question, show per-question errors and a form-level alert | `Engagement.test.jsx` (3, failed first) + Playwright tab 27 |
| BUG-PF-4 | 28 My surveys | Employee | Skip a required question, go to the last step, Submit | Told which answer is missing | Nothing visible: error keyed `questions.{id}` was only rendered on the current question, so the person stayed on step 2 with a silent form | `MySurvey.jsx` `submit()` stored the errors but never returned to the offending question | On a per-question error, jump back to the first question that has one (the message then renders) | `MySurvey.test.jsx` (failed first) + Playwright tab 28 |
| BUG-PF-5 | 30 Talent | admin | Open the room; Calibrate | Cycle picker lists cycles; rating picker offers 1-5 | Both dropdowns rendered with NO options (cycle picker blank; calibrated-rating picker empty), so a cycle could not be switched and a rating could not be chosen | `Talent.jsx` passed `options={...}` to the shared `Select`, which only renders `<option>` children and spreads unknown props onto `<select>` | `Talent.jsx`: render real `<option>` children (shared `Select` untouched) | `Talent.test.jsx` (2, failed first) + Playwright tab 30 |

**Same defect class, not fixed (outside the Performance group):** `resources/js/pages/hrms/EmployeeChanges.jsx` lines 145-161 pass `options=` to `Select` six times (employee, department, designation, location, manager, ...). They render empty. Needs the owner of the Records group; the root fix would be to teach `Select` an `options` prop or convert call sites.

### Valid business restrictions (additions)

- Engagement: only `hrms.engagement.manage` can create templates/campaigns or move a campaign (open/invite/close); HR Executive (view only) sees the page without controls and the API answers 403; Employee gets the 403 page naming `hrms.engagement.view`.
- Anonymity: with fewer responses than the threshold, results are withheld (`results: []`) and the respondent's name/comment never appear in the admin results payload.
- A survey takes one submission per person; a repeat is refused (409/422); closed campaigns leave the Employee's list.
- Skills: a skill someone holds cannot be deleted; a manager's assessment locks the person's own level; catalogue/assessment need `hrms.talent.manage`.
- Talent: calibration and sealing need `hrms.talent.manage`; ratings are 1-5 (422 outside); a sealed review cannot be recalibrated (API refuses).
- OKRs: an Employee can create only their own objectives (company-wide is 403); an admin without an employment record cannot own a personal objective; a closed objective is read-only.

### Improvements (additions)

| Idea | Value | Priority |
|---|---|---|
| Campaign form silently turns an anonymity threshold of `0` into `5` (`Number(x) \|\| 5`) while negatives are rejected: inconsistent. Seen in code; not executed in the UI | Predictable validation | Low |
| `Okrs.jsx` `close()` has no error handling (a refused close is an unhandled rejection with no message). Seen in code; not executed | Honest failure feedback | Low |
| Date popups stay open and cover the submit button (also seen on Engagement/OKRs forms) | Fewer misclicks | Low |
| My surveys hides an answered survey's button, so the "already answered" page is reachable only by URL | Clarity | Low |
| Objectives, cycles, goals, templates, campaigns have no delete/archive path, so QA data cannot be removed through the product | Operational hygiene | Medium |

### Test data left in Globex (cannot be removed through the product; nothing deleted in the DB this session)

- Engagement: template `QA-PF-Pulse`, `QA-PF-Required`; campaigns `QA-PF-Campaign` (open, 1 response), `QA-PF-Campaign-Required` (closed, 1 response).
- Skills: `QA-PF-Skill-Keep` (held by QA Employee at level 4, assessed). `QA-PF-Skill-Temp` is created and deleted by the spec through the UI each run.
- Talent: cycle `QA-PF-Cycle-Flow` is now in calibration with QA Employee's review calibrated to 4 and sealed.
- OKRs: two identical company objectives `QA-PF-OKR-Company` (the second was created by a retry in my own locator bug; objectives cannot be deleted) and one closed `QA-PF-OKR-Mine`.

### Shared files changed in this session

None of `api.js`, `vite.config.js`, `App.jsx`, `hrmsNav.js`, locales or UI primitives were changed by this work. Feature files edited: `Engagement.jsx`, `MySurvey.jsx`, `Talent.jsx` (each with a new `*.test.jsx`). (`Select.jsx` and `api.js` show uncommitted edits made by other agents; not mine.)

### Playwright commands (always with a private output dir)

`npx playwright test hrms-27 hrms-28 hrms-29 hrms-30 hrms-37 --output <private dir>`


## Tab 38 — Development plans

### Bug register (addition)

| ID | Tab | Role | Operation | Expected | Actual | Root cause | Fix | Retest |
|---|---|---|---|---|---|---|---|---|
| BUG-PF-6 | 38 Development plans (same pattern on 37 OKRs) | Employee / admin | Submit the start form with end before start | The error once, under the field | The same sentence twice: a red banner at the top and again under "Ends" | `setErrors({ ...fe, form: fe.form \|\| response.message })`: the generic response message always duplicated the first field error | Use the generic message only when no field has an error of its own (`DevelopmentPlans.jsx`, `Okrs.jsx`); the OKR `scope` error (no field) still shows in the banner | `DevelopmentPlans.test.jsx` (2) and `Okrs.test.jsx` (2), each failed first |

### Findings that were NOT defects

- First reading of the privacy test suggested the HR Executive could read an Employee's private plan. Verified in data: the QA HR Executive *is* QA Employee's manager, and "the plan owner, their manager and performance managers" is the documented rule (`DevelopmentPlanController::allowed`). The test now checks the true boundary: the Employee cannot see the HR Executive's own plan, the HR Executive (manager) sees the Employee's plan, the admin (manage) sees both.

### Valid business restrictions (additions)

- A plan is visible only to its owner, the owner's manager and `hrms.performance.manage`; closed plans lock their actions; a login without an employment record cannot start a plan (422 "You need an employment record to have a development plan.").
- The form has no person picker: managers can only open plans for themselves from the UI (the API accepts `employee_id` for managers).

### Improvements (additions)

| Idea | Value | Priority |
|---|---|---|
| `DevelopmentPlans.jsx` `close()` and `Okrs.jsx` `close()` have no error handling (a refused close is an unhandled rejection with no message). Seen in code; not executed | Honest failure feedback | Low |
| Managers cannot open a plan for a report from the UI (no person picker) although the API supports it | Manager workflow | Medium |

### Test data left in Globex (cannot be removed through the product)

Plans `QA-PF-IDP-Complete` (completed), `QA-PF-IDP-Cancel` x2 (cancelled; the second is a duplicate created by my own early-check race, now fixed), `QA-PF-IDP-HrExec` (active, owned by QA HR Executive).

### Test-harness fixes made on the way (my own files only)

- `helpers-performance.js` `useSession`: validates a saved session with `/api/auth/me` and signs in again if it was rejected (stale cookies after the environment reset sent every page to the login screen).
- Pages render their top-bar title before their list, so "heading visible" is not a loaded signal; specs now wait for the loaded-state button / network response before deciding whether test data already exists.


## Tab 39 — Improvement plans (and the `hrms/employees` 403 lead)

### Lead resolution

**Confirmed, as a UX defect (BUG-PF-7), not a data leak.** Every visitor's page load fired `GET hrms/employees` for the person picker. Anyone without `hrms.employees.view` (e.g. an Employee) got a 403 that the page silently swallowed, while the page still offered "Open a plan" with an empty picker, a form that could never be submitted. Server-side authorisation was already correct (only `hrms.performance.manage` or the person's direct manager may open or run a plan; 403 otherwise).

| ID | Tab | Role | Operation | Expected | Actual | Root cause | Fix | Retest |
|---|---|---|---|---|---|---|---|---|
| BUG-PF-7 | 39 Improvement plans | Employee | Open the page | No action they cannot use; no refused request | "Open a plan" shown with an empty person picker; background `hrms/employees` 403 on every load | The page did not know whether the caller may open plans, so it always rendered the action and always requested the directory | `ImprovementPlanController::index` now returns `can_open` (manage, or direct manager of someone: the same rule as `canRun`); `ImprovementPlans.jsx` shows the button and loads the directory only when `can_open` | `HrmsImprovementPlanOpenAccessTest` (3, failed first), `ImprovementPlans.test.jsx` (2, failed first), Playwright lead test asserts no refused `/hrms/employees` and no button |

JSON change: `GET api/hrms/improvement-plans` gains one boolean key `can_open` (additive; existing consumers unaffected). No new backend message strings, so `lang/es.json` is unchanged.

### Valid business restrictions (additions)

- Improvement plans are confidential: visible to the person, their direct manager and performance managers; a broad `view_all` grant is deliberately not enough (also covered by the existing `HrmsImprovementPlanTest::test_it_is_confidential`).
- One active plan per person: a second plan is refused with a message while one is active.
- Closing needs a result and an outcome; a closed plan locks its milestones.

### Test data left in Globex (cannot be removed through the product)

One improvement plan for QA Employee, opened by QA HR Executive, now Completed with outcome `QA-PF-PIP targets met`.

## Final summary of this work stream

### Tab status

All nine Performance-group tabs are **Pass**: 25, 26, 27, 28, 29, 30, 37, 38, 39. Cross-spec regression: `npx playwright test hrms-25 hrms-26 hrms-27 hrms-28 hrms-29 hrms-30 hrms-37 hrms-38 hrms-39` = **58 passed, 10 skipped (tests that only apply on a first pass over fresh data), 0 failed, 0 flaky** on the final run.
One earlier long run had 1 failed + 1 flaky, both in tab 39 (the lead test and the close test). Neither reproduced in two isolated runs or in the next full run; the cause was not identified (no error text was retained), so treat tab 39 as passing but watch it.

### Bugs found and fixed (7)

BUG-PF-1 My performance 403 for Employees; BUG-PF-2 padding on "no employment record"; BUG-PF-3 Engagement template question errors invisible; BUG-PF-4 My surveys required-answer error invisible; BUG-PF-5 Talent dropdowns rendered without options (cycle picker and calibrated rating); BUG-PF-6 duplicated validation messages (Development plans, OKRs); BUG-PF-7 dead-end "Open a plan" + refused directory request.

### Defect found but NOT fixed (outside this group)

`resources/js/pages/hrms/EmployeeChanges.jsx` lines 145-161 pass `options=` to `Select` six times; they render with no choices (same root cause as BUG-PF-5). Owner of the Records group should fix: either convert the call sites to `<option>` children or teach `Select` an `options` prop.

### Automated tests added

PHP: `HrmsPerformanceSelfServiceTest` (3), `HrmsImprovementPlanOpenAccessTest` (3). Vitest: `MyPerformance`, `Engagement` (3), `MySurvey`, `Talent` (2), `DevelopmentPlans` (2), `Okrs` (2), `ImprovementPlans` (2), `HrmsOverview`-style tests from the first session. Final gates: Vitest `resources/js/pages/hrms` 19 files / 33 tests pass; ESLint 0 errors (4 warnings, all raw `<select>`/`<table>` that exist at HEAD); Pint pass; PHP: the four related feature files above pass (3, 3, 3, 4 tests) plus the earlier scope/matrix suites.

### Files

Created: `tests/e2e/hrms-25..30,37,38,39-*.spec.js`, `tests/e2e/helpers-performance.js`, `tests/Feature/HrmsPerformanceSelfServiceTest.php`, `tests/Feature/HrmsImprovementPlanOpenAccessTest.php`, `resources/js/pages/hrms/{MyPerformance,Engagement,MySurvey,Talent,DevelopmentPlans,Okrs,ImprovementPlans}.test.jsx`, this file.
Modified (product code): `app/Policies/Hrms/Performance/PerformanceCyclePolicy.php`, `app/Http/Controllers/Hrms/Performance/ImprovementPlanController.php`, `resources/js/pages/hrms/{MyPerformance,Engagement,MySurvey,Talent,DevelopmentPlans,Okrs,ImprovementPlans}.jsx`.

### Shared files changed

**None.** No UI primitive (`Select`, `Modal`, `PersonSelect`, ...), `services/api.js`, `vite.config.js`, `App.jsx`, `hrmsNav.js`, locale JSON, `helpers.js`, `playwright.config.js` or `package*.json` was edited by this work stream. (`Select.jsx` and `api.js` show uncommitted edits by other agents.) `PerformanceCyclePolicy.php` and `ImprovementPlanController.php` are Performance-domain backend files, not shared infrastructure.

### Test data left in Globex (nothing deleted directly in the DB this session)

Cycles `QA-PF-Cycle-Goals`, `QA-PF-Cycle-Flow` (calibration, review sealed); goals `QA-PF-Goal-Self`, `QA-PF-Goal-HrExec`; engagement templates `QA-PF-Pulse`, `QA-PF-Required`, campaigns `QA-PF-Campaign` (open) and `QA-PF-Campaign-Required` (closed); skill `QA-PF-Skill-Keep`; OKRs `QA-PF-OKR-Company` x2 (one a duplicate from my own race), `QA-PF-OKR-Mine` (closed); development plans `QA-PF-IDP-Complete`, `QA-PF-IDP-Cancel` x2 (one a duplicate), `QA-PF-IDP-HrExec`; one improvement plan for QA Employee. (In the first session, before the "no direct DB deletes" rule, I removed duplicate QA-PF goal/cycle rows I had created through two test races; disclosed in the earlier section.)
