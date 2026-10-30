# HRMS UI test checklist (generated from `resources/js/utils/hrmsNav.js`)

Test tenant: **globex** (`owner@globex.test`, no subscription => every module), local Docker only. Status is updated only after a test is executed and verified.

| # | Path | Group | Gating capabilities | Status |
|---|---|---|---|---|
| 1 | `/hrms` | overview | m:hrms.core | **Pass** — admin + Employee, 4 UI tests; BUG-1 fixed |
| 2 | `/hrms/inbox` | workforce | m:hrms.core | Not run |
| 3 | `/hrms/team` | workforce | m:hrms.core | Not run |
| 4 | `/hrms/employees` | workforce | m:hrms.core, p:hrms.employees.view | Not run |
| 5 | `/hrms/org` | workforce | m:hrms.core, p:hrms.org.view | Not run |
| 6 | `/hrms/documents` | workforce | m:hrms.core, p:hrms.documents.view | Not run |
| 7 | `/hrms/documents/mine` | workforce | m:hrms.core | Not run |
| 8 | `/hrms/onboarding` | workforce | m:hrms.core, p:hrms.onboarding.view | Not run |
| 9 | `/hrms/offboarding` | workforce | m:hrms.core, p:hrms.offboarding.view | Not run |
| 10 | `/hrms/attendance` | time | m:hrms.core, m:hrms.attendance | Not run |
| 11 | `/hrms/attendance/approvals` | time | m:hrms.core, m:hrms.attendance, p:hrms.attendance.regularize | Not run |
| 12 | `/hrms/leave` | time | m:hrms.core, m:hrms.leave, p:hrms.leave.manage | Not run |
| 13 | `/hrms/leave/mine` | time | m:hrms.core, m:hrms.leave | Not run |
| 14 | `/hrms/comp-off` | time | m:hrms.core, m:hrms.comp_off, p:hrms.comp_off.manage | Not run |
| 15 | `/hrms/comp-off/mine` | time | m:hrms.core, m:hrms.comp_off | Not run |
| 16 | `/hrms/holidays` | time | m:hrms.core, m:hrms.holidays | Not run |
| 17 | `/hrms/shift-swaps` | time | m:hrms.core, m:hrms.shifts | Not run |
| 18 | `/hrms/shifts` | time | m:hrms.core, m:hrms.shifts | Not run |
| 19 | `/hrms/compensation` | pay | m:hrms.core, m:hrms.compensation, p:hrms.compensation.view | Not run |
| 20 | `/hrms/payroll` | pay | m:hrms.core, m:hrms.payroll, p:hrms.payroll.run | Not run |
| 21 | `/hrms/payroll/mine` | pay | m:hrms.core, m:hrms.payroll | Not run |
| 22 | `/hrms/statutory` | pay | m:hrms.core, m:hrms.payroll.statutory | Not run |
| 23 | `/hrms/expenses` | pay | m:hrms.core, m:hrms.expenses, p:hrms.expenses.view | Not run |
| 24 | `/hrms/expenses/mine` | pay | m:hrms.core, m:hrms.expenses | Not run |
| 25 | `/hrms/performance` | performance | m:hrms.core, m:hrms.performance, p:hrms.performance.view | Not run |
| 26 | `/hrms/performance/mine` | performance | m:hrms.core, m:hrms.performance | Not run |
| 27 | `/hrms/engagement` | performance | m:hrms.core, m:hrms.engagement, p:hrms.engagement.view | Not run |
| 28 | `/hrms/engagement/mine` | performance | m:hrms.core, m:hrms.engagement | Not run |
| 29 | `/hrms/skills` | performance | m:hrms.core, m:hrms.talent | Not run |
| 30 | `/hrms/talent` | performance | m:hrms.core, m:hrms.talent, p:hrms.talent.manage | Not run |
| 31 | `/hrms/assets` | records | m:hrms.core, m:hrms.assets, p:hrms.assets.view | Not run |
| 32 | `/hrms/resignation` | records | m:hrms.core, m:hrms.offboarding | Not run |
| 33 | `/hrms/exit-interview` | records | m:hrms.core, m:hrms.offboarding | Not run |
| 34 | `/hrms/automation` | records | m:hrms.core, m:automation, p:hrms.onboarding.manage | Not run |
| 35 | `/hrms/careers` | records | m:hrms.core, m:hrms.talent | Not run |
| 36 | `/hrms/signatures` | records | m:hrms.core, m:hrms.documents | Not run |
| 37 | `/hrms/okrs` | records | m:hrms.core, m:hrms.performance | Not run |
| 38 | `/hrms/development-plans` | records | m:hrms.core, m:hrms.performance | Not run |
| 39 | `/hrms/improvement-plans` | records | m:hrms.core, m:hrms.performance | Not run |
| 40 | `/hrms/loans` | records | m:hrms.core, m:hrms.payroll | Not run |
| 41 | `/hrms/letters` | records | m:hrms.core, m:hrms.documents, p:hrms.documents.manage | Not run |
| 42 | `/hrms/org-chart` | workforce | m:hrms.core, p:hrms.employees.view | Not run |
| 43 | `/hrms/changes` | records | m:hrms.core | Not run |
| 44 | `/hrms/assets/mine` | records | m:hrms.core, m:hrms.assets | Not run |
| 45 | `/hrms/approvals/chains` | records | m:hrms.core, p:hrms.approvals.manage | Not run |
| 46 | `/hrms/analytics` | insight | m:hrms.core, m:hrms.analytics, p:hrms.analytics.view | Not run |
| 47 | `/hrms/audit` | insight | m:hrms.core, p:hrms.audit.view | Not run |


## Bug register

| ID | Tab | Defect | Root cause | Fix | Retest |
|---|---|---|---|---|---|
| BUG-1 | `/hrms` | Headline said "35 modules enabled" while 21 tiles were shown | `HrmsOverview.jsx` counted `user.modules.length`, which includes TMS keys | Count the tiles shown | Vitest `HrmsOverview.test.jsx` + Playwright count test pass |

## Environment notes

- Acme (`admin@flowsync.test`) is on the `starter` suite plan with no HRMS modules, so the whole HR sidebar is locked. That is plan data, not a defect. Globex has no subscription and gets every module, so it is the HRMS test tenant.

## Test personas (created through the real Add user form, Globex)

| Persona | Email | Role |
|---|---|---|
| Tenant admin | `owner@globex.test` | admin, editor, hr_manager, payroll_manager, finance_admin |
| QA Employee | `qa.employee@globex.test` | Employee |
| QA HR Executive | `qa.hrexec@globex.test` | HR Executive |

Creating a user also creates its employee record, so the Overview setup checklist counts them; tests must not assume an empty directory.
The login endpoint is throttled (`Too Many Attempts` after a burst, working as designed), so specs reuse a saved session (`helpers.js` `useSession`).

## Leads from the Employee route scan (NOT confirmed bugs; verify in each tab)

Typed-URL gating works: every restricted page answers a 403 naming the missing permission. These need a decision in their own tab:

- **Holidays (`/hrms/holidays`)**: sidebar shows it to an Employee, but the page is a full "Access denied" and `hrms/holidays/calendars` + `/assignments` return 403. Nav entry has no permission, API does.
- **My performance (`/hrms/performance/mine`)**: shown in the sidebar, page is "Access denied" (`hrms/employees`, `hrms/performance/cycles` 403).
- Pages that render but fire a 403 background call (possible console noise or half-empty UI): `shift-swaps`, `statutory`, `expenses/mine` (`expenses/categories`), `careers`, `improvement-plans`, `changes` (all `hrms/employees`).
- The Theme Settings drawer is a `role=dialog` present in the DOM when closed (a11y: check it is hidden from assistive tech).
