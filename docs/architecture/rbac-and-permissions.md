# RBAC, Permissions & Scopes Architecture

## RBAC & provisioning
- `config/permissions.php` — the canonical permission catalog (base slugs plus the generated scope
  variants below; ~99 entries today) + role definitions: `admin` = `*`, `editor`/`viewer` =
  self-service reads (`*_own`) plus broad non-HRMS grants, **`manager`** = `*_own` + `*_assigned`
  (team scope, stops at the reporting line), `hr_manager`/`payroll_manager` = prefix selectors. **R4 adds the named defaults the product brief uses:** `employee` (self-service `*_own` only), `hr_executive` (day-to-day HR, no payroll/compensation/sensitive documents), `finance_admin` (expenses + tenant-wide payslip/compensation reads + billing view), `auditor` (read-only views + audit trail + analytics, no `.manage`, no sensitive reveal). `editor`/`viewer` remain the legacy names existing tenants assign; `tenants:provision` creates the new roles in existing tenants and reassigns **no** user.
  A role's `permissions` is a list of *selectors* (`*`, `hrms.*`, `!hrms.payroll.*`, exact slug)
  resolved against the catalog by `app/Support/PermissionSelector` at provision time and
  snapshotted onto the role.
- **Scope variants (member-access plan, Phase B — additive and inert until Phase C).** A base
  permission with a "my rows" reading gets three suffixes, generated from `$scopeDomains` in the
  same config so a new domain is one line: `X.view_own` (rows about you), `X.view_assigned` (you +
  your direct reports' rows), `X.view_all` (every row). `app/Support/PermissionScope` answers
  *which grants satisfy a scoped check* — a wider scope, or the legacy unsuffixed slug, which has
  always meant `all`; `User::granted($slug)` is the scope-aware check while `hasPermission()`
  stays exact-match, so nothing changes for callers outside the `scopes` map. The single legacy
  exception is `hrms.payroll.view` (always "runs + own payslip" → answers `_own` only, declared in
  `legacy_scope_aliases`). `.manage` is deliberately **not** in the lattice: folding it in would
  widen e.g. `hrms.payroll.manage` into "read every payslip". `App\Services\ReportsTo::idsFor(User)`
  is the single definition of `_assigned` (one hop down `employees.manager_id`, direct reports' user
  ids; memoized per unit of work like `TenantLimits`, flush wired into the same boundaries). **The
  Phase C enforcement cutover (steps 9–11) is shipped**: `TaskScope` (Row scoping section) and HRMS's
  `App\Services\Hrms\HrmsScope` deploy the lattice — `widestView()`/`seesAll()`/`canRead()`/
  `employeeIdsFor()`/`coversEmployee()` are the one shared answer for every HRMS list and per-record
  policy (leave/expense/document/comp-off flipped; the attendance-month/leave-balance/comp-off-credit
  employee-keyed reads and the performance goal/check-in/1:1/feedback lists+show all intersect a
  client-supplied `employee_id` with the caller's real scope — spoofed params resolve to `[]`/403,
  never wider; leave exemptions + the leave team calendar stay
  manage-only / subtree-team by design). Before the alias is ever removed, `tenants:scope-grants
  --force` makes the implicit explicit: every role holding a legacy scoped base gets the variant(s)
  it has always meant (`X.view_all` + `X.view_own`; payroll `view_own` only), idempotently
  (`ScopeGrantBackfill`, `syncWithoutDetaching`).

### Own/Assigned/All Policy & Scope Matrix

| Domain | Grant Base | `_own` Scope | `_assigned` Scope | `_all` Scope | Policy / Service Enforcement |
|---|---|---|---|---|---|
| Tasks (TMS) | `tasks.view\|edit\|delete\|move\|assign` | Assignee or reporter = caller | Caller + direct reports (`ReportsTo::idsFor()`) | All project tasks | `TaskScope::rowMatches()`, `TaskScope::constrainQuery()`, `TaskPolicy` |
| Leave Requests | `hrms.leave.view` | Caller's own requests | Caller + direct reports' requests | All tenant requests | `HrmsScope`, `LeaveRequestDirectory::listFor`, `LeaveRequestPolicy` |
| Expense Claims | `hrms.expenses.view` | Caller's own claims | Caller + direct reports' claims | All tenant claims | `HrmsScope`, `ExpenseClaimController::index`, `ExpenseClaimPolicy` |
| Documents | `hrms.documents.view` | Caller's own files | Caller + direct reports' files | All non-confidential tenant files | `HrmsScope`, `DocumentService::listFor`, `EmployeeDocumentPolicy` |
| Performance Goals | `hrms.performance.view` | Caller's own goals | Caller + direct reports' goals | All cycle goals | `HrmsScope`, `PerformanceGoalController::index`, `PerformanceGoalPolicy` |
| Check-Ins & 1:1s | `hrms.performance.view` | Caller's own sessions | Caller + direct reports' sessions | All cycle sessions | `HrmsScope`, `CheckInController`, `OneOnOneController` |
| Attendance & Records | `hrms.attendance.view` | Caller's own month grid | Caller + direct reports' grids | All tenant employee grids | `HrmsScope`, `AttendanceRecordsController`, `AttendanceDayPolicy` |
| Comp-Off Requests | `hrms.comp_off.view` | Caller's own credits/asks | Caller + direct reports' credits/asks | All tenant comp-off asks | `HrmsScope`, `CompOffRequestDirectory`, `CompOffRequestPolicy` |
| Payroll & Payslips | `hrms.payroll.view` | Caller's own payslips | Caller's own payslips (1:1, never widened) | All tenant payslips (`view_all` explicit) | `PayslipPolicy`, `PayrollRunController`, `PermissionScope::legacyScope()` |

- `config/project_roles.php` — project-permission catalog (view/create/edit/delete/assign/move/comments/attachments/work_logs/members/settings + `tasks` scope variants `view|edit|delete|move|assign × {own,assigned,all}`, exported as `scope_domains`/`scope_text`; 35 slugs) + system roles lead/developer/viewer.**Project-role resolution is scope-aware like the tenant catalog** — `ProjectRole::hasPermission()` stays literal (`['*']` → all) while `ProjectRole::grants()` answers `satisfying()` like `User::granted()`, so a `tasks.view_all` grant satisfies a `tasks.view_own` request and the legacy `tasks.view` means `_all`. The default roles deliberately keep the UNSCOPED slugs (`legacy = _all`); narrowing a team is what a custom role is for. `TenantProvisioner::provisionProjectRoles()` now UNION-adds configured slugs into system roles (additions only, never revoke; `['*']` on either side wins) so a pre-variant provisioned role picks up new slugs on repair.
- `app/Support/TenantProvisioner.php` — clones catalog + roles + priorities + project roles + owner admin per tenant; **idempotent** (`firstOrCreate`, pins tenant context null internally), safe to call repeatedly. `seed()` gives a NEW config role its full permission set and thereafter only ever ADDS to an existing one (`syncWithoutDetaching` — a plain `sync()` used to revert tenant edits on every repair); config roles are flagged `roles.is_system` (migration `2026_10_19_000040`) and are **read-only through the API** — `PUT/DELETE roles/{role}` on one is a 422 `form`; a tenant customises via `POST roles/{role}/clone` (the copy needs the actor to hold every permission, `GrantCeiling`) and a custom role is deletable only while unassigned (`DELETE roles/{role}`; both audited) — custom tenant roles are never touched by provisioning — and `provisionProjectRoles()` **union-adds** config slugs into system project roles idempotently (additions only, `['*']` guard), so system roles pick up config additions on re-provision while a config edit can never revoke what a tenant already holds.
- **Access explainer (R14):** `GET api/users/{user}/access?check=<slug>` (`UserAccessController`, `permission:roles.view`) answers "why can't they?": which of the user's roles grant the slug — exactly, or through a wider scope / the legacy unsuffixed slug (`PermissionScope::satisfying`) — then the plan module the slug lives under (longest module key prefix; `hrms.*` falls back to `hrms.core`) via `TenantLimits::hasModule`, and a plain-language `reason`. Read-only. No SPA panel yet.
- `app/Models/Tenant.php`, `Role`, `Permission`, `User`; pivots `role_user`, `permission_role`.
- `TenantSeeder` = super admin + Acme (demo users) + Globex; called from `DatabaseSeeder`.
