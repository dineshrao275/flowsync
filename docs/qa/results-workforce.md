# HRMS UI QA — Workforce group results

Environment: local Docker stack (`http://localhost:8000`), tenant **globex**. Playwright specs in `tests/e2e/hrms-NN-*.spec.js`.
Personas: admin `owner@globex.test`, QA Employee, QA HR Executive (see `hrms-ui-checklist.md`).
Status below is only for tests that were executed.

| Row | Tab | Status | Executed |
|---|---|---|---|
| 2 | `/hrms/inbox` | Pass (empty state, roles, API health) + populated state: the HR item of a started onboarding case appears in the admin Inbox (tab 8 spec) | 4 tests + 1 |
| 3 | `/hrms/team` | Pass (renders for 3 roles, no failing API call) | 4 tests |
| 4 | `/hrms/employees` | Pass | 13 tests (2 consecutive green runs) + `HrmsEmployeeCodeSoftDeleteTest` (2) + `Employees.test.jsx` |
| 5 | `/hrms/org` | Pass | 8 tests |
| 42 | `/hrms/org-chart` | Pass | 6 tests + `OrgChart.test.jsx` (+1) |
| 6 | `/hrms/documents` | Pass | 10 tests |
| 7 | `/hrms/documents/mine` | Pass | 6 tests (`hrms-07-my-files.spec.js`) |
| 8 | `/hrms/onboarding` | Pass (incl. the populated Inbox case: the started case's HR item appears in the admin Inbox) | 9 tests (`hrms-08-onboarding.spec.js`) |
| 9 | `/hrms/offboarding` | Pass | 12 tests (`hrms-09-offboarding.spec.js`) |
| 3b | `/hrms/team` with real reports | Pass (manager sees the report, report sees none, org chart shows it, reporting loop refused) | 5 tests (`hrms-03b-team-manager.spec.js`) |

## Regression evidence

- Full Workforce Playwright regression (`hrms-02/03/03b/04/05/06/07/08/09/42`): 74 passed, 3 failed; the 3 were the Inbox spec's stale "All caught up" assumption (the Inbox is now legitimately populated). After making it state-independent, `hrms-02` passes 4/4. Net: all Workforce specs green.
- Vitest (touched files + locale parity): 5 files / 30 tests green. ESLint on touched files: 0 problems. Pint on touched PHP: clean.
- PHP (each file run alone with `php artisan test --parallel`): `HrmsEmployeeCodeSoftDeleteTest` 2, `HrmsLifecycleDeletedEmployeeTest` 2, `HrmsEmployeeServiceTest` 61, `HrmsLifecycleApiTest` 11, `HrmsLifecycleServiceTest` 19, all green. The full PHP suite was not run.

## Bug register (confirmed and fixed)

| ID | Tab | Role | Operation | Expected | Actual | Root cause | Fix | Retest |
|---|---|---|---|---|---|---|---|---|
| WF-1 | 4 Employees | admin | Create after any delete | New employee gets the next code | Every create failed: "Could not allocate a unique employee code" once any employee was soft-deleted | `EmployeeCodeGenerator::nextSequence()` read `Employee::query()` (hides soft-deleted rows) while the unique index covers them, so all 5 retries re-picked the deleted row's code | `Employee::withTrashed()` in `app/Support/Hrms/EmployeeCodeGenerator.php` | `HrmsEmployeeCodeSoftDeleteTest` fails before / passes after; `HrmsEmployeeServiceTest` 61/61; Playwright hrms-04 13/13 twice |
| WF-2 | 4 Employees | admin | Search while the list loads | List always matches the search box | A slow earlier (unfiltered) response could land after a filtered one and overwrite it | `load()` had no abort/stale guard; one request per keystroke | `AbortController` per effect in `resources/js/pages/hrms/Employees.jsx` | `Employees.test.jsx` fails before / passes after |
| WF-3 | all abortable fetches | any | Superseded request | Cancelled requests are silent | Logged as `NETWORK_ERR` console errors | Response interceptor logged every rejection | `if (axios.isCancel(error))` early return in `resources/js/services/api.js` | `api.test.js` (2) |
| WF-4 | 42 Org chart | admin | Collapse a top-level manager | Team collapses | Button toggled its label but the team never collapsed | `isOpen = open.has(id) \|\| depth < 1` made roots permanently open | XOR with the default + `forceOpen` for search in `resources/js/pages/hrms/OrgChart.jsx` | `OrgChart.test.jsx` fails before / passes after; Playwright collapse test |
| WF-5 | 9 Offboarding | any | Open the Status filter | "In progress / Completed / Cancelled" | Showed raw keys `hrms.offboarding.stInProgress`, `stCompleted`, `stCancelled` | The three keys existed only under `onboarding` in the locale files | Added the keys to `offboarding` in `resources/js/locales/en/hrms.json` and `es/hrms.json` | `OffboardingCases.test.jsx` fails before / passes after; locale parity tests pass; Playwright option-label test |
| WF-6 | 8 + 9 | any | Open the Offboarding / Onboarding list after an employee with a case was soft-deleted | List still loads, case keeps its person | `GET /hrms/offboarding/cases` (and onboarding) answered **500** for everyone: `LifecyclePresenter::person()` got `null` | `OffboardingCase::employee()` / `OnboardingCase::employee()` used a plain `belongsTo`, which hides trashed employees | `->withTrashed()` on both relations (`app/Models/Hrms/Lifecycle/`) | `HrmsLifecycleDeletedEmployeeTest` (2) fails before / passes after; `HrmsLifecycleApiTest` 11/11, `HrmsLifecycleServiceTest` 19/19; Playwright hrms-09 12/12 |

## Valid business rules (documented, unchanged)

- Required fields on forms use native browser validation (`required`, `type=email`), so the app text is the browser's.
- Duplicate department names are allowed (`OrgNaming::uniqueSlug` gives each its own slug).
- A department is **deactivated** (struck through, "inactive"), never deleted; designations and locations have no delete in the UI.
- The org-chart filter **dims** non-matches instead of hiding them.
- The Upload document button stays disabled until employee, title, type and file are set.
- `GET /api/hrms/documents` is self-scoped for an Employee: 200 with only their own files (proven with a document uploaded for another employee). The page `/hrms/documents` itself needs `hrms.documents.view`.
- My files: a document of a **sensitive type** (e.g. Bank Proof) is stored `confidential`, and `EmployeeDocumentPolicy` says being the person in the file does not grant `view_sensitive`, so an employee's own sensitive upload is accepted (201, toast "HR will review it.") but never listed back to them; a non-sensitive type (e.g. Educational Certificate) is listed Pending. List query and policy agree.
- Onboarding: the Create-template and Start-case buttons stay disabled until their inputs are filled; templates cannot be deleted (only switched off with Active); a case can be cancelled.
- Offboarding: Open exit stays disabled until employee + last working day are set; a second open exit for the same employee is refused; Clear exit asks "Sign off exit?" first and Complete needs a signed clearance.
- Completing an exit signs off the paperwork only; the employment status is **not** changed by it. Terminating an employee (`POST employees/{id}/terminate`) sets status Terminated, stamps the exit date and **opens an exit case automatically**; a second termination is refused (422).
- A manager can be set from the employee's Reporting line; Save is disabled when nothing changed; a reporting loop (A manages B, then B's manager = A... reversed) is refused.
- The SPA has no delete button for employees (exit is Offboarding); delete exists only as a soft-delete API (admin 200, Employee 403).

## Permission findings

- Employee persona: 403 page naming the missing permission on `/hrms/employees`, `/hrms/org`, `/hrms/org-chart`, `/hrms/documents`; API 403 on employees, org-chart; delete employee 403.
- HR Executive: can open employees, org, org-chart, documents.

## Environment notes / blockers

- **RESOLVED by the coordinator (original `APP_KEY` restored, sessions cleared, sign-in works).** It had blocked tabs 7-9: nobody could sign in. `POST /api/auth/login` returns 500 `DecryptException: The MAC is invalid` for every user (admin, Employee), and every saved session returns 401. The server log shows it fails reading `Tenant.db_user` (an encrypted cast on the central `tenants` row, `TenantDatabaseManager::dsn()` via `AuthController::loginIsolated`). The running app's `APP_KEY` no longer matches the key that encrypted the tenants' DB credentials. All containers show "Up ~25 min", so the stack was recreated/restarted during this session (not by this agent). Needs the original `APP_KEY` restored, or the tenants re-provisioned, by whoever owns the environment. I did not read or change keys, restart containers or touch the database.

- `APP_DEBUG` is on locally: a 403 JSON body includes the exception class and a server file path. Confirm it is off in production.
- Saved login cookies expire; `helpers-workforce.js` `useFreshSession` re-signs in once when `/api/auth/me` is dead (login is throttled).
- Several forks share one tenant, so record counts change; specs avoid count assertions.
- Playwright runs from different agents wipe a shared `.results` dir; each run here uses its own `--output`.

## Test data left behind (undeletable through the UI)

- Departments `QA-WF-Dept *` (all deactivated), designations `QA-WF-Desig *`, locations `QA-WF-Loc *` (no delete exists).
- Employees `QA-WF-*` were soft-deleted by the delete / cleanup tests (employees `QA-WF-Hire *`, `QA-WF-Leaver *`).
- Onboarding templates `QA-WF-Template *` (cannot be deleted; not all were switched off) and their cases, plus documents deleted by the tests.
- QA Employee now reports to QA HR Executive (set through the UI; useful data for later tabs).

## Improvement ideas (not defects)

| Idea | Business value | Priority |
|---|---|---|
| Warn when a department/designation/location name already exists | Avoids accidental duplicates | Low |
| Allow archiving designations and locations, as departments can be deactivated | Keeps pickers tidy | Medium |
| Employee list search with a debounce | Fewer requests per keystroke | Low |
| Org-chart toggle labelled "−1" has no accessible name | Screen-reader clarity | Low |
| After an employee uploads a sensitive-type file, tell them it was received (it never appears in My files) | Removes the "did it work?" doubt | Medium |
| Theme Settings drawer is a `role=dialog` present in the DOM when closed | Confirm it is hidden from assistive tech | Low |

## Shared files changed (outside my own specs)

| File | Why |
|---|---|
| `app/Support/Hrms/EmployeeCodeGenerator.php` | WF-1 |
| `resources/js/pages/hrms/Employees.jsx` | WF-2 |
| `resources/js/services/api.js` | WF-3 (skips logging cancelled requests; 2 added lines) |
| `resources/js/pages/hrms/OrgChart.jsx` | WF-4 |
| `resources/js/locales/en/hrms.json`, `resources/js/locales/es/hrms.json` | WF-5 (3 keys added to the `offboarding` block, one insert each, after `stInitiated`) |
| `app/Models/Hrms/Lifecycle/OffboardingCase.php`, `OnboardingCase.php` | WF-6 (`withTrashed()` on `employee()`) |

New tests: `tests/Feature/HrmsLifecycleDeletedEmployeeTest.php`, `resources/js/pages/hrms/OffboardingCases.test.jsx`, `tests/Feature/HrmsEmployeeCodeSoftDeleteTest.php`, `resources/js/pages/hrms/Employees.test.jsx`, `resources/js/services/api.test.js`, and a case added to `resources/js/pages/hrms/OrgChart.test.jsx`.
