# FlowSync — Verified End-to-End Application Flow

How an organization approaches the SaaS, selects a plan, becomes a tenant, configures
HRMS + TMS, adds people, assigns roles, and runs day-to-day work.

**Everything below is traced against the codebase at commit `d5bc70c`** (routes, middleware,
services, SPA pages). Where the flow breaks or dead-ends, it links to the gap register
(`gap-register.md` IDs). Companion: `verification-report.md`.

---

## Flow at a glance

```
[Platform boot: super admin + plan catalog + marketing site]
        │
        ▼
① Prospect ──(marketing site /)──► ② Choose plan ──► ③ Register  or  SA creates tenant
        │                                                            │
        ▼                                                            ▼
④ Provisioning pipeline (DB → migrate → seed → owner account → routing → trial + subscription)
        │
        ▼
⑤ Onboarding wizard (self-service only) ──► ⑥ First admin login & tenant configuration
        │
        ▼
⑦ Add employees (+ roles + reporting lines)        ⑧ Configure TMS (workspaces/projects)
        │                                                  │
        └──────────────► ⑨ Configure HRMS ◄────────────────┘
                              │
                              ▼
              ⑩ Daily usage loops (employee / manager / contributor / admin)
                              │
                              ▼
              ⑪ Ongoing SaaS lifecycle (usage → quota → billing → renewal/suspension)
                              │
                              ▼
              ⑫ Super-admin oversight (impersonation, audit, health, backups)
```

---

## Stage 0 — Platform boot (one-time, operator)

1. System DB migrated + seeded: `php artisan migrate --database=system --path=database/migrations/system`
   → `db:seed …DatabaseSeeder` provisions **super admin** (`superadmin@flowsync.test`), demo tenants
   (acme/globex), and the **plan catalog** (`SubscriptionPlanSeeder` — starter/pro/business/enterprise
   with module lists + limits in `config/subscriptions.php`).
2. Platform settings seeded (`platform_settings`): `app_name`, **`public_registration`**,
   `default_plan_id`, `maintenance_mode`.
3. Services up: Reverb (realtime), queue worker, scheduler (`schedule:run` — see gap H-1:
   only `tenants:collect-usage` + `tenants:backup` are currently registered).
4. Public marketing site live at `/` (DB-backed CMS pages via `PublicSiteController`;
   editable at `/admin/pages`).

**Gates here:** none — this is operator territory (`docs/runbook.md`).

---

## Stage ①–③ — Acquisition: prospect → plan → tenant

Two entry paths:

### Path A — Self-service registration (product-led)

| Step | What happens | Where |
|---|---|---|
| 1 | Prospect reads the marketing site (`/`), clicks **Register** | `resources/views/public/*`, `/app/register` → `pages/auth/Register.jsx` |
| 2 | Two switches must be ON: platform **`public_registration`** setting *and* `ONBOARDING_ENABLED` (default **off**) — otherwise register 403s | `RegisterController::store`, `config/onboarding.php` |
| 3 | Registrant picks a **plan** (or defaults to `default_plan_id`) + sees trial days | plan catalog from `subscription_plans` |
| 4 | Submit: email/password/company → creates the **central tenant row** (`status=pending`, `trial_ends_at` set, `onboarding_meta.started` flagged) → `Bus::dispatchSync(ProvisionTenantJob)` runs **synchronously** so they can log in immediately | `RegisterController` |
| 5 | The provisioned `owner@{slug}.test` account is **claimed** for the registrant (name/email/password swapped; stale routing row deleted), then **auto-login** via `establishTenantSession()` (payload built inside `TenantDatabaseManager::using($tenant)`) | `RegisterController` + `AuthController` |
| 6 | Redirect → **onboarding wizard** `/onboarding` | `AuthContext`, `AdminLayout` redirect |

### Path B — Super-admin created (sales-led)

| Step | What happens | Where |
|---|---|---|
| 1 | SA opens `/tenants` → **New tenant** modal: name/slug, **plan picker**, **trial_days**, billing contact | `pages/Tenants.jsx` |
| 2 | `POST /api/tenants` → `status=pending`, validates `plan_id`/`trial_days`, sets `trial_ends_at` **before** dispatch, queues `ProvisionTenantJob` (HTTP 202) | `TenantController::store` |
| 3 | Tenant appears in the table with provisioning status; SA can view-as, suspend, edit | `Tenants.jsx`, `TenantDetail.jsx` |

> **Gap:** neither path ever expires — no scheduled job transitions a trial to `expired` (G-2).

---

## Stage ④ — Provisioning pipeline

`ProvisionTenantJob` (`tries=1`, repair via `php artisan tenants:provision`) records a
`provisioning_runs` row and runs `TenantProvisioner::provisionIsolated()`:

```
connect tenant connection
  → createPostgresDatabase (role FIRST, then CREATE DATABASE … OWNER)   [PG prod]
     or sqlite file under tenancy.tenant.db_path                        [dev/test fast-path]
  → run ALL tenant migrations (full current set)
  → seed: permissions/roles/priorities/project-roles (idempotent, union-add)
  → HrmsDefaultsProvisioner::provision()  — one guarded step per catalog:
       hrms_settings, employment types, org starters (departments/designations/locations),
       leave types/policies, comp-off, holidays (US/IN presets), expense categories,
       document types, salary components/structures, payslip templates, issue types…
  → create owner admin user (local) + is_default guard
  → syncRouting() → central tenant_users row  (WITHOUT this the account cannot log in)
  → SubscriptionService::assign/startTrial → subscription row + trial_started/subscribed events
  → lifecycle → TRIAL (or ACTIVE when SA-created without trial)
```

**On failure:** tenant → `provisioning_failed` + `provisioning_error`; repair with
`tenants:provision --tenant=ID`.

**Entitlements become live here:** the subscription row + plan `modules`/`limits` drive every
later `ensure_module:<key>` 403 and `TenantLimits::assertQuota` 422.

---

## Stage ⑤ — Onboarding wizard (Path A only)

Middleware `onboarding_complete` gates the **whole domain route group**
(`switch_tenant → auth → tenant → tenant_context → onboarding_complete`) until done —
API 403 + `X-Onboarding-Redirect`, SPA redirects to `/onboarding`.

| Order | Step | What the org does | Persisted to |
|---|---|---|---|
| 1 | `business` | Company profile mini-form → `PUT /api/tenant/profile` | `tenants.onboarding_meta` |
| 2 | `admin` | Confirm admin details | 〃 |
| 3 | `subscription` | Review/select plan (reads `GET /api/plans` — role-aware) | subscription re-stamped |
| 4 | `configuration` *(optional)* | Org basics | 〃 |
| 5 | `verification` *(optional)* | Verification items | 〃 |
| 6 | `completion` (required) | **Finish** → `POST /api/onboarding/complete` → `refresh()` → `/dashboard` | `completed_at` |

**Bypass rules:** a non-impersonating super admin skips the gate; a tenant that **never started**
the wizard is treated as complete (SA-provisioned/seeded tenants skip automatically).
`payload.me` carries `onboarding_complete` for the SPA to decide.

---

## Stage ⑥ — First admin login & tenant configuration

Login: email (+ optional `tenant` slug) → `TenantUserRouting` resolves the tenant →
`SwitchTenant` connects the tenant DB per request → session keys carry the central tenant id.

The landing page is `homeRouteFor(user)` → `/dashboard` (tenant) / `/admin` (SA).

**Then the org configures, in rough order:**

| # | Configuration | Where | Gate |
|---|---|---|---|
| 1 | **Roles & permissions** — admin/editor/viewer/manager/hr_manager… from `config/permissions.php` selectors; create custom roles, tick scoped slugs (`hrms.leave.view_own/_assigned/_all`, `tasks.edit_own…`) | `/roles` (`pages/Roles.jsx`) | `roles.manage` (tenant admin = `*`) |
| 2 | **Users** — invite/create logins, assign roles, set default user | `/users` (`pages/Users.jsx`) | `users.view` / `users.manage` |
| 3 | **Branding/theme** | theme drawer | `ensure_module:branding` + `settings.theme` |
| 4 | **Org structure** — departments (tree), designations, locations | `/hrms/org` (`Org.jsx`) | `hrms.core` + `hrms.org.view/manage` |
| 5 | **Subscription self-service** — view plan, usage meters, upgrade (`POST my-subscription/switch`), cancel/renew | `/subscription` (`Subscription.jsx`) | tenant `admin` role |
| 6 | *(Automatic)* HRMS defaults, issue types, priorities, default task statuses, project roles — seeded during provisioning, editable later | catalogs | — |

**Entitlement check pattern used everywhere:**
`ensure_module:<module>` (middleware, fail-closed, `X-Module-Reason` header) +
`permission:<slug>` (route) + **policy per record** (row scope). Frontend mirrors with
`can()`/`hasModule()`/`check()` — hiding only, never the authority.

---

## Stage ⑦ — Adding employees

`/hrms/employees` (`Employees.jsx`, gate `hrms.employees.view`; writes `hrms.employees.manage`):

1. **Create modal** with three login modes: *no account* / *new login* / *link existing user*
   — resolving roles **before** inserting the user (validated-after would strand an account).
2. Employee gets: `employee_code` (collision-retried), employment type, department/designation/
   location, **manager** (reporting line, cycle-checked by `ReportingLine`), status = `active`
   (+ `probation_end_date`/`confirmation_date` optional), optional inline photo.
3. Routing row written → the person can now log in (or the linked account gains the employee).
4. Personal fields are **redacted** per reader (`SensitiveFieldRedactor`) + sensitive views are
   logged (`hrms_data_access_logs`); photo downloads are signed & attributed.
5. Bulk path for existing logins: `hrms:backfill-employees` creates employee rows for any login
   lacking one (**no** bulk CSV import yet — G-24).
6. Offboarding later: status → `exited`/`terminated` auto-initiates an **offboarding case**
   (clearance: assets, leave, expenses) — but see G-27: authentication is *not* yet blocked.

---

## Stage ⑧ — Configuring TMS

```
Workspace  (POST /workspaces — creator auto-owner; members owner/admin/member)
  └── Project  (key auto-derived "WR"; 5 default statuses seeded from config/task_statuses.php;
                creator = lead; members get project roles lead/developer/viewer — 35 slugs, scope-aware)
        ├── Workflow: statuses CRUD (category todo/in_progress/done drives board columns)
        ├── Catalogs: priorities (tenant), labels (workspace), issue types, components, versions
        ├── Project roles assigned per member (tasks.view_own/_assigned/_all …)
        └── Tasks: atomic key KEY-N, status/priority defaults, position on board,
                   labels/components/version/issue-type/start-date/story-points (backend),
                   parent/child (2 levels), dependencies (blocks/related), watchers,
                   comments+mentions, attachments (quota-checked), work logs
```

**UI surfaces:** global `/workspaces`, `/projects` (+ sidebar items), project hub
`/projects/:id?tab=…` (overview/tasks/members/workflow/settings), Kanban board + list,
`⌘K` command palette, deep links `/projects/:id?tab=tasks&task=KEY`.

**Not yet available to the org:** sprints/backlog, calendar/timeline/Gantt views, workflow
transition rules, automation rules, saved filters, bulk edits, task checklists (G-7b, G-7, G-42,
G-4/G-5, G-8, G-23).

---

## Stage ⑨ — Configuring HRMS

Behind `ensure_module:hrms.core` + `permission:hrms.view`; every HRMS section is a sub-tab of
the single `/hrms` hub (`HRMS_NAV`, capability-filtered):

| Area | Setup actions | Module key |
|---|---|---|
| Attendance | windows, late/early thresholds, OT, IP rules, geofence locations, **auto-derive from work logs** toggle | `hrms.attendance` (+ `.remote`) |
| Leave | types, policies, **accrue run**, exemptions, team calendar | `hrms.leave` |
| Comp-off | eligibility, validity/expiry, accrue | `hrms.comp_off` |
| Holidays | calendars, per-location assignment, optional holidays, year seed | `hrms.holidays` |
| Expenses | categories, receipt rules, finance approval step | `hrms.expenses` |
| Compensation | salary components → structures → per-employee assignment; revision threshold | `hrms.compensation` |
| Payroll | run lifecycle calculate→approve→publish→mark-paid→lock; payslip templates; statutory profiles (PF/ESI/PT/TDS/LWF per jurisdiction) | `hrms.payroll` (+ `.statutory`) |
| Performance | cycles (stage machine), goals (+ task links), check-ins, 1:1s, feedback | `hrms.performance` |
| Onboarding/Offboarding | templates, checklist items, document-request types | `hrms.onboarding/offboarding` |
| Engagement | survey templates, campaigns, audiences, anonymity threshold | `hrms.engagement` |
| Documents | document types, confidentiality, expiry | `hrms.documents` |
| Assets | categories, inventory, assignment rules | `hrms.assets` |

Shifts (`hrms.shifts`) and Talent (`hrms.talent`) are **reserved** — keys exist, nothing to
configure (C-section of gap register).

---

## Stage ⑩ — Daily usage loops

**Employee (self-service).** Punch in/out (web; IP/geofence rules recorded) → sees own month
grid; request leave / comp-off / regularization / expense (receipt upload); read payslips
(signed, masked, access-logged); manage documents/assets acknowledgements; goal check-ins;
survey responses; browse **Inbox** (merged pending work: approvals, case tasks, expiring docs).
Also TMS: work on assigned tasks, log time.

**Manager.** **Inbox** + notifications surface pending approvals; decisions route through the
shared **approval engine** (manager → dept head → HR for leave; manager → HR → finance for
expenses; auto-approve when no manager resolvable for regularization). Own team leave calendar.
Performance check-ins/feedback. Offboarding clearance sign-off.

**Contributor (TMS).** Board/list → open task drawer (details/comments/attachments/dependencies/
time/activity) → move cards (blocked from Done while open blockers exist) → comment with @mentions
→ log work → realtime updates via Reverb (`task.synced`, `comment.synced`).

**Admin/HR.** Employee directory (redacted), org chart, attendance approvals + CSV export,
payroll runs, performance cycles, surveys open/close, analytics (8 tabs + CSV + scheduled
digests), lifecycle cases, audit log.

**Cross-cutting during the day:**
- **Notifications:** in-app bell (Echo + 30s poll) + email for 4 TMS events, per-event prefs,
  deep links into task/section. *Not yet:* watcher fan-out, digest, push, webhooks
  (G-2a/G-44/G-43/G-45).
- **Reports:** `/reports` (distributions + time scope), `/dashboard` (personal widgets),
  `/search`, `/hrms/analytics`. *Not yet:* velocity/burndown/cycle time (G-46).
- **Audit:** task activities + HRMS audit with diffs; platform feed at `/admin/audit-logs`.
  *Not yet:* login/logout (G-6), before/after centrally (G-40).

---

## Stage ⑪ — Ongoing SaaS lifecycle

| Event | What happens today | Gap |
|---|---|---|
| Usage accrues | `tenants:collect-usage` (daily) → `usage_metrics`; `GET api/my-usage` shows counts vs plan limits | — |
| Quota hit | `TenantLimits::assertQuota` → 422 on user/workspace/project/task/employee/attachment creates | No grace/overage (H-17) |
| Upgrade/downgrade | Tenant admin `POST my-subscription/switch` → plan re-stamped + `plan_changed` event; modules/limits take effect immediately (`TenantLimits` memo flushed per request) | No proration (G-12) |
| Checkout | `BillingController` → Stripe/Razorpay checkout (idempotent) → verify → webhook (`api/webhooks/*`, signature+replay-guarded) → `active` | No invoice record (G-11) |
| Failed payment | Webhook failure → `SubscriptionService::suspend` → `past_due` + `paused` events | No dunning/grace ladder (G-11) |
| Trial ends | **Nothing happens** — trial runs forever | **G-2** |
| Cancel / renew / reactivate | Self-service endpoints + SA endpoints; lifecycle-synced (`TenantLifecycle`) | — |
| Backup | `tenants:backup --all --verify` daily 02:00 + restore **drill** | Real restore is manual `psql` (H-14) |
| Export | `GET /export` → queued ZIP (8 categories) → signed download; gated `export.full` | ZIPs never purged (H-12) |
| Suspend/delete tenant | SA: suspend/activate/soft-delete/restore + audit rows | No hard purge (G-55) |

**Entitlement hierarchy enforced throughout:** `Subscription → Plan → module → route gate →
permission → record scope`. Module availability and row access are separate checks and never
substitute for each other.

---

## Stage ⑫ — Super-admin oversight

`/admin` (overview) · `/tenants` (+ detail: provisioning, subscription pill, stats) · `/plans` ·
`/features` (module × plan grid) · `/admin/users` · `/admin/analytics` · `/admin/audit-logs` ·
`/admin/settings` · `/admin/pages` (CMS). Support = **impersonation** (audited, guarded —
cannot impersonate SAs, session-regenerated, stop resolves original via system DB).

**Not yet:** platform health UI (endpoint exists, unused), backups UI, failed-jobs/queue
monitoring, migrations UI (G-54).

---

## Persona → first-week journey (condensed)

| Persona | Day 1 | First week |
|---|---|---|
| **Tenant owner/admin** | Register or accept invite → onboarding wizard → roles + users → org structure | Attendance/leave settings, expense categories, first workspace + project, subscription review |
| **HR manager** | Land on `/hrms` → hire employees (with logins) → assign managers | Leave types + accrual, holidays, onboarding templates, first payroll structures, performance cycle |
| **Project lead** | Get `lead` project role → create statuses/components/versions → create tasks, invite members | Board usage, labels, dependencies, time summary, reports |
| **Employee** | Get account → punch in, browse own leave/payslip/profile | Request leave/expenses, check Inbox, work assigned tasks, log time |
| **Manager** | Inbox → approve leave/expenses/regularizations | Team calendar, check-ins, feedback, offboarding clearances |
| **Super admin** | Create tenant with plan + trial → impersonate owner → verify provisioning | Audit feed, feature toggles, backups, usage/billing watch |

---

## Flow integrity checklist (what actually holds vs. what silently fails)

| Flow promise | Status |
|---|---|
| Register → provision → auto-login → wizard | ✅ verified |
| Trial starts with subscription + events | ✅ |
| Module gates block unentitled features (fail-closed) | ✅ (`ModuleGateTest` re-run) |
| Quotas block over-limit creates | ✅ (`SubscriptionStateTest` re-run) |
| Usage collected daily | ✅ (`TenantUsageCollectionTest` re-run) |
| Routing row always written with tenant users | ✅ (`TenantUserCreationTest`-documented path) |
| New tenant receives all HRMS catalogs | ✅ (`HrmsDefaultsProvisioner`, per-step guards) |
| Approvals flow manager→HR with audit | ✅ |
| **Scheduled HRMS housekeeping actually runs** | ✅ 10 `hrms:*` commands + trial expiry scheduled; systemd timer runs `schedule:run` (G-1) |
| **Trial ends → expiry** | ✅ `tenants:expire-trials` scheduled daily (G-2 trial half; period-end auto-expiry still absent) |
| **Offboarded user cannot log in** | ❌ not enforced (G-27) |
| **Watcher gets notified** | ❌ table only (G-2a) |
| **TMS expansion fields reachable in UI** | ❌ backend-only (G-2b) |
| **Plan modules all entitle real routes** | ✅ `api`/`audit_export` delisted (G-9/H-2) |
| **Invoice after payment** | ❌ none (G-11) |
| **Login appears in audit** | ❌ none (G-6) |

*Fix priorities for the ❌ rows: see gap-register §F (Top 10).*
