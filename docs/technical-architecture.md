# FlowSync Technical Architecture

FlowSync is a multi-tenant SaaS admin panel with two sellable products: **TMS** (task/project management) and **HRMS** (human resources). It is a Laravel 12 API plus a React 19 SPA, with **one database per tenant** and a central `system` database for platform data.

## 1. System Architecture

```
Browser (React SPA, Echo)        Public marketing site (Blade)
        |  HTTPS, session cookie        |
        v                               v
  Apache/php artisan serve  ->  Laravel 12 (routes/web.php, api/*)
        |                         middleware -> FormRequest -> controller -> service -> presenter
        |                                   |
        |            +----------------------+----------------------+
        |            v                      v                      v
        |    System DB (PostgreSQL)   Tenant DB per tenant   Queue / Scheduler
        |    tenants, routing,        (PostgreSQL; sqlite    (ProvisionTenantJob,
        |    plans, subscriptions,    files in tests)        webhooks, exports,
        |    audit, support           domain schema, no      mail, sweeps)
        |                             tenant_id columns
        +--<- WebSocket --- Laravel Reverb (private channels)
```

**Tenant isolation is physical.** Tenant databases hold the full domain schema without `tenant_id` columns; the system DB holds platform data and the super admin. A request is bound to one tenant DB for its lifetime, so a missing `WHERE tenant_id` cannot leak data.

**Layering** (binding, see `docs/coding-standards.md`):

| Layer | Responsibility |
|---|---|
| Middleware | Coarse gates: tenant switch, auth, permission, module, product |
| FormRequest | Validation, one per write endpoint |
| Controller | Validate, authorize, one service call, present |
| Service (`app/Services/<Context>/`) | Business rules, transactions |
| Presenter | JSON shaping |
| Policy / `TaskScope` / `HrmsScope` | Per-record and row-level scoping |

## 2. Components

**Backend**
- `TenantDatabaseManager` (singleton): manages the `system` and `tenant` connections; `using($tenant, fn)` scopes work to one tenant DB. Central models use the `CentralConnection` trait.
- `TenantContext`: guard-intent singleton (tenant id, impersonating flag).
- Middleware chain for tenant routes: `switch_tenant -> auth -> tenant -> tenant_context -> permission`. Order is enforced through `$middleware->priority()` so it runs before route-model binding.
- `TenantProvisioner` / `ProvisionTenantJob`: create the tenant DB, run the right migration sets, seed catalogs and the default user.
- `TenantLimits`: merges plan limits and modules across subscriptions; answers `hasModule()` and `productEnabled()`.
- TMS services: `TaskService` (thin orchestrator over `Tasks/*`), `WorkflowGuard`, `SprintService`, `AutomationEngine`, `NotificationService`, `ActivityLogger`.
- HRMS services: `ApprovalService` (generic approval engine), `HrmsAuditLogger`, `HrmsScope`, per-context presenters and enums.
- Platform: `SupportDesk`, `TenantIntake`/`TenantActivation`, billing gateways, `PlatformAudit`.

**Frontend (`resources/js`)**
- Entry `main.jsx`; routing in `App.jsx` (`GuestRoute`, `ProtectedRoute` with `permission`/`product` props, lazy routes behind a shared error boundary).
- Contexts: `AuthContext` (`can()`, `hasModule()`, `check()`), `ProductContext` (active product shell), `ThemeContext`, `ToastContext`, `NotificationContext`, `BreadcrumbContext`.
- Data hooks: `useResource`, `usePaginated`, `useUrlState`, `useBoardPreferences`; UI primitives in `components/ui` (`DataTable`, `Modal`, `Menu`, `FormField`, ...).
- i18n via i18next with lazy non-English dictionaries (`en` bundled, `es` lazy).

## 3. Data Flow

**Authenticated request**
1. SPA calls `/api/...` via axios (`withCredentials`, XSRF token).
2. `AssignRequestId` tags the request; `PlatformMaintenance` may short-circuit with 503.
3. `SwitchTenant` reads the central tenant id from the session and connects the tenant DB.
4. `auth`, `tenant`, `tenant_context`, then `ensure_product` / `ensure_module` / `permission` gates run.
5. FormRequest validates; controller authorizes via policy; service executes inside a transaction; presenter returns JSON.
6. `ActivityLogger::log()` records an activity, which is also written as a `domain_events` outbox row and queued to `ProcessDomainEvent`.

**Domain events fan-out.** `ProcessDomainEvent` hands each event to the consumers configured in `config/domain_events.php`: `WebhookConsumer` (signed outbound webhooks with retry rows), `AutomationConsumer` (rules engine, depth-capped at 3), and notifications. A failing consumer is logged, never thrown.

**Realtime.** Events broadcast through the queue to Reverb private channels (`user.{id}`, tenant channels); Echo in the SPA updates notifications and boards. A 30s poll is the fallback when Reverb is off.

**Login routing.** Login looks up the central `tenant_users` routing index (optional tenant slug disambiguator) to pick the tenant DB. Every code path that creates a tenant user must also write the routing row. Super admins authenticate against the system DB.

**Provisioning.** Sign-up creates a `draft` tenant (no DB). `TenantActivation::activate()` validates the intake, then `ProvisionTenantJob` creates the DB, migrates the product sets, seeds roles/permissions/catalogs, claims the default user, and starts the trial subscription.

## 4. Technology Stack

| Area | Choice |
|---|---|
| Backend | PHP 8.3, Laravel 12, session auth (no Breeze/Fortify/Sanctum) plus personal API tokens |
| Database | PostgreSQL 16 (central + per tenant); sqlite files as the test fast-path |
| Realtime | Laravel Reverb, Laravel Echo |
| Queue / schedule | Laravel queue workers; scheduler timer running `schedule:run` each minute |
| Frontend | React 19, Vite 7, Tailwind v4, axios, dnd-kit (board), Recharts, i18next |
| Deploy | Docker compose dev setup or native Apache `mod_php` host with systemd user units |
| Payments | Stripe, Razorpay |

## 5. APIs

- **Style:** JSON over HTTP under `/api`, session cookie auth with CSRF; Bearer personal API tokens for programmatic access (`api/v1` search among others). Routes live in `routes/web.php`.
- **Groups:**
  - Public: `register`, `health`, `locales`, `contact`, `csp-report`, signature verify. Throttled and tenant-free.
  - Tenant TMS: workspaces, projects, tasks, comments, work logs, sprints, workflow, automation, hierarchy, saved filters, search, reports. Gated by `ensure_product:tms`.
  - Tenant HRMS: `hrms/*` (employees, attendance, leave, payroll, performance, talent, documents, assets, analytics), gated by `ensure_product:hrms` plus `ensure_module:hrms.*`.
  - Tenant admin: users (incl. CSV import), roles, settings, theme, webhooks, API tokens, support tickets, subscription.
  - Platform (`super_admin`): tenants, plans, intake, impersonation, support desk, audit, health, settings. Each route also needs an entry in `config/platform_access.php`.
- **Authorization model:** permissions use `domain.action[_own|_assigned|_all]` scopes; project roles add row scoping; `GrantCeiling` bounds role grants; the product catalog filters which permissions a tenant can see.

## 6. Integrations

- **Stripe:** hosted checkout, card capture for trials (`CardCaptureGateway`), recurring subscriptions, signed webhooks with a replay guard, scheduled changes and reconciliation.
- **Razorpay:** checkout and signature-verified webhooks.
- **Outbound webhooks:** HMAC-SHA256 signed (`X-FlowSync-Signature: t=,v1=`), retry backoff of 1/5/30/120 minutes, auto-disable after 20 failures, SSRF guard (`WebhookUrlGuard`: https only, no private addresses).
- **Mail:** localized task and audit emails, queued, with recipient-locale snapshotting.

## 7. Key Technical Decisions

| Decision | Rationale | Trade-off |
|---|---|---|
| **Database per tenant** | Strong isolation, per-tenant backup/restore, no `tenant_id` filter bugs | Migration fan-out; cross-tenant queries must be explicit, capped and audited |
| **Four migration sets** (core / TMS / HRMS / bridge) | Products sold separately; a tenant only gets the schema it pays for | `TenantProductSync` must migrate on plan change; cross-set readers probe schema first (`TmsSchema`, `HrmsSchema`, `BridgeSchema`) |
| **Product-aware subscriptions** (one per product, or a bundle) | Flexible packaging; `TenantLimits` merges to the most generous limit | More states to reason about; `assertProductFits` guards invalid combos |
| **Session auth, no starter kit** | Same-origin SPA, simple CSRF model, full control of tenant routing | Custom code for 2FA, reset, and impersonation, covered by dedicated tests |
| **Outbox-style domain events** | One funnel feeds webhooks, automation and notifications, with idempotent runs | Async consistency; consumers must tolerate retries |
| **Scoped permissions** (`own/assigned/all`) via `TaskScope` / `HrmsScope` | Row-level rules defined once; policy and list scoping cannot disagree | Slug catalog grows; migration command `tenants:scope-grants` backfills legacy grants |
| **Code must run on PostgreSQL and sqlite** | Fast tests on file DBs, production on PG | No dialect-only SQL without a guard; raw SQL is pinned with `toSql()` tests |

## 8. Security and Operations

- CSP and security headers, TOTP 2FA, audit chain verification, support-access grants, logged impersonation.
- Request-id log context, public and platform health endpoints, per-tenant usage collection, scheduled backups and trial expiry.
- ParaTest with per-tenant sqlite files; Vitest for the SPA.
