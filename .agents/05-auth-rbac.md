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

- Tenant level: `config/permissions.php` — 12-permission catalog; roles
  `admin` (`*`), editor/viewer subsets (incl. `workspaces.view|create|manage`,
  `dashboard.view`, `reports.view`).
- Project level: `config/project_roles.php` — lead (`*`)/developer/viewer system
  roles (immutable, undeletable) + custom roles; permissions
  view/create/edit/delete/assign/move/comments/attachments/work_logs/members/settings.
- Workspace membership: owner/admin/member pivots.
- HRMS: 47 permissions, per-record policies (self-service included — `view` covers
  self), set-scoping in services.
- Enforcement: `permission:` middleware + `Gate::define('permission')` SA bypass
  (unless impersonating) + Policies. Tenant admin = `workspaces.manage` bypass.

## Member-based access (non-admins see only what they belong to)

- **Workspaces/projects/tasks:** `WorkspacePolicy::view` (member any role), `ProjectPolicy::view`
  (project member — workspace membership alone is not enough), `TaskPolicy::*`
  (project member **and** the role grants the `tasks.*` slug, resolved against
  the task's own project). `TaskController@index` additionally requires the
  caller's project-role grants `tasks.view` (403 like a per-row show), so the
  board/list pools are not a back door around it.
- **Lists return zero rows, not 403:** `WorkspaceService::listFor`,
  `ProjectService::listFor|listAll`, `ScopesVisibleTasks::visibleTaskQuery()`
  (search, dashboard, reports, analytics, work-log aggregates) all filter
  `whereHas(members, user)` unless `workspaces.manage`. Task board/list 403
  instead (project `view` first) — both shapes deny by default.
- **Nested writes verify belonging:** status update/destroy, comment/work-log/
  attachment/dependency child routes 404 on a foreign child id; the URL
  `{project}` on single-task routes is decorative (the policy follows
  `$task->project`), never a second gate.
- **HRMS self-service:** `view` covers self everywhere; list endpoints that
  non-viewers can reach self-scope server-side (goals, check-ins, 1:1s,
  leave/comp-off requests, expenses — the feedback-request pattern), never
  client-side filtering alone. Performance `view` on a single row additionally
  allows the manager-of and the broad `performance.view|talent.manage` holders.
- **Frontend mirrors, never enforces:** sidebar `capabilities`, `ProtectedRoute`
  permission/module gates, and per-tab `can()` checks hide; every hidden
  surface still 403/404s server-side on direct URL (project pages navigate to
  `/403`, task loads surface an inline error). No `My/*` page trusts a query
  param for identity — the caller resolves from the login.
- Regression: `tests/Feature/MemberIsolationTest.php` (foreign status 404,
  sight-only board/list/show 403, performance self-scope incl. spoofed filter).

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
