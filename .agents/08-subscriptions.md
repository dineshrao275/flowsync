# 08 — Subscriptions, Limits, Onboarding, Tenant Lifecycle

## Plans (`config/subscriptions.php`, seeded by `SubscriptionPlanSeeder`)

Four plans verified 2026-10-05: **starter / pro / business / enterprise**.
Modules: `time_tracking, reports, global_search, branding, export.full` +
`hrms.*` keys (see `07-hrms.md`); `api`/`audit_export` delisted 2026-10-08
(gated nothing — see roadmap gap G-9). Numeric limits: users/seats, workspaces,
projects, tasks, `employees`, `assets`, `storage_bytes`, `attachments_per_task`,
`hr_document_bytes`. Starter = no HRMS; higher tiers unlock progressively through
Enterprise (+payroll/statutory/exemptions/platform modules). **Export Data shipped**
as the `export.full` add-on module (queued full-tenant ZIP export behind
`ensure_module`, so existing `ensure_module`/`hasModule` work unchanged).

## Models (all central)

`SubscriptionPlan` (`limit($key)`/`hasModule($module)`/`periodEnd()`),
`Subscription` (`isActive()`/`onTrial()`, `EVENT_*` constants, unique `tenant_id` —
one re-stamped row per tenant), `SubscriptionEvent` (subscribed|plan_changed|
renewed|trial_started|trial_expired|canceled|reactivated|paused|payment_failed|
seats_changed). `Tenant`: `subscription()/subscriptions()/subscriptionEvents()`.

## `SubscriptionService` + `TenantLimits`

- Service: `assign` (first subscribe or re-stamp + `plan_changed`), `startTrial`
  (sets `trial_ends_at`, lifecycle → TRIAL), `switch`, `cancel`, `renew`
  (reactivated vs renewed), `suspend` (past_due + `paused`), `record()` event writer.
- `TenantLimits::effective()` = plan.limits ⊕ `tenants.limits_override` (override
  wins); per-tenant SA switching via `tenants.features_override.modules`
  (**additive — grants, never revokes**). **No subscription ⇒ unlimited** (keeps
  seeded/legacy tenants running). No-op when `TenantContext::currentId()` is null
  (provisioning/seeders/tests). `assertQuota()` counts on the tenant connection →
  422 `form`; consulted by `UserController::store` + every create in
  Workspace/Project/Task services (+ `employees` via `Employee::count()`).
- `hasModule()` helpers ready; `me()` payload carries `user.modules` (full catalog
  fallback for no-subscription / non-tenant SA).

## Enforcement points (keep backend + UI in lockstep)

Backend `ensure_module:` — theme→`branding`, `search/*`→`global_search`,
`reports/overview`→`reports`, work-logs + time-summaries→`time_tracking`, every
HRMS route→its `hrms.*` key. Frontend `hasModule()` + `ProtectedRoute module=`
+ sidebar/CommandPalette/Time-tab gating (`/search`→`global_search`,
`/reports`→`reports`, `/hrms`→`hrms.core`). Impersonating SA bound to target
tenant's plan.

Two validation gotchas (paid for): tenant-side actions omit
`SubscriptionEvent.actor_id` (tenant admins aren't central `users` rows — FK
violation; actor travels in `data.actor`); `exists:subscription_plans,id` fails on
tenant connections → `MySubscriptionController` uses `find()` + 422 (SA side keeps
the rule — system connection).

## Tenant self-service (`MySubscriptionController`)

Plain `auth → tenant` group (outside onboarding gate so the wizard reads plans):
`GET my-subscription` (subscription + plan + events + tenant), `GET my-usage`
(counts vs effective limits + modules), `GET plans` (role-aware: SA full catalog,
tenant users active only), `POST my-subscription/switch|cancel|renew`
(admin-gated 403; non-impersonating SA 404s). SPA `pages/Subscription.jsx` at
`/subscription` + Billing sidebar section.

## Onboarding (self-service, default OFF)

`config/onboarding.php` `enabled` (env `ONBOARDING_ENABLED`). Steps:
business → admin → subscription → configuration (opt) → verification (opt) →
completion (required), persisted to `tenants.onboarding_meta`. `TenantOnboarding`:
`status/start/markStep` (**rejects terminal `completion`** — only via `complete()`),
`reset()` (SA repair). Gating: completed_at set → complete; **never started →
complete** (admin/seed/SA tenants bypass; self-registration is the only entry).
Middleware `onboarding_complete` 403s the domain group otherwise (non-impersonating
SA bypasses). Public `POST api/register` (throttled): validates plan/trial/billing,
flags `onboarding_meta` started BEFORE provisioning, `dispatchSync(ProvisionTenantJob)`
so login is immediate, claims `owner@{slug}.test` (name/email/password swap, stale
routing row deleted), slugs suffixed `-2/-3…` on collision. Wizard endpoints in
plain `auth → tenant` group; SPA `Register.jsx` → `/onboarding` wizard →
`refresh()` + `/dashboard`; `AdminLayout` redirects incomplete tenants to wizard.

## Tenant lifecycle (`TenantLifecycle`)

States pending/provisioning/trial/active/suspended/expired/deactivated/
`provisioning_failed`; `canTransition()/assertTransition()/transition()` writes
`audit_logs` (`tenant.status_changed`). `TenantController`: filtered/sorted index
(`users_count` via routing withCount/pluck, subscription inlined as
plan_slug/plan_name/subscription_status), store → pending + job (202), destroy
(soft) + `restore` (`->withTrashed()` so trashed binds; suspend/activate on trashed
404s), suspend/activate, `stats` (counts via `using()` + 60s cache).
`ProvisionTenantJob(tries=1, ?planId, ?trialDays)`: records `provisioning_runs`,
post-provision creates/re-stamps subscription + `trial_started|subscribed` (default
plan fallback; no-op with no plans). Repair path is re-running `tenants:provision`.
