# 04 — Database

Counts verified 2026-10-05: **12 system** migration files, **35 tenant** files,
**137** Feature test files, 43 HRMS SPA pages. `database/factories/` = stock
`UserFactory.php` only.

## System DB (`database/migrations/system/`, central connection)

| Migration | Tables |
|---|---|
| `0001…_create_system_users_table` | `users` (super admins only), `password_reset_tokens`, `sessions` |
| `…_create_cache_table` | `cache`, `cache_locks` |
| `…_create_jobs_table` | `jobs`, `job_batches`, `failed_jobs` |
| `000003_create_tenants_tables` | `tenants`, `impersonation_logs` |
| `000013_expand_tenants_for_saas` | alters `tenants` (status/provisioning/encrypted db creds/subscription/billing/trial/limits JSON/soft deletes) |
| `000014_create_platform_management_tables` | `platform_roles`, `platform_permissions`, `platform_role_permission`, `platform_user_role`, `audit_logs` |
| `000015_create_routing_and_provisioning_tables` | `tenant_users` (unique `(tenant_id,email)`), `provisioning_runs` |
| `000016_create_subscription_tables` | `subscription_plans`, `subscriptions` (**unique `tenant_id`** — one row/tenant), `subscription_events` |
| `000017_add_tenant_profile_fields` | alters `tenants` (profile/billing/contact/`onboarding_meta`) |
| `000018_create_platform_settings_table` | `platform_settings` key-value |
| `000019_create_website_pages_table` | `website_pages` (CMS) |
| `000014_add_hrms_to_plan_modules` (data) | adds `hrms.*` module keys to plan limits |

`tenants.subscription_id` FK lands **only on PostgreSQL** — sqlite gets a plain index
(can't ALTER ADD CONSTRAINT); app-level FK via the model.

## Tenant DB (`database/migrations/tenant/`)

TMS core: `roles/permissions/permission_role/role_user`, `users` (+`password_reset_tokens`,
`is_default` with **partial unique index `WHERE is_default = true`** — predicate must be
`true`, never `1`: PG rejects `boolean = integer`, sqlite silently accepts),
`user_settings`, `workspaces`, `workspace_members`, `project_roles`, `priorities`,
`projects`, `project_members`, `task_statuses`, `tasks` (SoftDeletes, `position`
decimal, unique `(project_id,key|sequence)`), `labels`, `task_label`, `comments`
(SoftDeletes), `attachments`, `work_logs`, `task_dependencies`, `activities`,
`notifications`. Index passes `000011` (task search) + `000012` (FK-composite hardening).

HRMS (one monolithic migration per phase, **pre-assigned numbers** — landing out of
order is safe, Migrator runs pending files in filename order): `000014` shared
(`approvals`, `approval_steps`, `hrms_audit_logs`, `hrms_data_access_logs`,
`hrms_settings`), `000015` employee, `000016` org, `000017` lifecycle, `000018`
attendance, `000019` leave, `000020` comp-off, `000021` holiday, `000022` payroll,
`000023` statutory, `000027` expense, `000028` performance, `000030` assets,
`000031` inbox reads, `000032` surveys, `000033` report schedules, `000034/000035`
task links. `employees.shift_id` added via raw SQL (see `02-backend-conventions.md`).

## Intentional duplications (do NOT "consolidate")

- `users` + `password_reset_tokens` exist in BOTH DBs: system = `SystemUser` (SA),
  tenant = login accounts. Same name, different connections. Wrong-connection queries
  500 — mitigated by middleware priority, still the #1 foot-gun.
- Tenant DBs run the full migration set incl. central-table clutter (acknowledged
  pre-cutover). Inflates provision time; not a correctness bug.
- Polymorphic pairs (`activities`, `audit_logs`, `hrms_audit_logs`, `hrms_task_links`
  morphs, `document_requests.(case_type,case_id)`) are bare integers + composite
  index by design — no DB integrity; application enforces.

## Index gaps to close (Phase 2 backlog)

`offboarding_case_tasks.expense_claim_id` (no index), `payroll/leave_adjustments.reference_id`
(no index), `approvals.approvable_*` composite (verify), `notifications(user_id,read_at)`
composite (verify — 30s poll hot path), `attendance_days[employee,work_date]` uniqueness
(rollup idempotency), `[status_id,position]` covering index (board ordering), `pg_trgm`
for `tasks.title/description` + `users.email` LIKE searches.

## Migration rules

- Always pass explicit `--path`: system `--path=database/migrations/system`,
  tenant via `TenantDatabaseManager::migrateTenant()` (pending-only) or
  `tenants:provision` (reaches all live tenants — backfill inline, repair-safe:
  `Schema::hasColumn`, `DROP INDEX IF EXISTS`, `whereNull` guards).
- Plain `php artisan migrate` runs NOTHING (Laravel 12 non-recursive glob).
- PG-only SQL slips past the sqlite suite: exercise the PG path
  (`TENANT_DB_DRIVER=pgsql … tenants:provision`) when touching tenant migrations.
- `HrmsDefaultsProvisioner::provision()` guards EACH catalog step individually —
  never a single early-return (pre-catalog tenants must still receive later catalogs).
