# 05 — Auth, RBAC & Impersonation

Session-based auth. No Breeze/Fortify/Sanctum. Session driver `database` on the
central DB (see `.env`: `SESSION_DRIVER=database`).

## Login (`AuthController::loginIsolated`)

1. Normalize email (`Str::lower(trim())`), look up central `tenant_users` routing.
2. Optional `tenant` slug disambiguator — >1 route without slug ⇒ 422 `tenant`.
3. Strip `tenant` from credentials (`Arr::except`) before `Auth::attempt` (else
   `where tenant = ?` against `users` fails).
4. Super admins fall back to the system DB.
5. `establishTenantSession()` builds the `me()` payload **inside**
   `TenantDatabaseManager::using($tenant)` so `$user->load('roles')` hits that
   tenant's DB.

**Lower-case emails at creation** — `Auth::attempt` matches `users.email`
case-sensitively while routing is lowercased; mixed-case stored emails become
casing-locked. `UserController::store` normalizes before validation.

## Permission layers

- Tenant level: `config/permissions.php` — tenant permission catalog with own/assigned/all scope variants
  for the 13 HRMS domains that have a self concept (e.g. `hrms.leave.view_own`, `view_assigned`, `view_all`).
  System roles: `admin` (`*`), `manager` (`*_own` + `*_assigned`), `editor`/`viewer` (`*_own` subsets).
- Project level: `config/project_roles.php` — project roles (lead `*`, developer, viewer, plus custom roles)
  with `tasks` scope variants `view|edit|delete|move|assign × {own,assigned,all}` (35 slugs).
- Scope lattice: `app/Support/PermissionScope` resolves scope satisfaction (`own ⊂ assigned ⊂ all`).
  A grant of `_all` satisfies `_assigned` and `_own`. Legacy unsuffixed slugs default to `_all` (except
  `hrms.payroll.view` which maps to `_own` only). `User::granted()` and `ProjectRole::grants()` perform
  scope-aware checks while `hasPermission()` performs exact string matching.
- Manager scope resolution: `App\Services\ReportsTo::idsFor(User)` resolves direct reports' user IDs via
  `employees.manager_id` (one hop, memoized per request/job, flushed on lifecycle boundaries).
- TMS row scoping: `App\Support\TaskScope` provides the single shared definition for `TaskPolicy` per-row
  checks and `TaskService::board/list` query scoping (`own` = assignee or reporter, `assigned` = caller or
  direct report, `all` = every project task).
- HRMS row scoping: `App\Services\Hrms\HrmsScope` provides the single shared definition for HRMS directory
  queries and per-record policies (`LeaveRequest`, `CompOffRequest`, `ExpenseClaim`, `EmployeeDocument`,
  `PerformanceGoal`, `AttendanceDay`). Client-supplied `employee_id` params intersect the caller's real scope.
- Enforcement: `EnsurePermission` route middleware + `Gate::define('permission')` (standardized refusal worded
  by `EnsurePermission::denial`) + Policies. Tenant admin = `workspaces.manage` bypass.

## Member-based access (non-admins see only what they belong to)

- **Workspaces/projects/tasks:** `WorkspacePolicy::view` (workspace member), `ProjectPolicy::view`
  (project member), `TaskPolicy::*` (project member **and** project role grants the required `tasks.*`
  scope for the row). `TaskController@index` requires `tasks.view_own` or higher, and scopes the board/list
  query via `TaskScope::constrainQuery`.
- **Lists return zero rows, not 403:** `WorkspaceService::listFor`, `ProjectService::listFor|listAll`,
  `ScopesVisibleTasks::visibleTaskQuery()` (search, dashboard, reports, analytics) filter
  `whereHas(members, user)` unless `workspaces.manage`.
- **Nested writes verify belonging:** status update/destroy, comment/work-log/attachment/dependency child
  routes 404 on foreign IDs.
- **HRMS self-service & manager visibility:** `view` covers self; callers with `_assigned` view own and direct
  reports; `_all` or `manage` views tenant-wide. Performance goals and check-ins explicitly permit the direct
  manager (`isManagerOf`).
- **Frontend mirrors, never enforces:** `ProtectedRoute` passes missing permission state to `/403` to name the
  exact missing slug; module-denied requests navigate to `/module-denied`; dashboard and reports show active
  task scope pills ('every task in the tenant' vs 'tasks in your projects').
- Regression: `tests/Feature/MemberAccessMatrixTest.php` (matrix of domains × {own, report, stranger} × scopes),
  `tests/Feature/MemberIsolationTest.php` (strict cross-project, workflow, and reporting line isolation).

## Protected default user

One `users.is_default` per tenant DB (partial unique index). Oldest admin (else
oldest user); maintained by `TenantProvisioner::ensureDefaultUser()` on every
provision. Cannot be deleted (`deleting` hook throws 422 `form`); `updateRoles`
refuses dropping `admin` from it; `makeDefault` requires admin target and goes
through the query builder (see `02-backend-conventions.md`).

## Impersonation (super admin → tenant user)

`POST api/impersonate` (`start`, super_admin) → session `impersonate =
[log_id, tenant_id, original_user_id, original_user_name]` + `Auth::login($target)`
+ session regenerate. `POST api/impersonate/stop` (auth only, works while
impersonating) resolves the SA from the **system DB** (`connectSystem()` first).

- **Tenant-local id collision:** every tenant's owner is local id 1 — `start`
  accepts optional `tenant_id` scoping the routing lookup; the SA UI always sends it.
- Cannot impersonate super admins. Rows in `impersonation_logs`
  (`super_admin_id`, `tenant_id`, `impersonated_user_id`, ip, started/ended_at).
- `me()`/`logout` include impersonation state; banner renders in SPA.

## Platform RBAC & settings (schema + minimal enforcement)

`platform_roles`/`platform_permissions` + pivots exist; `is_super_admin` remains the
master gate. `platform_settings` (`PlatformSetting::value()/bool()/set()`):
`app_name`, `public_registration` (gates `POST /register` — RegisterTest's
`enableRegistration()` must flip it), `default_plan_id` (must be active),
`maintenance_mode`. `SystemUsersController` (create SA), `AuditLogsController`
(merged central + impersonation feed), `SystemAnalyticsController` (capped 100-tenant
fan-out, 300s cache — cold hits are slow), `FeatureManagementController`
(module × plan grid → `plans.limits.modules`).

## Gotchas for new auth code

- Every user-creation path MUST write the `tenant_users` routing row
  (`UserController::store`, Register claim, `syncRouting`); `destroy` removes it.
  Regression: `tests/Feature/TenantUserCreationTest.php`.
- Password reset currently runs on the wrong DB for tenant users (see `10-security.md`).
- `DetectsPlatformUsers` concern: platform SA (SA + no tenant context) gets empty
  notifications / default theme (theme PUT not persisted — tenant table).
