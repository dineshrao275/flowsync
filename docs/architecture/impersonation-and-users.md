# Impersonation & Protected Users Architecture

## Impersonation (super admin → tenant user)
- Session key `impersonate` = `['log_id', 'tenant_id', 'original_user_id', 'original_user_name']`; then `Auth::login($target)` + `session()->regenerate()`.
- Controllers: `ImpersonationController@start` (super_admin | `POST api/impersonate`) and `@stop` (`POST api/impersonate/stop`, only `auth`, works while impersonating).
- **Tenant-local id collision gotcha:** `user_id` is a per-tenant DB id (every tenant's owner is local id
  1), so the routing lookup must be disambiguated by `tenant_id` — `start` accepts an optional
  `tenant_id` and scopes `TenantUserRouting` `where('tenant_id', …)`; the super-admin UI always sends it.
- Audit rows in `impersonation_logs` (`super_admin_id`, `tenant_id`, `impersonated_user_id`, `ip_address`, `started_at`, `ended_at`).
- Stop must resolve the original super admin from the system DB (`connectSystem()` before the lookup).
- Cannot impersonate super admins; `AuthController::me`/`logout` include impersonation state.
- **Controls (R7/R8):** `POST api/impersonate` requires a `reason` (8–500 chars) and takes `mode`
  (`read_only` default | `write`); both land on `impersonation_logs` with `expires_at`
  (`tenancy.impersonation.ttl_minutes`, default 30). `App\Support\ImpersonationGuard`, called from
  `SwitchTenant`, ends a lapsed session on its next request (401 `impersonation_expired`, log closed at
  `expires_at`), refuses every non-safe verb in `read_only` (403 `impersonation_read_only`), and even in
  `write` refuses roles/project-roles/users/my-subscription/billing/my-export/tenant-profile/onboarding/
  re-impersonation (403 `impersonation_blocked`); `impersonate/stop`, `auth/logout`, `broadcasting/auth` are
  always allowed. `tenants:close-impersonations` (every 10 min) closes sessions nobody touched again.
  `me()` carries `impersonation_mode` / `impersonation_expires_at`; the banner shows both; the SA UI collects
  the reason through `ImpersonateModal`. Tenant-admin audit rows (`AuditsTenantAdminActions`) carry
  `impersonator_id`. **Not yet attributed:** `ActivityLogger` and `HrmsAuditLogger` rows written while
  impersonating still show only the impersonated user (follow-up under R7).

## Protected default tenant user
- Migration `database/migrations/tenant/2026_09_26_000013_add_default_user_to_users_table.php` adds
  `users.is_default` (boolean, default false) **plus a partial unique index** so a tenant DB can have at
  most one default (`CREATE UNIQUE INDEX users_default_unique ON users (is_default) WHERE is_default = true`).
  The predicate **must** be `true`, never `1` — `is_default` is a boolean column and PostgreSQL rejects
  `boolean = integer` (sqlite silently accepts it, so this only explodes on the PG path). The migration is
  repair-safe (`Schema::hasColumn` + `DROP INDEX IF EXISTS` first) because `tenants:provision` re-runs
  migrations that failed partway.
- `TenantProvisioner::ensureDefaultUser()` runs on every `provisionIsolated()`: the default is the oldest
  **admin** (falling back to the oldest user) — so `owner@{slug}.test` is the default for freshly
  provisioned tenants.
- `App\Models\User` — `scopeDefault()`, `defaultUser()`, `fillable += is_default`, and a `deleting` hook that
  throws `ValidationException` (`form`) so the default can never be deleted by any code path.
- `UserController`: `updateRoles()` refuses to drop `admin` from the default user, `destroy()` refuses
  self-deletion, `makeDefault()` (422 `form`) requires the target to be an admin. `/api/users*` now sits in
  the **tenant_context group** (the users table lives in the tenant DB — a non-impersonating super admin
  gets 403 instead of a "no such table: roles" 500), and every user response carries `is_default`.
- `makeDefault()` must clear + set through the **query builder**, not `$model->update()`: shifting onto the
  user who is *already* the default is a no-op for Eloquent's dirty check, which would clear the flag and
  never re-set it, leaving the tenant with **no** default user (regression-tested in `DefaultUserTest`).
