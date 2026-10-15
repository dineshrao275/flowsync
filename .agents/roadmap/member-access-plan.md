# Member-Based Access + Own/All Permission Model — Implementation Plan

> Status: approved for execution. Mode: build (one reviewed commit per task on
> `refactor/work-hrms`, pushed immediately). Todos mirror phases A→E; `state.md`
> appended per commit; full suite only at phase gates (cross-cutting changes
> still gate per task per repo loop).

## 0. Diagnosis — why HRMS feels broken today

| # | Root cause | Evidence |
|---|---|---|
| 1 | **No employee row for most logins.** User creation never mints `employees.user_id`; only HR action or manual backfill does. Attendance/leave/comp-off/punch answer `404 no employment record`, while My-home/files/assets read `200 empty`. | `UserController.php:58-79` vs `AttendanceRecordsController.php:150-153` |
| 2 | **Subscription tab invisible to everyone.** Uncommitted tree work added `billing.view` to config + sidebar, but tenants were provisioned *before* the edit and the provisioner only seeds at provision time — live tenant DBs hold 59/60 slugs, `billing.view` missing, and `hasPermission` is exact-match (no wildcard eval). | Live PG check; `config/permissions.php:25`, `Sidebar.jsx:43` |
| 3 | **editor/viewer hold only `hrms.view`** — every admin surface 403s/hides by design. Correct, but combined with #1 it reads as "nothing works". | `config/permissions.php:124-140` |
| 4 | **Plan modules.** Starter ships zero `hrms.*`; failures render as generic `/403` with no explanation. | `EnsureModule.php:41-45` |
| 5 | **Own data masked.** Self `view` passes but `viewSensitive` fails without `view_sensitive`/`manage`, so PII arrives masked. | `EmployeePolicy.php:39-52` |

Locked decisions: **full catalog refactor** (own/all), **build manager scope now**, **map to existing pages** (no new Profile/Benefits pages).

## Phase A — Stabilise & unblock (no redesign)

1. **Employee auto-link on user creation**: after `User::create` in `UserController::store` (+ Register claim path), `Employee::firstOrCreate(['user_id' => $user->id], …)` with auto code (`EMP-{user_id}`) and default status. Run `hrms:backfill-employees --all` on the host for existing logins. Test: new user → employee row exists; attendance self-read 200.
2. **Repair `billing.view` everywhere**: `tenants:repair-permissions` step (or extended provision repair) that `firstOrCreate`s missing catalog slugs into every tenant DB and grants new slugs to roles whose config selectors match (admin's `*` picks it up automatically). Run on host → Subscription tab appears for admins. Test: seed-date-independent (delete slug, re-run repair, assert restored).
3. **Subscription route parity**: `App.jsx` `/subscription` currently auth-only while sidebar requires `billing.view` — add `permission="billing.view"` to the route; introduce **`billing.manage`** for mutations (replace bare `hasRole('admin')` with `hasRole('admin') || hasPermission('billing.manage')`, grant to admin). Fold in the uncommitted `MySubscriptionController`/`ExportController` work consistently and commit it here.
4. **Verify-only**: Users/Roles tabs + Create buttons already correctly gated (audit confirms). Add missing `/403` navigation to Users/Roles pages to match the WorkspaceDetail pattern.

## Phase B — Catalog refactor (own/all/assigned)

5. **New slug scheme** (additive first, cutover second): for each of ~25 domains, add `*.view_own`, `*.view_all`, `*.edit_own`, `*.edit_all`, `*.delete_own`, `*.delete_all`, plus `*.view_assigned` (manager-of scope). Semantics: `_own` = rows where you are assignee/owner/self; `_all` = every tenant row; `_assigned` = own + direct reports' rows; `.manage` keeps meaning manage-all (implies all). Keep legacy `.view` working during transition as alias of `_all` in a `Permission::isGranted`-style helper, then remove in cleanup.
6. **Default role mapping**: admin `*`; new `manager` role (or extended editor) gets `*_own` + `*_assigned` for team domains; viewer gets `*_own` where a self-concept exists; editor keeps current broad TMS grants mapped to `*_all`. Roles page: group permission checkboxes by domain (currently flat — unusable at ~150 slugs).
7. **Data migration per tenant DB**: for each role holding legacy `X.view`, grant `X.view_all` (+ `X.view_own`); custom UI roles mapped 1:1, never widened silently — produce a pre-migration report (`--dry-run`) listing every grant the migration will add, require `--force`.
8. **Backfill safety**: step 2's repair must also attach *new* slugs to matching selector roles (admin) without touching custom roles (config-driven `sync` only touches config-listed roles — verify, add test).

## Phase C — Backend enforcement

9. **TMS policies**: `TaskPolicy`/`ProjectPolicy`/`WorkspacePolicy` branch self (`assignee/reporter/owner`) vs all vs manager-of (`manager_id` reporting chain via `Employee::user_id`). Board/list/index filter by the same rule (not just `whereHas(members)`).
10. **HRMS policies + services**: extend the G1 pattern (goals/check-ins/1:1s already self-scope) to leave/expense/document/comp-off lists: non-viewer callers get own + assigned-reports rows. `*.view_assigned` resolves through the reporting line; logins with no employee row see own-only (empty, not 403).
11. **Controllers**: pass `employee_id` explicitly from My-pages (already started for performance); keep server-side intersection so spoofed params can't widen.
12. **Manager scope helper**: single `ReportsTo::idsFor(User)` (direct reports' user ids via `employees.manager_id`) shared by policies and services — one definition of "assigned".

## Phase D — Frontend

13. **Sidebar**: already capability-driven — add any new slugs; add an explanatory empty state when a whole section hides due to plan modules (today items just vanish).
14. **Buttons**: audit every mutation button for `can()` gating (pattern exists; extend to new slugs); permission-specific 403 messages on management surfaces (P21.4 pattern).
15. **Dashboard/Reports**: keep member-scoped aggregates; add a "showing: self / team / all" scope indicator driven by the caller's permissions so regulars understand why numbers differ from a manager's.
16. **Module-denied UX**: surface the server's "not in your plan" message instead of generic `/403` for `EnsureModule` rejections.

## Phase E — Verification, docs, rollout

17. **Test matrix** (new `MemberAccessMatrixTest` + extended `MemberIsolationTest`): per domain × {own row, other's row, report's row} × {no perm, own, assigned, all, manage} × {API status + sidebar visibility}. Full suite green required (cross-cutting: policies, config, middleware).
18. **Docs**: `AGENTS.md` policy section rewrite (own/all table), `.agents/05-auth-rbac.md` full update, `docs/` migration guide for custom roles, `state.md` per phase.
19. **Host rollout** (ordered): deploy → `tenants:repair-permissions --all` (all 102 tenant DBs) → backfill employees → verify demo logins (admin/editor/viewer/owner) + a viewer login sees only self data → report.

## Risks & mitigations

- **Custom roles referencing old slugs**: dry-run migration report + alias period; never auto-widen.
- **Reporting-line query cost**: `ReportsTo` resolves one hop, memoized per request (TenantLimits memo pattern); no recursive CTEs.
- **`sync()` clobbering UI-customized system roles**: restrict config-sync to slug *additions*, never removals, on repair path (test-pinned).
- **Scope**: ~25 domains × (catalog + policy + service + UI + tests). Phases A→E sequential, one reviewed commit per task on `refactor/work-hrms`, pushed immediately. Full suite only at phase gates.
