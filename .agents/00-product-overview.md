# 00 — Product Overview

## What it is

**FlowSync** is a multi-tenant SaaS combining two products in one app:

1. **TMS (Task Management System)** — workspaces → projects → tasks (kanban + list),
   collaboration (comments, dependencies, attachments, activity), time tracking,
   search / dashboard / reports.
2. **HRMS (Human Resource Management)** — employee records, org chart, onboarding /
   offboarding, attendance, leave, comp-off, holidays, payroll + statutory, expenses,
   performance, documents, assets, engagement surveys, inbox, analytics.

Plus platform surfaces: public marketing site + CMS, tenant admin, super-admin
platform (tenants, plans, features, users, analytics, audit logs, settings),
subscriptions with plan-driven module gates, tenant self-service onboarding.

## Who uses it

| Actor | Scope | Notes |
|---|---|---|
| Super admin | Whole platform, no tenant | `superadmin@flowsync.test` / `password`. Blocked from domain routes unless impersonating (`tenant_context` middleware). Manages tenants, plans, platform. |
| Tenant admin | One tenant | e.g. acme `admin@flowsync.test`. Holds `admin` role (`*` permissions). |
| Editor / viewer | One tenant | Subsets of permissions (`config/permissions.php`). |
| Employee (HRMS) | Own record + self-service | A login with an `employees` row; `employees.user_id` nullable-unique (service accounts have no row). |
| Public visitor | Marketing site `/`, `/page/{slug}` | No auth. Registration only when `ONBOARDING_ENABLED=true` (default off). |

Demo logins (password `password`, seeded by `database/seeders/TenantSeeder.php:26,50`):
`superadmin@flowsync.test`, acme `admin@flowsync.test` / `editor@flowsync.test` /
`viewer@flowsync.test`, globex `owner@globex.test`.

## Entry points

- `/` — public server-rendered marketing site (`PublicSiteController`, Blade).
- `/app` — React SPA (`BrowserRouter basename="/app"`, entry `resources/js/main.jsx`).
- `/api/*` — all backend routes live in `routes/web.php` (no `routes/api.php`).
- `/broadcasting/auth` — registered in `routes/web.php` with `switch_tenant`, NOT via
  `withRouting(channels:)` (see `bootstrap/app.php:33-37`).

## Environments

- **Dev host (current):** Apache `mod_php` vhost serving `public/` on `http://localhost`
  (`/app` = SPA); PHP 8.3 CLI + PostgreSQL 16; systemd user units
  `flowsync-reverb` / `flowsync-queue`. First bootstrap: `php artisan migrate
  --database=system --path=database/migrations/system` then seed (`TenantSeeder`
  provisions acme + globex).
- **Docker (legacy):** `docker-compose.yml` + `.env.docker`; first boot auto-migrates,
  provisions, seeds.
- **Tests:** sqlite fast-path — file-backed `iso_system` + per-tenant sqlite files via
  `Tests\IsolatesDatabase` (`database/tenants/` dir). Nothing in `php artisan test`
  exercises PostgreSQL shapes.

## Scale facts (dev host, via `tenants:seed-scale`)

100 tenants / 1,000 users / 500 workspaces / 2,500 projects / 250,000 tasks +
~37.5k comments / 50k work logs / 20k notifications, each tenant in its own Postgres DB.

## Current state vs roadmap

- Shipped: TMS complete, HRMS ~all contexts, subscriptions (code, no billing),
  onboarding wizard, CMS, platform admin.
- Missing: email delivery for notifications (`MAIL_MAILER=log`, zero `Mail::` in
  `app/` — verified 2026-10-05), payment gateways (zero Stripe/Razorpay code),
  general/TMS export (only 2 HRMS CSVs), factories (only stock `UserFactory`).
- Open roadmap (user-defined, 8 phases): security hardening → DB/perf → HRMS UX
  (single sidebar tab + sub-tabs) → TMS @mentions/email → subscriptions + full
  tenant export (Export Data plan) → Stripe+Razorpay → factories + app rename →
  final QA. Strictly one phase at a time, each tested/verified/reported.
