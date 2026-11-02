# HRMS UI QA — Insight group, shell and a11y (results)

Scope: `/hrms/analytics` (checklist row 46), `/hrms/audit` (row 47), the HRMS shell, and the Theme drawer a11y lead.
Environment: local Docker stack only (`http://localhost:8000`), tenant **globex**, never production.
Personas: `owner@globex.test` (admin), `qa.employee@globex.test` (Employee), `qa.hrexec@globex.test` (HR Executive),
`qa.in.analytics@globex.test` (custom role: `hrms.view` + `hrms.analytics.view` + `hrms.attendance.view`),
`qa.in.audit@globex.test` (custom role: `hrms.view` + `hrms.audit.view`). Test records are prefixed `QA-IN`.

## Status

| Row | Tab / area | Status | Evidence |
|---|---|---|---|
| 46 | Analytics | **Pass** (after 3 fixes) | `tests/e2e/hrms-46-analytics.spec.js` |
| 47 | Audit log | **Pass** (after 1 fix) | `tests/e2e/hrms-47-audit.spec.js` |
| — | HRMS shell | **Pass** | `tests/e2e/hrms-shell.spec.js` |
| — | Theme drawer a11y | **Pass** (after 1 fix) | shell spec + `ThemeSettingsDrawer.test.jsx` |

Final counts are in the "Execution log" at the bottom.

## Bug register (confirmed defects, all fixed and retested)

| ID | Where | Role | Operation | Expected | Actual | Root cause | Fix | Retest |
|---|---|---|---|---|---|---|---|---|
| IN-1 | Analytics → Overview (and the `overview` payload) | admin | Read | Headcount tiles load | HTTP 500, "Unable to load analytics" on **PostgreSQL** (sqlite passes) | `AnalyticsReading::employees()` orders by `employees.id`; `HeadcountReading::breakdown()` then `GROUP BY` on that builder, which PostgreSQL rejects (42803) | `reorder()` before grouping in `HeadcountReading` | `HrmsAnalyticsApiTest::test_headcount_breakdowns_never_order_a_grouped_query_by_a_bare_column` (SQL-shape pin, failed first) + UI tab loads |
| IN-2 | Analytics → Overview/Lifecycle | admin | Read, once employees have a joining date | Lifecycle tiles load | HTTP 500 `Return value must be of type int, float returned` | Carbon 3 `diffInDays()` is a signed float; three closures in `LifecycleReading` were typed `int` | `(int) …diffInDays($other, true)` (absolute whole days, the old semantics) | `HrmsAnalyticsApiTest::test_lifecycle_time_to_first_day_survives_fractional_day_differences` (failed first) |
| IN-3 | Analytics (all cached tabs) | any tenant admin | Read | A tenant sees only its own numbers | Tenant B was served tenant A's cached dashboard for up to 300 s | Cache key `hrms.analytics.<method>.<md5(filters)>` had no tenant, and production keeps the cache in the shared **system** DB (`DB_CACHE_CONNECTION=system`) | Key now carries `TenantContext::currentId()` (the `UsageMeter` precedent) | `HrmsAnalyticsApiTest::test_cached_analytics_never_cross_tenants` (globex got acme's count first, now its own) |
| IN-4 | Audit log → search box (`q`), and the data-access ledger search | admin | Filter | Case-insensitive literal substring | On PostgreSQL `q=EMPLOYEE` matched nothing (LIKE is case-sensitive); `q=%` or `q=_` matched **every** row | Hand-built `%term%` instead of the shared `App\Support\Like` helper (`ILIKE` on PG, wildcards escaped) | `Like::any()` in `AuditLogQuery` and `DataAccessQuery` | `HrmsAuditApiTest::test_free_text_search_treats_wildcards_literally_and_ignores_case_via_the_shared_like_helper` (failed first) + UI search test |
| IN-5 | Theme Settings drawer (every page) | any | Keyboard / screen reader | A closed drawer is not exposed | The closed drawer was only translated off-screen: still `role=dialog` in the accessibility tree and its controls were tabbable | Missing `inert` / `aria-hidden` / `visibility` when closed | `inert`, `aria-hidden`, `invisible` (transitioned) when closed; `aria-modal` when open | `ThemeSettingsDrawer.test.jsx` (failed first) + Playwright drawer test |

## Valid business restrictions (documented, not changed)

- **CSV export is tenant-admin only** (`workspaces.manage`, controller gate) and the Export button is hidden for everyone else, including roles that can view the tab. Rationale in the controller: "viewing a chart never implied taking the dataset home". Each export writes one `accessed(…, Export)` data-access row (verified in the UI test).
- **The Analytics page needs `hrms.analytics.view`; each tab API answers to its own permission.** A role with only `hrms.attendance.view` / `documents.view` / `assets.view` (the built-in HR Executive) is refused the page but those three tab APIs answer 200 directly. Pinned in the spec.
- **Analytics values are cached for 5 minutes per tenant and filter set**, so tiles can lag the live directory briefly.
- The **audit ledger is read-only end to end**: no UI mutation controls, and POST/PUT/PATCH/DELETE on `/api/hrms/audit*` answer 404/405.
- The `/hrms/audit/{subjectType}/{id}` trail returns an empty list for an unknown subject (200), and 422 for a subject type over 255 characters.
- Login is throttled ("Too Many Attempts"); the specs reuse one saved session per persona.

## Findings outside this scope (reported, NOT changed — other tabs' files)

Same defect class as IN-3: cache keys that omit the tenant while using tenant-local ids, in a shared system-DB cache.
`UsageMeter` and `NotificationDispatcher` already do it correctly with `TenantContext::currentId()`.

| File | Key | Risk |
|---|---|---|
| `app/Services/Hrms/InboxService.php:67` | `hrms.inbox.unread.{$user->id}` | user ids are per-tenant, so tenant B sees tenant A's unread **count** for the same local id (30 s) |
| `app/Services/Hrms/MyHrService.php:54` | `hrms.myhr.{$user->id}` | the cached **My HR payload** (leave balances, etc.) can cross tenants for equal local user ids (60 s) — the more serious one |
| `app/Services/Hrms/Survey/SurveyResults.php:32` (`resultsKey`) | campaign id | survey results (anonymous aggregates) can cross tenants for equal campaign ids (300 s) |

Also: `EmployeeDirectoryQuery.php:154` and `Talent/SkillService.php:116` escape LIKE with a backslash (`\%`), which works on PostgreSQL but not on sqlite and bypasses the shared `Like` helper.

## Coverage gaps and improvement list (separate from defects)

| # | Item | Business value | Priority |
|---|---|---|---|
| 1 | **Department / designation / location writes write no audit row** (`DepartmentService` etc. have no `HrmsAuditLogger` call; only `OrgUnitService` and dotted lines do). Org-structure changes are invisible in the audit log. | Compliance / traceability of org changes | High |
| 2 | The data-access ledger (`/hrms/audit/data-access`: who viewed or exported salary, documents, analytics) has an API but **no UI**; the only way to read it is by hand. | Makes sensitive-read auditing usable | High |
| 3 | Analytics and audit show a generic "Unable to load…" for a 422 (e.g. To before From). Surface the field error inline. | UX clarity | Medium |
| 4 | Audit page has no CSV export and no "subject id" filter in the UI (API supports `subject_id`). | Investigations | Medium |
| 5 | Date fields on both pages are text-style pickers; there is no client-side `To >= From` guard. | Prevents avoidable 422s | Low |
| 6 | The analytics cache lag (5 min) is not shown anywhere; consider an "as of" stamp or a refresh control. | Trust in numbers | Low |
| 7 | The sidebar's unread badge is part of the group button's accessible name ("Workforce 4") with no unit; label it ("4 unread"). | Screen-reader clarity | Low |

## Execution log

| Suite | Result |
|---|---|
| Playwright `hrms-46` (Analytics, 19 tests) | 19 pass |
| Playwright `hrms-47` (Audit, 14 tests) | 14 pass |
| Playwright `hrms-shell` (8 tests) | 8 pass (the last combined run was 39/41; the 2 failures were a selector that did not allow the sidebar's unread badge, fixed and re-run green) |
| `HrmsAnalyticsApiTest` | OK (8 tests, 27 assertions; +4 new: IN-1, IN-2, IN-3) |
| `HrmsAuditApiTest` | OK (6 tests, 37 assertions; +1 new: IN-4) |
| `HrmsAnalyticsTest`, `HrmsAnalyticsExportTest`, `HrmsAuditLoggerTest`, `HrmsSensitiveReadAuditTest` | OK (6, 4, 47, 5 tests) |
| `tests/Unit` (incl. class-size and architecture ratchets) | OK (110 tests) |
| Vitest, whole suite | 346/347; the one failure (`extractedModules.test.jsx` Tenants) is a load timeout and passes alone (21/21) |
| `npm run lint` | 0 errors, 122 warnings (unchanged) |
| `./vendor/bin/pint --test` | pass |

Persistent test fixtures (idempotent, safe to leave): roles `qa-in-analytics-viewer` and `qa-in-audit-reader` with users `qa.in.analytics@globex.test` and `qa.in.audit@globex.test` (password `password`). Audit rows from the QA-IN salary-component create/delete pairs remain by design (the ledger is append-only). QA-IN saved filters were deleted.

Not verified: the data-access ledger has no UI so only its API was exercised; no acme/other-tenant browser session was run against Globex (isolation is proven at the PHP level for the cache and by actor/name checks in the UI).

Commands:

```
npx playwright test hrms-46 hrms-47 hrms-shell
php artisan test --parallel tests/Feature/HrmsAnalyticsApiTest.php   # one file per invocation
php artisan test --parallel tests/Feature/HrmsAuditApiTest.php
npx vitest run resources/js/components/ThemeSettingsDrawer.test.jsx
./vendor/bin/pint --test && npm run lint
```
