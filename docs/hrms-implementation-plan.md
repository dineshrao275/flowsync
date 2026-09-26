# FlowSync HRMS — Phase-Wise Implementation Plan

**Status:** plan only — no HRMS code exists yet.
**Baseline at plan time:** `php artisan test` = **368 tests / 2629 assertions passing**.
**Reference inspiration:** Keka's public marketing capability list was used only to enumerate functional
breadth. No Keka code, assets, copy, or data was consulted or reproduced.

Two standards are **binding on every line of HRMS code** and are specified in
[D2.16 (code structure & quality)](#d216--code-structure--quality-standard-binding) and
[D2.17 (observability & logging)](#d217--observability--logging-standard-binding):

1. **Clean, modular, professionally structured code** — strict layering, one class per concern, bounded
   contexts, FormRequests + presenters + policies, enums instead of raw strings, typed everything.
2. **Logging wherever it aids debugging** — a dedicated `hrms` channel, dotted event names with
   structured context, a correlation id on every request/job/command, and a hard ban on logging
   sensitive data.

Written to be executed **one small task at a time** by an agent (OpenCode) with no memory of the wider
design. Every task is self-contained: read the phase, do the task, verify, commit.

---

## Table of contents

- [Part 0 — How to execute this plan](#part-0--how-to-execute-this-plan)
- [Part 1 — Architecture analysis (what already exists)](#part-1--architecture-analysis-what-already-exists)
- [Part 2 — Design decisions (binding for all phases)](#part-2--design-decisions-binding-for-all-phases)
- [Part 3 — Module / permission / data-model reference](#part-3--module--permission--data-model-reference)
- [Part 4 — Phase index](#part-4--phase-index)
- [Part 5 — The phases](#part-5--the-phases)
- [Part 6 — Cross-cutting test matrix](#part-6--cross-cutting-test-matrix)
- [Part 7 — Manual verification harness](#part-7--manual-verification-harness)
- [Part 8 — Risk register](#part-8--risk-register)
- [Part 9 — Global definition of done](#part-9--global-definition-of-done)

---

## Part 0 — How to execute this plan

### 0.1 Task granularity

Each phase is decomposed into numbered tasks (e.g. `P1.4`). **One task = one commit.** Never bundle two
tasks; never split a task across commits. If a task turns out to be too large, split it and update this
document before continuing.

### 0.2 Mandatory loop, every task, no exceptions

```
1. Read  : AGENTS.md (current) + this plan's phase section + the files the task names
2. Code  : the task's deliverable only, to the standard of D2.16 (structure) + D2.17 (logging)
3. Style : ./vendor/bin/pint            # whole repo; --dirty only works in git
4. Test  : php artisan test --filter=<TheTestClass>
          php artisan test               # FULL SUITE — must stay green (>= baseline)
5. Build : npm run build                # must succeed
6. Verify: manual checks from 0.4 — isolation + module gate + permission gate
7. Gate  : run the 0.8 checklist — structure, types, enums, logs, no debug leftovers
8. Update: this document if reality diverged; AGENTS.md if architecture changed
9. Commit: git add -A && git commit -m "<P><n> <task-slug>: <imperative summary>" && git push
10. Continue with the next task
```

`git remote` is `flowsync https://github.com/dineshrao275/flowsync.git` (push is required by the loop).

### 0.3 Commit naming

`<P><n> <task-slug>: <what changed>` — e.g. `P1.3 hrms-permissions: add hrms.* permission catalog`.

### 0.4 Manual verification, every task

Three checks no unit test will catch for you:

1. **Isolation** — a Globex request must never see an Acme employee row. Log in as `owner@globex.test`
   and confirm the acme-only record 404s.
2. **Module gate** — a tenant whose plan lacks the module must get **403** from the API and the nav item
   must be absent. `tests/Feature/ModuleGateTest.php` is the pattern.
3. **Permission gate** — a `viewer`-role user must get 403 on writes and see no action buttons. A
   non-impersonating super admin must get 404 (not 403, not 500) on tenant-DB HRMS routes.

### 0.5 Test count bookkeeping

`AGENTS.md` states the current gate (`368 tests / 2629 assertions`). **Update that line at the end of
every phase.** A phase that adds tests but does not update the number is incomplete.

### 0.6 Hard rules (violating these breaks the app)

| Rule | Why |
|---|---|
| Never add a `tenant_id` column to a tenant-DB table | The tenant **is** the database (Phase 13) |
| Never `connectSystem()` while the default connection is `:memory:` sqlite | Purging wipes the test DB |
| Any middleware that must precede model binding goes in `$middleware->priority([...])` in `bootstrap/app.php` | Laravel reorders route middleware by the priority list |
| Any route with `{parent}/{child}` model params must declare **both** in the controller signature | `ImplicitRouteBinding` splices positionally; a missing param becomes a raw string -> `TypeError` |
| Pagination payload is always `{current_page,last_page,per_page,total}` | Uniform frontend renderer |
| `useToast()` is used directly, never destructured | `const {toast} = useToast()` is `undefined` |
| `Modal`/`Drawer` must `createPortal(..., document.body)` | `AdminLayout`'s animated page wrapper traps nested `fixed` |
| New tenant migrations go in `database/migrations/tenant/`, system ones in `database/migrations/system/`, with explicit `--path` | `Migrator` globs non-recursively |
| Boolean index predicates must be `true`, never `1` | PostgreSQL rejects `boolean = integer`; sqlite silently accepts it |
| Money in `decimal(14,2)`; never floats | Float drift in payroll totals |
| Log to the `hrms` channel with a dotted event name + array context | D2.17 — an ungreppable, unstructured log is not a log |
| Never log sensitive values (salary, bank, PAN/UAN, tokens, bodies) | D2.17.8 / R2 — logs outlive the data they describe |
| Controllers never build queries or hold business rules | D2.16.1 — layering keeps 22 modules reviewable |
| Every state field is an enum, never a raw string | D2.16.5 — typos in status strings are silent data bugs |

### 0.7 Pre-existing bugs that HRMS depends on

Both found while preparing this plan. **Fixed in Phase 0** (see the status line at the end of this
section); later phases assume they are fixed.

1. **`UserController::store` never creates the `tenant_users` routing row.** Login resolves the tenant via
   `TenantUserRouting` (`app/Http/Controllers/AuthController.php:86`). A user created through
   `POST /api/users` in an already-provisioned tenant has no routing row, so they **cannot log in at
   all**. `destroy` deletes a row that `store` never created.
   *HRMS impact:* Phase 2 (create employee) and Phase 4 (onboarding invite) create users.
2. **The onboarding wizard's business step 405s.** `resources/js/pages/Onboarding.jsx:76` calls
   `api.put('/tenant/profile', ...)` but only `GET api/tenant/profile` was registered
   (`TenantController::selfProfile`). The SA `updateProfile` takes a route-bound `Tenant` and is
   `super_admin`-gated, so it cannot serve this.
   *HRMS impact:* Phase 21 adds the module-scoped `hrms` step to the same wizard.

3. **Found while fixing #1 — `store` never normalized the email case.** Login lower-cases the submitted
   address for the routing lookup (`Str::lower(trim(...))`) and then calls `Auth::attempt`, which matches
   the tenant DB `users.email` **case-sensitively**. A user created as `New.Hire@Acme.Test` could
   therefore only sign in by typing that exact casing back in; `new.hire@acme.test` returned 422. This
   affected `POST /api/users` only — `RegisterController` and `TenantProvisioner::syncRouting` already
   lower-cased. Fixed by `$request->merge(['email' => Str::lower(trim(...))])` **before** validation, so
   the `unique:users,email` rule also checks the normalized form.
   *HRMS impact:* every HRMS-created user (Phase 2 employees, Phase 4 onboardees) would otherwise have
   inherited the same trap.

**Status: done.** `UserController::store` writes the routing row and normalizes the email;
`TenantController::updateSelfProfile` + `PUT api/tenant/profile` exist (5 business fields, `404` without
a tenant context, billing/entitlement fields unreachable). Regression tests:
`tests/Feature/TenantUserCreationTest.php` (8 tests, incl. a real login round-trip) and 6 new cases in
`tests/Feature/TenantProfileTest.php`. Suite: 368 tests / 2629 assertions.

### 0.8 Structure & logging gate (run on every task, before committing)

The executable form of D2.16 + D2.17. A task is not done until every box is true.

**Structure (D2.16)**

- [ ] Controller does validate → authorize → one service call → present; no queries, no business rules
- [ ] Every write endpoint has a `FormRequest` in `Http/Requests/Hrms/<Context>/`; the controller uses
      `$request->validated()` and types the request in the signature
- [ ] Business logic lives in a service under `Services/Hrms/<Context>/`; models hold no orchestration
- [ ] The response shape comes from a presenter in `Http/Resources/Hrms/<Context>/`; no endpoint returns
      `$model->toArray()`, no endpoint invents its own key names
- [ ] Every new state field is a backed enum in `Enums/Hrms/`, used by the model cast, the query and the
      validation; no raw status strings in code
- [ ] New file sits in the right context folder; no class added to an unrelated flat namespace
- [ ] Cross-context use goes through the other context's **service**, not its models
- [ ] Money uses `Support/Hrms/Money` (integer minor units); no floats, no inline arithmetic
- [ ] Ceilings respected: class ≤ 300 lines, method ≤ 40 lines, ≤ 4 args, ≤ 3 nesting levels
- [ ] Every method has a return type; array shapes documented; no new untyped properties
- [ ] No `dd`/`dump`/`ray`/`var_dump`, no commented-out code, no unexplained `TODO`

**Logging (D2.17)**

- [ ] Every log call names the channel explicitly: `Log::channel('hrms')` (never the default)
- [ ] Event name is a dotted identifier (`leave.request.approved`) — not a sentence
- [ ] Context is an array carrying `tenant_id` (+ `user_id`, `employee_id`, and the entity's own id)
- [ ] Correlated through `CorrelationId` (inherited automatically in jobs/commands)
- [ ] Each job/command logs start, success (with `count` + `duration_ms`) and failure (with exception)
- [ ] Each state transition in an approval/payroll/leave flow logs at `info`
- [ ] Degraded-but-recovered paths log at `warning` with the reason
- [ ] Row loops log `batch` / `processed` / `failed` / `duration_ms`
- [ ] **Nothing sensitive logged** (D2.17.8): no salary, bank, PAN/UAN/ESI, national id, document
      content, password, token, signed URL, or full request body
- [ ] At least one test asserts the event name + `tenant_id` on the `hrms` channel (Part 6 logging row)

---

## Part 1 — Architecture analysis (what already exists)

### 1.1 Tenancy model (Phase 13, isolated-only)

- One PostgreSQL database per tenant (`flowsync_tenant_{centralTenantId}`) plus a central `system` DB.
- `app/Support/TenantDatabaseManager.php` — singleton. `connect()` / `connectSystem()` switch the
  default in-app connection; `using($tenant, fn)` scopes a closure to a tenant DB and is **nestable**.
- `app/Support/TenantContext.php` — guard-intent singleton (`tenantId`, `impersonating`).
- **HRMS tables live in the tenant database and never carry `tenant_id`.** The DB is the boundary.
- The only HRMS data that belongs centrally is *entitlement*: which tenants have HRMS on
  (`tenants.features_override.modules`).

### 1.2 Provisioning (how tenant DBs get built)

`app/Support/TenantProvisioner.php::provisionIsolated()` — exact step order:

```
connectSystem()
  -> guarded lifecycle transition to `provisioning` (only if not already serviceable+provisioned)
  -> createDatabase()                       (provisioning_status = db_created)
  -> using($tenant, fn {
        migrateTenant()                    (provisioning_status = migrated)
        seed()                             (provisioning_status = seeded)
     })
  -> syncRouting()                          (mirror users -> central tenant_users)
  -> provisioning_status = provisioned, provisioned_at stamped
  -> lifecycle transition -> trial | active
```

`seed()` sub-steps, in order — **the extension point for HRMS defaults**:

```php
permissions (firstOrCreate by slug) -> roles (sync permissions) -> provisionPriorities()
  -> provisionProjectRoles() -> createAdmin() -> ensureDefaultUser()
```

`provisionPriorities()` / `provisionProjectRoles()` are the templates to copy:
`Model::firstOrCreate(['slug' => ...])` over a `config/*.php` catalog. `seed()` runs **inside
`using($tenant)`**, so `TenantContext::currentId()` is set — meaning `TenantLimits::assertQuota()` is
**not** a no-op there. Never call quotas from a seed step.

Callers of `provisionIsolated()` — a new seed step therefore covers all of them automatically:
`app/Jobs/ProvisionTenantJob.php:63`, `app/Console/Commands/ProvisionTenants.php:32`
(`php artisan tenants:provision`), `database/seeders/TenantSeeder.php:46,74`,
`database/seeders/ScaleDataSeeder.php:103`, plus several tests.

### 1.3 Migration application to the 102 live dev tenants

`TenantDatabaseManager::migrateTenant()` runs
`Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true])`.

- It runs **pending only** and stamps a per-tenant `migrations` table.
- Therefore **a new tenant migration auto-applies to every existing tenant the next time
  `provisionIsolated` runs** (`php artisan tenants:provision`, or any new-tenant creation).
- Therefore a migration that must **backfill data for existing tenants** does the backfill inside the
  migration, guarded like `2026_09_26_000013_add_default_user_to_users_table.php`
  (`Schema::hasColumn` first, `DROP INDEX IF EXISTS` first) so a half-failed run is re-runnable.
- Next free migration timestamp: **`2026_09_27_000014_...`**. Never reuse a timestamp, never renumber.

### 1.4 Subscription + feature control (the gate HRMS plugs into)

- `config/subscriptions.php` — `modules` list (currently `time_tracking`, `reports`, `global_search`,
  `api`, `branding`, `audit_export`), `limits` list, and the `starter` / `pro` / `enterprise` plan
  definitions whose `limits.modules` arrays carry enabled modules.
- `SubscriptionPlanSeeder` — `updateOrCreate` by slug. **Re-running the seeder overwrites `limits` on
  existing plans**, so changing a plan's module list is a deliberate data migration (see `P1.5`).
- `app/Services/TenantLimits.php` — `effective(Tenant)` = plan limits (+) `tenants.limits_override`;
  `hasModule($tenant, $module)`; `assertQuota($resource, $context)` -> `ValidationException` -> 422
  `form`. **A tenant with no subscription is unlimited** (keeps seeded/demo tenants working).
- `app/Http/Middleware/EnsureModule.php` — alias `ensure_module`, priority-registered before
  `SubstituteBindings`; 403s a route group when the effective plan lacks the module; **bypasses when
  `TenantContext::currentId() === null`** (non-impersonating super admin, provisioning, seeders).
- `app/Http/Controllers/FeatureManagementController.php` — module x plan toggle grid persisted into
  `plans.limits.modules`, audit action `plan.module_toggled`.
- `AuthController::payload` (`me()`) fills `user.modules` = effective modules (full catalog fallback
  when the tenant has no subscription).
- Frontend: `AuthContext.hasModule()`, `<ProtectedRoute module="...">` -> redirects `/403`; Sidebar
  items carry a module filter; `pages/FeatureManagement.jsx` + `components/billing/LimitsEditor.jsx`.

### 1.5 Routing, middleware order, and request context

Domain group order (`routes/web.php`):

```
['switch_tenant', 'auth', 'tenant', 'tenant_context', 'onboarding_complete']
  -> then per route: 'permission:<slug>'  and/or  'ensure_module:<module>'
```

HRMS sits beside four existing groups: the `auth -> tenant` group (self-service, wizard, `my-*`), the
domain group above, the `super_admin` group, and the signed/file routes (outside everything).

### 1.6 Existing domain primitives HRMS reuses

| Primitive | Where | HRMS use |
|---|---|---|
| `activities` + `ActivityLogger::log(subjectType, subjectId, action, data, actor, ipAddress)` | `app/Services/ActivityLogger.php` | task-facing audit; HRMS gets its own append-only log (D2.5) |
| `notifications` + `NotificationService` + `NotificationSent` on `PrivateChannel('user.{id}')` | `app/Services/NotificationService.php` | leave/expense/payroll/document notifications |
| `work_logs` (`user_id`, `started_at`, `duration_minutes`) | tenant DB | optional attendance source, performance evidence, payroll input |
| `tasks` (`assignee_id`, `project_id`, `status_id`, `completed_at`, `estimate_minutes`, `due_date`) | tenant DB | performance-goal evidence, onboarding task checkboxes |
| `ScopesVisibleTasks::visibleTaskQuery(User)` | `app/Http/Controllers/Concerns/` | the **only** sanctioned way to query tasks outside a project context |
| `user_settings.settings` JSON | tenant DB | employee notification/display preferences |
| `users` (`id`, `name`, `email`, `is_default`, `roles()`) | tenant DB | `employees.user_id` unique FK — one user = one employee |
| `TenantUserRouting` (central `tenant_users`) | system DB | login routing; see 0.7 bug 1 |
| `Cache::remember()` for expensive aggregates | `SystemAnalyticsController`, `TenantController::stats` | HR analytics caching pattern |

### 1.7 Test infrastructure

- `tests/IsolatesDatabase.php` (the trait) — replaces `RefreshDatabase`. File-backed `iso_system`
  sqlite for the central DB, one sqlite file per tenant under `sys_get_temp_dir()`, migrates
  `database/migrations/system`, then seeds `TenantSeeder` (which provisions `acme` + `globex`).
  **Therefore every new tenant migration and every new provisioning seed step is exercised by the
  entire suite automatically.** Helpers: `loginAs($email)`, `connectTenant($slug)`, `acme()`,
  `globex()`, `systemUser($email)`.
- Central-DB assertions: `assertDatabaseHas('...', [...], 'iso_system')`.
- Pattern files: `tests/Feature/HardeningTest.php` (soft deletes, N+1, indexes),
  `tests/Feature/ModuleGateTest.php` (module gating), `tests/Feature/CollaborationTest.php`
  (signed downloads + policy matrix), `tests/Feature/Isolated/IsolatedProvisioningTest.php`
  (hand-rolled isolated setup — prefer the trait).

### 1.8 Test-side DB driver is sqlite, production is PostgreSQL

The suite runs tenant DBs as **sqlite files**. Statements sqlite tolerates but PostgreSQL rejects
(partial index predicates on booleans, `jsonb` operators, `ILIKE` semantics, `timestamp` precision,
enum columns) only explode on a real tenant DB. For any migration touching booleans, decimals, or JSON,
also run the real path:

```
TENANT_DB_PG_ROLE=flowsync TENANT_DB_DRIVER=pgsql php artisan tenants:provision
```

### 1.9 Frontend conventions

- `resources/js/App.jsx` owns routing, `basename="/app"`.
- `context/AuthContext.jsx` — `user`, `can()`, `hasModule()`, `check('permission:slug'|'module:name')`.
- `context/ToastContext.jsx` — `const toast = useToast()`.
- UI kit: `resources/js/components/ui/` (`Select`, `Modal`, `Drawer`, `Pagination`, `EmptyState`,
  `Avatar`, `Spinner`, `Card`, `Button`, `Input`, `Alert`, `fieldStyles.js`).
- Charts: **Recharts** (already a dependency, used by `Dashboard.jsx`).
- Deep links: **all** URL building goes through `resources/js/utils/deepLinks.js`
  (`taskUrl`, `projectUrl`, `workspaceUrl`) — HRMS adds `employeeUrl`, `hrmsUrl`, `payrollRunUrl`.
- Notification copy/links: `resources/js/utils/notifications.js` (`describeNotification`,
  `notificationHref(data, type)`) — HRMS adds types here, never inline in a component.
- 403 handling: pages `catch (err.response?.status === 403) -> navigate('/403', {replace: true})`.

---

## Part 2 — Design decisions (binding for all phases)

Chosen deliberately. Do not re-litigate per phase; if a phase genuinely requires a change, change it in
this document first.

### D2.1 — One module key per feature, dotted namespace

`plans.limits.modules` gains flat dotted keys. `TenantLimits::hasModule()` compares strings, so
**`hrms.attendance.remote` works unchanged** — no new gate type, no migration on `subscription_plans`.

Hierarchy is expressed by prefix; the Feature Management UI renders a **tree** derived by splitting on
`.` (P1.6). Enabling a parent in the UI auto-checks its children; the backend treats each leaf
independently.

### D2.2 — Plan tiers

| Plan | Slug | HRMS modules |
|---|---|---|
| Plan A — Starter | `starter` | none |
| Plan B — Pro | `pro` | `hrms.core`, `hrms.documents`, `hrms.onboarding`, `hrms.offboarding`, `hrms.assets`, `hrms.attendance`, `hrms.attendance.remote`, `hrms.leave`, `hrms.comp_off`, `hrms.holidays`, `hrms.shifts`, `hrms.inbox` |
| Plan C — Business | **new: `business`** | Plan B + `hrms.compensation`, `hrms.expenses`, `hrms.performance`, `hrms.talent`, `hrms.engagement`, `hrms.analytics` |
| Plan D — Enterprise | `enterprise` | everything, incl. `hrms.payroll`, `hrms.payroll.statutory`, `hrms.leave.exemption`, `hrms.exemptions` |

`business` is **added**, not substituted, so existing tenants keep their plan. It sits at
`sort_order` 25 between `pro` (20) and `enterprise` (30), inheriting `pro`'s numeric limits widened.

### D2.3 — Super-admin per-tenant switch = `tenants.features_override.modules`

Central, additive override on top of the plan:

- `features_override.modules` **adds** modules the plan lacks (switch HRMS, or a single sub-feature, on
  for a negotiated deal).
- It **cannot remove** plan modules (removal is a plan change, audited as `plan.module_toggled`).
- `TenantLimits::effective()` merges `features_override.modules` into the module list.
- Precedence: `plan modules | override modules`.

### D2.4 — `employees.user_id` unique FK: every user is an employee

`employees.user_id` is a unique, **nullable** FK to `users.id`.

- Creating an employee for an existing user = one row linking the identity already used for tasks,
  attendance, work logs, notifications, and performance.
- Creating an employee **with** a login = create the `users` row, the routing row (0.7 bug 1), and the
  `employees` row in one flow.
- A user with no `employees` row is a **service account** (API/integration user): full task access, no HR
  self-service. This is the escape hatch for the existing 100 seeded tenants, where none of the 1,000
  seeded users will have employee rows.
- `nullable()` keeps "contractor with no login" representable.

### D2.5 — Shared primitives built once, in Phase 1

Building these first is what keeps the later phases small.

1. **`approvals` + `approval_steps`** — one generic multi-step approval engine, reused by attendance
   regularization, leave, comp-off, expenses, offboarding clearance, salary revisions, onboarding
   documents. Status vocabulary `pending|approved|rejected|cancelled` (+ `skipped` per step).
2. **`hrms_audit_logs`** — append-only, tenant-DB, `actor_user_id`, `actor_employee_id`, `subject_type`,
   `subject_id`, `action`, `data` JSON `{before, after}`, `ip_address`. **Every** sensitive mutation
   writes one. Deliberately separate from `activities` (task-facing, shown in the task timeline).
3. **`hrms_settings`** — single-row-per-tenant operational config (week start, timezone, country,
   currency, fiscal-year start, attendance rounding, leave-year start, remote clock-in policy, PF/ESI/PT/
   TDS toggles, sensitive-field masking). Created by `TenantProvisioner::provisionHrmsDefaults()` for
   **every** tenant from `config/hrms.php`, so the row always exists regardless of entitlement.

### D2.6 — New config file `config/hrms.php`

Mirrors `config/priorities.php` / `config/project_roles.php` / `config/task_statuses.php` and is the
single source of truth for per-tenant HRMS defaults: `settings_defaults`, `employment_types`,
`leave_types`, `salary_components`, `holidays` (per country/region), `shift_patterns`,
`expense_categories`, `document_types`, `statutory_configurations`, `departments`/`designations`
starters, `locations`. Every entry carries a natural key (`slug`/`code`) and is `firstOrCreate`-able.

### D2.7 — One monolithic tenant migration per phase, in the assigned timestamp order

HRMS is ~40 tables. One migration per phase keeps review, rollback reasoning, and the live-tenant
backfill simple. Timestamps are pre-assigned in Part 3.3 and **must not** be changed. A follow-up
takes the next unused `0000NN` in the same date block.

### D2.8 — Employee-sensitive data: encrypt, mask, audit reads

- `statutory_profiles.bank_account` uses an `encrypted` cast; the API returns `****1234` only.
  PAN / Aadhaar / UAN / ESI / PF numbers are **write-only**: accepted on write, returned masked plus a
  `has_*` boolean. Never returned by a list endpoint.
- Bank details, statutory ids, and payslips require a dedicated permission **and** write an
  `hrms_data_access_logs` row on view/download/export.
- `hrms.documents.view_sensitive` gates confidential documents; `hrms.payroll.view` alone is not enough
  to read another employee's payslip (`hrms.payroll.view_all` is).

### D2.9 — Payroll statutory compliance is a separate, high-risk phase

Phase 9 delivers salary structures, revision letters, payroll runs, and payslip generation with
**manual** inputs. Phase 10 delivers statutory compliance (PF/ESI/PT/TDS/LWF), gated behind
`hrms.payroll.statutory` **plus** `hrms.payroll.statutory.manage`. Rules are jurisdiction-configured
per tenant region; nothing is hard-coded per country in the service. Every computation is snapshotted
onto the payslip so a later rule change cannot rewrite history. Risk entries in Part 8.

### D2.10 — Performance never auto-scores

Automated signals (tasks completed, hours logged, overdue count) are computed and stored as
**evidence** on the goal and shown to the reviewer. They are **never** converted into a rating or a
grade. Every rating is entered by a human. A goal's `progress_percent` may be derived; the
`review_summaries` ratings are manual-only, and the UI must not display a composite score.

### D2.11 — HRMS queries go through `employees`, never through `User` directly

Join `employees.user_id` <-> `users.id` where a task/activity/work-log integration is needed. Keeps
HRMS working for employees whose user row is later deactivated, and keeps payroll logic out of the
RBAC model.

### D2.12 — Self-service is authenticated + policy, not permission-gated

Requesting your own leave, clocking in, uploading your own document, and reading your own payslip must
work for **any** logged-in employee without an HRMS tenant permission. Therefore:

- `hrms.view` is added to the `viewer` and `editor` role subsets so the HRMS nav is reachable.
- Self-service routes are gated by **policy** (self-or-privileged), not by `permission:`.
- Tenant-level permissions gate manager/HR surfaces only.

### D2.13 — Files follow the fixed signed-download pattern

A download route that must work in a fresh tab **cannot** rely on the tenant connection being switched,
because it sits outside `switch_tenant`. Every HRMS file download (documents, payslips, letters,
receipts) therefore uses:

```php
// presenter
'url' => url()->temporarySignedRoute('hrms.documents.download', now()->addHours(1), [
    'document' => $doc->id,
    'tenant'   => $this->tenantContext->currentId(),   // central id, signed
]),

// route registered OUTSIDE switch_tenant/auth, middleware ['signed']
public function download(Request $request, int $document) {
    $tenant = Tenant::find($request->query('tenant'));          // central connection
    abort_unless($tenant, 404);
    abort_unless($tenant->isServiceable(), 404);
    return TenantDatabaseManager::using($tenant, fn () => /* lookup + Storage::disk()->download() */);
}
```

Editing the signed `tenant` param invalidates the signature -> 403. **Write the regression test.**

### D2.14 — Entitlements never become a cross-tenant read vector

`EnsureModule` bypasses when there is no tenant context, so HRMS must not sit behind it alone.
**Every** HRMS route requires `tenant_context` (403 for a non-impersonating super admin), and the only
HRMS routes in the `super_admin` group are entitlement endpoints (`GET|PUT api/tenants/{tenant}/hrms`).

### D2.15 — Every module-gated route group gets its own test

Each phase registers its route groups with **exactly** the gate named in **3.5 Route-group → module gate
mapping**; if a phase needs a different gate, change 3.5 first. A group whose endpoints span modules
(`inbox`, `audit`) is deliberately un-gated and relies on the aggregate. Add
`tests/Feature/ModuleGateTest.php` coverage for every new gate.

### D2.16 — Code structure & quality standard (binding)

HRMS is the largest module in the codebase (~40 tables, 22 module keys, ~50 endpoints). Written
carelessly it becomes an unmaintainable pile of fat controllers and stringly-typed arrays. This
decision is **binding on every HRMS file**, and the gate in 0.8 is checked on every task.

**D2.16.1 — Strict layering, one direction of dependency**

```
Route → FormRequest → Controller → Policy → Service → Model → DB
```

| Layer | Allowed | Forbidden |
|---|---|---|
| **Controller** | resolve the request, call the policy, call **one** service method, hand the result to a presenter | any query building, `Model::create` with conditional arrays, business rules, `if` chains over domain state, calling another controller |
| **Service** | all business logic, transactions, model writes, logging, notification side-effects | raw HTTP concerns (`Request`, `JsonResponse`, `response()`), reading `$_GET`, depending on a controller |
| **Model** | relations, casts, scopes, small invariants (`deleting` hooks) | orchestrating other models, issuing HTTP requests, deciding approvals |
| **Presenter** | turning a model into the API payload | queries, decisions, authorization |

A controller must read like: *validate → authorize → delegate → present*. If a controller exceeds ~40
lines, the logic belongs in a service.

**D2.16.2 — Bounded contexts, one folder per context**

Never a flat pile of `app/Services/Hrms/LeaveService.php`. Each HRMS phase is a context with its own
folder, mirroring the phase split:

```
app/
  Enums/Hrms/                        one enum per state vocabulary (P1.9)
  Http/Controllers/Api/Hrms/<Context>/<Resource>Controller.php
  Http/Requests/Hrms/<Context>/<Action>Request.php        (writes only)
  Http/Resources/Hrms/<Context>/<Resource>Resource.php    (presenters)
  Models/Hrms/<Context>/<Model>.php
  Policies/Hrms/<Context>Policy.php
  Services/Hrms/<Context>/<Service>.php
  Jobs/Hrms/<Context>/<Job>.php
  Console/Commands/Hrms/<Command>.php
  Support/Hrms/<ValueObject>.php                          (Money, DateRange, Balance…)
```

Contexts (one folder each, named exactly): `Shared`, `Employee`, `Org`, `Lifecycle`, `Attendance`,
`Leave`, `CompOff`, `Holiday`, `Payroll`, `Statutory`, `Expense`, `Performance`, `Document`, `Asset`,
`Inbox`, `Engagement`, `Reporting`, `Audit`, `TaskLink`.

- **Dependency rule:** context A may call context B's **service**, never B's models or queries. If
  Payroll needs leave balances it calls `LeaveService::balanceFor()`, which it already depends on (D2.5).
- A context that needs data from another context's tables gets it through that context's service — this
  is what keeps the circular-dependency risk in R9 visible at review time instead of in production.

**D2.16.3 — Size and complexity ceilings (review-enforced)**

| Thing | Ceiling | Instead of exceeding it |
|---|---|---|
| Class length | 300 lines | extract a collaborator class |
| Method length | 40 lines | extract named private methods |
| Method arguments | 4 | pass a readonly DTO |
| Nesting depth | 3 | early returns / guard clauses |
| Cyclomatic complexity per method | 10 | split into a strategy or a service method |
| `if`-chains over a state field | any | an enum with `canTransitionTo()` / `label()` |

**D2.16.4 — One payload shape per resource, defined once**

Every resource gets a presenter (`Http/Resources/Hrms/…`) with a single `toArray()`. No endpoint invents
its own key names, and no endpoint returns `$model->toArray()`. Sensitive fields are masked **in the
presenter**, never in the controller, and never in a list payload.

**D2.16.5 — Enums over strings, everywhere**

Every state vocabulary is a backed enum in `app/Enums/Hrms/` (`ApprovalStatus`, `LeaveRequestStatus`,
`EmployeeStatus`, `PayrollRunStatus`, `AttendanceDayStatus`, `AssetCondition`, …). Queries, model casts
and validation all use the enum. Domain questions live on the enum (`->isTerminal()`, `->canTransitionTo()`,
`->label()`, `->color()`), so controllers never switch on raw strings.

**D2.16.6 — Readonly value objects for anything numeric**

`Support/Hrms/Money` wraps integer minor units with bcmath-safe add/subtract/multiply/allocate
(`Money::allocate(1430, 3)` splitting 14.30 three ways without losing a paisa). `DateRange`,
`LeaveBalanceSnapshot` and `StatutoryProfileSnapshot` are readonly. **No float ever touches money**
(0.6) and no arithmetic happens inline in a controller or a Blade/React render path.

**D2.16.7 — Typing and naming**

- Every method has a return type; properties are typed; constructor promotion everywhere.
- Array shapes are documented: `@return array{id:int, code:string, label:string}`.
- `mixed` only where a framework forces it. No untyped properties, no `$data['x']` chains deeper than
  one level in a service (map to a DTO at the boundary).
- Names: `Hrms` prefix for cross-cutting (`HrmsAuditLogger`, `HrmsSetting`), context name for domain
  (`LeaveService`), verb-noun methods (`approveLeave`, `rebuildBalance`) — never `processLeave`,
  `handleStuff`, `doIt`.
- **No abbreviations** (`$emp`, `$usr`, `$cfg`) except the existing tenant-DB convention
  (`db_name`, `tenant_id`).

**D2.16.8 — Cleanliness**

No commented-out code, no `dd()`/`dump()`/`ray()`/`var_dump()` in committed code, no dead code, no
unused imports, no `TODO` without an owning issue reference, no `@todo`. Pint is the floor, not the
ceiling — Pint cannot judge structure, so 0.8 is the human/agent gate.

**D2.16.9 — Tests mirror the structure**

`tests/Feature/Hrms/<Context>Test.php` for endpoint behaviour, `tests/Unit/Hrms/<Context>/` for pure
services, value objects and enums (fast, no DB). A value object with arithmetic **must** have unit
tests — that is where payroll bugs are caught cheaply.

### D2.17 — Observability & logging standard (binding)

The repo currently logs almost nothing (one `Log::error` in `ProvisionTenantJob`). HRMS handles payroll,
PII, and long-running batch work, so "we can see what happened" is a feature, not a nicety. Logging is
**as binding as** the structure rules above, and it is a correctness concern, not decoration: without it,
a payroll run that produced wrong numbers is undiagnosable.

**D2.17.1 — Two distinct mechanisms, never conflated**

| Mechanism | Where it goes | Purpose | Who reads it |
|---|---|---|---|
| `HrmsAuditLogger` (D2.5) | the `hrms_audit_logs` **table** | business record: *who did what to which record* | HR/admin users, auditors, the Audit page (P19) |
| `Log::channel('hrms')` | `storage/logs/hrms-*.log` | operational debugging: *what failed, where, how slow* | developers, `tail -f` during support |

A state change writes **both**: the audit row is the product's record, the log line is our trail. Never
substitute one for the other. Never write a business event only to the log (it is not queryable) or only
to the table (no stack trace, no timing, no correlation).

**D2.17.2 — A dedicated `hrms` channel (P1.1)**

`config/logging.php` gains a `hrms` channel: `daily` driver, `storage_path('logs/hrms.log')`, `days` from
`HRMS_LOG_DAYS` (default 30), level from `HRMS_LOG_LEVEL` (default `info` in production, `debug`
locally). Every HRMS log call names the channel explicitly — never the global default. This keeps
HRMS noise out of `laravel.log` and lets it be rotated/deleted/grepped on its own terms.

**D2.17.3 — Structured, dotted event names — never sentences**

```php
Log::channel('hrms')->info('leave.request.approved', [
    'leave_request_id' => $request->id,
    'employee_id'      => $request->employee_id,
    'days'             => 3,
    'approval_id'      => $approval->id,
]);
```

- Event name = `<context>.<entity>.<past-tense-verb>`, lowercase dot-separated, a stable identifier.
- Context = an **array**, never an interpolated string (`"approved leave for {$name}"` is forbidden).
- The same event name is reused everywhere that transition happens, so `grep "leave.request.approved"`
  answers "who had leave approved, when, by whom".

**D2.17.4 — Mandatory context keys**

Set once per unit of work and merged into every entry by a helper (P1.10 `HrmsLog`):

| Key | Source |
|---|---|
| `request_id` | correlation id (D2.17.5) |
| `tenant_id` | `TenantContext::currentId()` (central id) |
| `user_id` | acting user, when authenticated |
| `employee_id` | subject employee, on employee-scoped events |
| `route` + `method` | the HTTP route pattern (never the raw URL with query strings) |

Then the event-specific keys (`leave_request_id`, `payroll_run_id`, `batch`, `count`, `duration_ms`).

**D2.17.5 — Correlation id across requests, jobs and commands**

`App\Support\CorrelationId` + `App\Http\Middleware\AssignCorrelationId` (registered in the `web` group,
first): reads an inbound `X-Correlation-Id` (or generates a ULID), calls `Log::withContext(['request_id' =>
...])`, and returns it as an `X-Correlation-Id` response header so a user can quote it in a bug report.
Queued jobs and console commands inherit it the same way `SwitchesTenantConnectionForQueuedJobs` already
stamps `tenant_id` (`Queue::createPayloadUsing`), so a payroll job's log lines join up with the request
that queued them. This is the single highest-value piece of the standard — without it, logs cannot be
assembled per user action.

**D2.17.6 — What MUST be logged**

- **Lifecycle of every job and command:** start (with the work's identifying keys), success (with
  `count`/`duration_ms`), failure (with the exception, at `error`).
- **Every state transition** in an approval engine, a payroll run, a leave request — `info`.
- **Every provisioning/seed step**, including HRMS defaults and skipped steps, at `debug` (info for
  failures).
- **Every sensitive data access** — in addition to the `hrms_data_access_logs` row (D2.8), because a
  compliance trail is not a debugging trail.
- **Every caught exception** with full context, at `error`, and rethrown unless the recovery is real.
- **Every external I/O:** file stored/deleted, signed URL issued, mail/notification dispatched.
- **Degraded-but-recovered paths** at `warning`: quota exceeded, cache miss fallback, retry used, a
  stale-tenant repair, a skip-because-no-approver step.
- **Any loop over rows** logs its `batch`, `processed`, `failed` and `duration_ms` — the first question
  is always "was it slow, or was it wrong?"

**D2.17.7 — Level discipline**

| Level | Use for | Example |
|---|---|---|
| `emergency` | tenant data at risk or a rule violation | statutory totals that do not balance |
| `error` | an operation failed; a human must look (include the exception) | payroll run threw mid-calculation |
| `warning` | degraded but recovered | quota hit, retry, cache fallback, step auto-skipped |
| `info` | significant business/lifecycle events | `leave.request.approved`, job succeeded |
| `debug` | per-request detail, SQL counts, cache hits | query-count assertions, resolved approver ids |

Never log a plain `ValidationException`/422 at `error` — a rejected form is normal traffic; it goes to
`debug` at most. Never `Log::info` an entire model.

**D2.17.8 — What must NEVER be logged (hard ban, R2)**

No salary amounts or CTC, no bank account/IFSC, no PAN/Aadhaar/UAN/ESI/PF numbers, no national ids, no
document contents or file paths that reveal names, no passwords, tokens, signed URLs, or full request
payloads/bodies for HRMS endpoints. Log **ids and masked values only** (`'bank' => '****1234'`,
`'employee_id' => 42`). If a value would be sensitive in a support ticket, it does not go in the log.

**D2.17.9 — Testable**

Part 6 gains a logging row: at least one test per phase asserts a `Log::fake`-spied channel (or the
`hrms` channel's spy) received the event name with `tenant_id` in context for a significant action, and
that a sensitive-field read produced **no** forbidden key. `Log::fake()`/`Log::spy()` is the only
assertion mechanism; never assert on real files.

---

## Part 3 — Module / permission / data-model reference

### 3.1 Module keys (into `config/subscriptions.php::modules`)

```
hrms.core                  Employee directory, records, org structure access
hrms.onboarding            New-hire onboarding cases + templates
hrms.offboarding           Offboarding cases, exit clearance
hrms.attendance            Shifts, clock-in/out, daily records, regularization
hrms.attendance.remote     Remote (web/mobile) punch-in with IP + geo validation
hrms.shifts                Shift patterns, rosters, weekly offs
hrms.leave                 Leave types, policies, balances, requests, approvals
hrms.leave.exemption       Statutory leave-exemption requests (jurisdiction-gated)
hrms.comp_off              Comp-off accrual and redemption
hrms.holidays              Holiday calendars, regional, optional holidays
hrms.expenses              Claims, categories, receipts, approval, reimbursement
hrms.compensation          Salary structures, CTC revisions, letters
hrms.payroll               Payroll runs, payslips, disbursement
hrms.payroll.statutory     PF/ESI/PT/TDS/LWF (high risk, jurisdiction-configured)
hrms.performance           Cycles, goals, check-ins, one-on-ones
hrms.talent                Review cycles, ratings, calibration, 360 feedback
hrms.engagement            Surveys, pulse, engagement results
hrms.documents             Employee document store, expiry, verification
hrms.assets                Asset register, assignment, return
hrms.inbox                 HR inbox (approvals + assigned action items)
hrms.analytics             HR dashboards and reports
hrms.exemptions            Annual exemption/declaration tracking (built in P10, `statutory_declarations`)
```

### 3.2 Permission slugs (into `config/permissions.php::permissions`)

Naming follows the existing `group.verb` convention.

```
hrms.view                    Reach HRMS surfaces (added to viewer + editor)
hrms.employees.view          View the employee directory + profiles
hrms.employees.manage        Create/edit employee records
hrms.org.view                View org structure
hrms.org.manage              Manage departments, designations, locations
hrms.onboarding.view         View onboarding cases
hrms.onboarding.manage       Create/run onboarding cases
hrms.offboarding.view        View offboarding cases
hrms.offboarding.manage      Run offboarding, sign off clearance
hrms.attendance.view         View attendance records (own always allowed)
hrms.attendance.manage       Edit/delete punches and day records
hrms.attendance.regularize   Approve attendance regularization requests
hrms.attendance.settings     Configure shifts, rosters, clock-in policy
hrms.leave.view              View leave types/policies/balances (own always allowed)
hrms.leave.manage            Manage leave types, policies, balances
hrms.leave.approve           Approve/reject leave requests
hrms.comp_off.view           View comp-off credits/balances
hrms.comp_off.approve        Approve/reject comp-off requests
hrms.comp_off.manage         Configure comp-off policy
hrms.shifts.view             View shifts/rosters
hrms.shifts.manage           Configure shifts/rosters
hrms.holidays.view           View holiday calendars
hrms.holidays.manage         Manage calendars and holidays
hrms.expenses.view           View expense claims (own always allowed)
hrms.expenses.approve        Approve/reject claims
hrms.expenses.manage         Manage categories and policy
hrms.compensation.view       View salary structures and letters
hrms.compensation.manage     Change salary structures / revisions        [SENSITIVE]
hrms.payroll.view            View payroll runs and own payslip
hrms.payroll.view_all        View every employee's payslip                [SENSITIVE]
hrms.payroll.run             Create/run/approve/disburse payroll         [SENSITIVE]
hrms.payroll.manage          Manage salary components/structures         [SENSITIVE]
hrms.payroll.statutory.view   View statutory config, projections and masked ids
hrms.payroll.statutory.manage Configure statutory rules + employee ids   [VERY SENSITIVE]
hrms.performance.view        View cycles, goals, reviews
hrms.performance.manage      Create cycles, manage goals
hrms.talent.view             View reviews and ratings (per anonymity policy)
hrms.talent.manage           Run review cycles, calibrate
hrms.engagement.view         View surveys and results
hrms.engagement.manage       Create surveys/campaigns
hrms.documents.view          View documents (own always allowed)
hrms.documents.manage        Upload/verify/expire documents
hrms.documents.view_sensitive View confidential/bank/statutory documents [SENSITIVE]
hrms.assets.view             View the asset register
hrms.assets.manage           Manage assets and assignments
hrms.analytics.view          HR analytics dashboards
hrms.audit.view              View HRMS audit log
```

Role updates in `config/permissions.php::roles`: `admin` keeps `permissions => '*'` and picks up
everything automatically. Phase 1 adds `hr_manager` (all `hrms.*` except `hrms.payroll.*`,
`hrms.compensation.manage`, `hrms.payroll.statutory.*`) and `payroll_manager` (`hrms.view`,
`hrms.payroll.*`, `hrms.compensation.*`, `hrms.attendance.*`, `hrms.leave.*`, `hrms.expenses.*`).
`editor` and `viewer` get `hrms.view` only (D2.12).

### 3.3 Table inventory (tenant DB, no `tenant_id` column)

| Migration | Phase | Tables |
|---|---|---|
| `2026_09_27_000014_create_hrms_shared_tables` | P1 | `approvals`, `approval_steps`, `hrms_audit_logs`, `hrms_data_access_logs`, `hrms_settings` |
| `2026_09_27_000015_create_hrms_employee_tables` | P2 | `employment_types`, `employees`, `employee_status_history` |
| `2026_09_27_000016_create_hrms_org_tables` | P3 | `departments`, `designations`, `locations` |
| `2026_09_27_000017_create_hrms_lifecycle_tables` | P4 | `onboarding_templates`, `onboarding_template_tasks`, `onboarding_cases`, `onboarding_case_tasks`, `offboarding_cases`, `offboarding_case_tasks`, `exit_clearances`, `document_requests` |
| `2026_09_28_000018_create_hrms_attendance_tables` | P5 | `attendance_shifts`, `attendance_rosters`, `attendance_punches`, `attendance_days`, `attendance_ip_rules`, `attendance_regularization_requests` |
| `2026_09_28_000019_create_hrms_leave_tables` | P6 | `leave_types`, `leave_policies`, `leave_policy_types`, `leave_balances`, `leave_adjustments`, `leave_requests`, `leave_request_days`, `leave_exemption_requests` |
| `2026_09_29_000020_create_hrms_comp_off_tables` | P7 | `comp_off_credits`, `comp_off_requests`, `comp_off_request_days` |
| `2026_09_29_000021_create_hrms_holiday_tables` | P8 | `holiday_calendars`, `holidays`, `employee_holiday_calendars`, `holiday_optional_holidays` |
| `2026_09_30_000022_create_hrms_payroll_tables` | P9 | `salary_components`, `salary_structures`, `salary_structure_components`, `employee_salary_structures`, `salary_revisions`, `payroll_runs`, `payslips`, `payslip_adjustments`, `payslip_templates` |
| `2026_09_30_000023_create_hrms_statutory_tables` | P10 | `statutory_configurations`, `statutory_profiles`, `statutory_declarations`, `tds_projects` |
| `2026_10_01_000024_create_hrms_expense_tables` | P11 | `expense_categories`, `expense_claims`, `expense_claim_items` |
| `2026_10_01_000025_create_hrms_performance_tables` | P12 | `performance_cycles`, `performance_goals`, `goal_task_links`, `check_ins`, `one_on_ones`, `feedback_requests`, `feedback_responses`, `review_summaries` |
| `2026_10_02_000026_create_hrms_document_tables` | P13 | `document_types`, `employee_documents` |
| `2026_10_02_000027_create_hrms_asset_tables` | P14 | `asset_categories`, `assets`, `asset_assignments`, `asset_maintenance` |
| `2026_10_03_000028_create_hrms_inbox_tables` | P15 | `inbox_reads` |
| `2026_10_03_000029_create_hrms_engagement_tables` | P16 | `survey_templates`, `survey_questions`, `survey_campaigns`, `survey_responses`, `survey_answers`, `survey_results` |
| `2026_10_04_000030_create_hrms_reporting_tables` | P18 | `hrms_report_schedules` |
| `2026_10_04_000031_create_hrms_task_link_tables` | P20 | `hrms_task_links` |

### 3.4 Frontend routes

```
/hrms                        Overview                (module: hrms.core)
/hrms/employees               Directory               (hrms.core, hrms.employees.view)
/hrms/employees/:employeeId   Profile, tabs: overview|documents|attendance|leave|payroll|
                              performance|assets (?tab= deep link)
/hrms/org                     Org chart              (hrms.core, hrms.org.view)
/hrms/onboarding              Onboarding cases       (hrms.onboarding)
/hrms/offboarding             Offboarding cases      (hrms.offboarding)
/hrms/attendance              Attendance             (hrms.attendance)
/hrms/attendance/approvals    Regularization queue   (hrms.attendance.regularize)
/hrms/shifts                  Shifts & rosters       (hrms.shifts)
/hrms/leave                   Leave admin            (hrms.leave, hrms.leave.manage)
/hrms/comp-off                Comp-off               (hrms.comp_off)
/hrms/holidays                Holiday calendars      (hrms.holidays)
/hrms/expenses                Claims                 (hrms.expenses)
/hrms/compensation            Salary structures      (hrms.compensation)
/hrms/payroll                 Runs + payslips        (hrms.payroll)
/hrms/payroll/statutory       Statutory config       (hrms.payroll.statutory)
/hrms/performance             Cycles, goals          (hrms.performance)
/hrms/talent                  Reviews                (hrms.talent)
/hrms/engagement              Surveys                (hrms.engagement)
/hrms/documents               Document store         (hrms.documents)
/hrms/assets                  Asset register         (hrms.assets)
/hrms/analytics               HR analytics           (hrms.analytics)
/hrms/audit                   HRMS audit log         (hrms.audit.view)
/hrms/inbox                   HR inbox               (any hrms.* module)
/my/                          My HR home             (any hrms.* module, self-service)
```

All inside `App.jsx` as `<ProtectedRoute module="...">`, all using `?tab=` deep links built by
`utils/deepLinks.js`, all added to `components/layout/Sidebar.jsx` in a new **People** section gated by
module **and** permission (the Sidebar already keeps items with no `permission`).

### 3.5 Route-group → module gate mapping (binding)

Every phase registers its routes with exactly the gate named here. A phase that needs a different gate
must change this table first (R8 depends on it). Gate order within a group is always
`switch_tenant -> auth -> tenant -> tenant_context -> ensure_module:<module> -> permission:<slug>`.

| Route group (`api/hrms/...`) | Phase | `ensure_module` | Route-level `permission` |
|---|---|---|---|
| `employees*` | 2 | `hrms.core` | `employees.view` / `employees.manage` |
| `org/*` (departments, designations, locations) | 3 | `hrms.core` | `org.view` / `org.manage` |
| `onboarding*` | 4 | `hrms.onboarding` | `onboarding.view` / `onboarding.manage` |
| `offboarding*` | 4 | `hrms.offboarding` | `offboarding.view` / `offboarding.manage` |
| `attendance/*` (records, days, regularization) | 5 | `hrms.attendance` | `attendance.view` / `attendance.manage` / `attendance.regularize` |
| `attendance/punch` (remote web/mobile punch) | 5 | `hrms.attendance.remote` | authenticated own-record only |
| `attendance/shifts`, `attendance/rosters`, ip rules | 5 | `hrms.shifts` | `shifts.view` / `shifts.manage` / `attendance.settings` |
| `leave/*` types, policies, balances, requests | 6 | `hrms.leave` | `leave.view` / `leave.manage` / `leave.approve` |
| `leave/exemptions` | 6 | `hrms.leave.exemption` | `leave.manage` |
| `comp-off/*` | 7 | `hrms.comp_off` | `comp_off.view` / `comp_off.approve` / `comp_off.manage` |
| `holidays/*` | 8 | `hrms.holidays` | `holidays.view` / `holidays.manage` |
| `compensation/*` structures, revisions, letters | 9 | `hrms.compensation` | `compensation.view` / `compensation.manage` |
| `payroll/runs`, `payroll/payslips` | 9 | `hrms.payroll` | `payroll.view` (own) / `payroll.view_all` / `payroll.run` / `payroll.manage` |
| `payroll/statutory/*` incl. `.../exemptions` | 10 | `hrms.payroll.statutory` | `payroll.statutory.view` / `payroll.statutory.manage` |
| `expenses/*` | 11 | `hrms.expenses` | `expenses.view` / `expenses.approve` / `expenses.manage` |
| `performance/*` cycles, goals, check-ins | 12 | `hrms.performance` | `performance.view` / `performance.manage` |
| `talent/*` reviews, ratings, calibration | 12 | `hrms.talent` | `talent.view` / `talent.manage` |
| `engagement/*` templates, campaigns, results | 16 | `hrms.engagement` | `engagement.view` / `engagement.manage` |
| `documents/*` | 13 | `hrms.documents` | `documents.view` / `documents.manage`; confidential + bank/statutory files additionally require `documents.view_sensitive` |
| `assets/*` | 14 | `hrms.assets` | `assets.view` / `assets.manage` |
| `inbox*` | 15 | any `hrms.*` (no gate — the aggregator spans modules) | `hrms.view` |
| `analytics/*` | 18 | `hrms.analytics` | `analytics.view` (dashboard) **plus** the per-tab permission (`attendance.view`, `leave.view`, `payroll.view_all`, `performance.view`, `documents.view`, `assets.view`) — never a blanket `workspaces.view` |
| `audit/*` | 19 | any `hrms.*` | `audit.view` |
| `tasks/{task}/links`, `employees/{employee}/tasks` | 20 | `hrms.core` | `employees.view` |
| `my/hr`, `my/hr/preferences` | 17 | `hrms.core` | `hrms.view` |
| `my/team` | 17 | `hrms.core` | `attendance.view` or manager-of |
| `employees/{employee}/summary` | 17 | `hrms.core` | `employees.view` |
| `my/surveys*`, `engagement/.../respond` | 16 | `hrms.engagement` | authenticated invitee only |
| `tenants/{tenant}/hrms` (entitlement) | 1 | — (central `super_admin` group) | `super_admin` |

Note the three deliberate exceptions, all explained above: `inbox` and `audit` are **not** module-gated
because they aggregate across modules, `my/*` self-service routes are gated on the parent module only,
and the entitlement endpoint lives in the central `super_admin` group where `EnsureModule` would
always bypass (D2.14).

---

## Part 4 — Phase index

| # | Phase | Migration | Depends on |
|---|---|---|---|
| 0 | Prerequisites & bug fixes | — | — |
| 1 | Module registration, entitlement, shared primitives | `000014` | 0 |
| 2 | Core HR — employee profiles | `000015` | 1 |
| 3 | Organization structure | `000016` | 2 |
| 4 | Onboarding & offboarding | `000017` | 2, 3, 13 |
| 5 | Attendance | `000018` | 2, 3 |
| 6 | Leave management | `000019` | 1, 2 |
| 7 | Comp-off | `000020` | 1, 2 |
| 8 | Holiday management | `000021` | 2, 3 |
| 9 | Payroll (core) | `000022` | 2, 5, 6, 11, 13 |
| 10 | Payroll — statutory compliance (**high risk**) | `000023` | 9, 11, 13 |
| 11 | Expenses | `000024` | 1, 2, 13 |
| 12 | Performance | `000025` | 2 |
| 13 | Employee documents | `000026` | 1, 2 |
| 14 | Asset tracking | `000027` | 2, 3 |
| 15 | HR inbox & notifications | `000028` | 1, 2 |
| 16 | Engagement & surveys | `000029` | 2, 15 |
| 17 | My Team / self-service | — | 2, 5, 6, 13 |
| 18 | HR analytics & reports | `000030` | 2, 5, 6, 9, 12 |
| 19 | Audit, security & data protection | — | 1 (+ hardening per phase) |
| 20 | Task-management integration | `000031` | 2, 12 |
| 21 | Sidebar, navigation & UX polish | — | all |

**Ordering caveats (read before starting a phase):**
- **Phase 13 (documents) is numbered after its dependents.** Phases 4, 11, 14, 9, 10 and 17 reference
  document types. When starting any of them, confirm 13 has shipped; if not, ship 13 first rather than
  inventing a second document system.
- **Phase 11 (expenses) is numbered after Phase 9 (payroll).** Payroll reads approved expense claims as a
  reimbursable component, so ship 11 before 9. The dependency is one-way — expenses must never read
  payroll.
- **Phase 20 extends Phase 12, not the other way round.** Performance goals ship in 12 with
  `goal_task_links` and evidence derived from tasks *assigned* to the employee; 20 then adds the broader
  `hrms_task_links` and linked-task evidence. Do not defer any part of 12 to 20.
- Do **not** merge migrations across phases, and do not renumber to "fix" a dependency — record the
  deviation in this table instead.

---

## Part 5 — The phases

Each phase below lists its migration, service/policy work, routing, frontend, and acceptance criteria.
Task ids are `P<phase>.<n>` and each is one commit (0.1, 0.3). Where a phase says "routes" without
naming a module gate, the gate is the one in **3.5 Route-group → module gate mapping** — that table is
binding and every gate listed there gets a `ModuleGateTest` case (D2.15).

### Phase 0 — Prerequisites & bug fixes

**Objective:** unblock HRMS and fix the two bugs HRMS depends on.

**P0.1 — Fix the missing tenant-facing `PUT tenant/profile`**
- Add `TenantController::updateSelfProfile(Request): JsonResponse` validating the 6 fields the wizard
  sends (`legal_name`, `industry`, `company_size`, `country`, `website`), updating
  `Tenant::findOrFail(TenantContext::currentId())`, returning `{message, tenant}`.
- Add `Route::put('api/tenant/profile', ...)` in the `switch_tenant, auth, tenant` group beside the
  existing `GET`.
- Tests: wizard saves business details (200 + persisted); 422 on a `country` longer than 2; 404 for a
  non-impersonating super admin.
- Verify: `PUT /api/tenant/profile` as `admin@flowsync.test` returns 200 and the onboarding business
  step completes in the UI.

**P0.2 — Fix `UserController::store` not creating the login-routing row**
- After saving the user and roles, `TenantUserRouting::updateOrCreate(['tenant_id' => ..., 'email' => $email], ['user_id' => $user->id, 'name' => $user->name])`, inside a transaction on the tenant connection so a routing failure cannot leave a user who can never log in.
- Tests: create a user via `POST api/users`, then log in through `POST api/login` with the new
  credentials -> 200; the routing row exists on `iso_system`; a cross-tenant duplicate email is
  rejected.
- Verify: create a user in the UI, log out, log in as them.

**P0.3 — Record the HRMS baseline**
- No code change. Confirm `php artisan test` = 354/2577 and `npm run build` are green, and record the
  count in this document's header.

**Acceptance:** full suite green (>= 354/2577), both bugs regression-tested, one commit per task.

---

### Phase 1 — Module registration, entitlement & shared primitives

**Objective:** make `hrms` first-class end-to-end (catalog -> plan -> SA toggle -> middleware -> nav
placeholder) and land the three primitives every later phase builds on.

**Status: done.** 12 sections (added `departments`, `designations`, `locations` beyond the listed 9). Money-bearing entries are integer minor units / `decimal(14,2)` in later phases; nothing here is a float.

**P1.1 — `config/hrms.php`**
Create the catalog file (D2.6) with the skeleton and small starter arrays: `settings_defaults`,
`employment_types`, `leave_types`, `salary_components`, `holidays`, `shift_patterns`,
`expense_categories`, `document_types`, `statutory_configurations`. Each entry carries a natural key
(`slug`/`code`) and `is_system => true` where it must not be deletable. Later phases extend the arrays.

**Status: done.** 22 `hrms.*` keys + 3 new numeric limits (`employees`, `assets`,
`hr_document_bytes`) across all four plans; verified starter 0 / pro 12 / business 18 / enterprise 22
against the catalog, with no key orphaned in either direction.

**P1.2 — Module catalog + the new `business` plan (`config/subscriptions.php`)**
Add the 22 module keys from 3.1 to `modules`. Add the `business` plan (D2.2) with `sort_order` 25 and
the Plan B + Plan C module list. Update `enterprise::limits.modules` to include every `hrms.*` key. Add
numeric limits `employees`, `assets`, `hr_document_bytes` to `limits` and set them on `pro`, `business`,
`enterprise`.
- This changes what **existing** tenants get the next time the seeder runs. Do **not** re-seed yet —
  P1.5 does that deliberately.

**Status: done.** 47 permissions, 2 new roles. Roles now use a **selector** grammar
(`'*'`, `'hrms.*'`, `'!hrms.payroll.*'`, exact slug) resolved by the new
`App\Support\PermissionSelector` against the catalog, so a later phase adding `hrms.foo.manage` reaches
`hr_manager` automatically instead of needing a hand-edited 40-item list. The selector emits results in
catalog order so a role's stored permission set is order-stable. `admin`'s bare-string `'*'` is still
supported. 13 unit tests (pure list algebra, no framework) + provisioning assertions.

**P1.3 — Permission catalog + roles (`config/permissions.php`)**
Add all `hrms.*` permissions from 3.2. Add `hrms.view` to the `editor` and `viewer` subsets (D2.12).
Add `hr_manager` and `payroll_manager` roles as specified in 3.2.
- `TenantProvisioner::seed()` syncs `admin`'s permissions with `sync($permissions->pluck('id'))` over
  the config-built collection, so `admin` picks up every new permission on the next
  `tenants:provision` with no extra work.

**Status: done, with two deliberate deviations from the wording above.**
- `hasModule()` consults `features_override.modules` first (grant *or* switch off), then the plan, then
  treats a subscription-less tenant as unlimited. A flat string compare is still what makes the dotted
  keys free — the override is resolved as an exact key, never a glob.
- Instead of an `isEnabled()` alias (which would just be a second name for
  `hasModule()`), added `enabledModules(Tenant)` and `isHrmsEnabled(Tenant)`: the first feeds
  `user.modules` in `AuthController::payload` and keeps catalog ordering, the second is the single
  predicate the frontend needs for "is any HRMS surface available at all". `hasModule()` stays the
  per-module check so no existing call site changes.
- 11 tests in `HrmsEntitlementTest`.

**P1.4 — Feature-override plumbing (`TenantLimits`)**
- `effective(Tenant)`: merge `features_override.modules` (D2.3); plan stays authoritative for removal.
- `hasModule()` unchanged (string compare) — that is what makes dotted keys free (D2.1).
- Add a short-named `isEnabled(Tenant, string $module)` wrapper for readability at call sites.

**Status: done.** 7 tests in `HrmsPlanEntitlementMigrationTest`, including two that would have
caught real bugs during implementation:
- `test_numeric_limits_survive_the_merge` — the first implementation replaced the whole `limits`
  *object* with just the modules array, silently wiping `users`/`workspaces`/`projects`/`tasks` and
  marking every tenant unlimited. The merge now edits the `modules` **sub-key** and leaves numeric
  limits to config (the same contract `SubscriptionPlanSeeder` uses).
- `test_it_never_re_plans_a_tenant` — the first implementation moved `pro` tenants onto `business`,
  which would have silently changed what customers are billed ($29 → $79/mo) and handed Plan C
  (expenses, performance, talent, engagement, analytics) to existing Pro accounts for free. A migration
  must never re-plan a tenant; upgrades stay explicit and audited via `TenantSubscriptionController`.

`down()` is intentionally a no-op: module keys are additive and the dependents are not inferable from
the catalog, so there is no safe automatic reverse (matches the repo's forward-fix discipline).

**P1.5 — System migration: backfill entitlement + seed the new plan**
`database/migrations/system/2026_09_27_000014_add_hrms_to_plan_modules.php` (system path — this is a
*plan* change, not a tenant-table change). Repair-safe: for each plan slug in the catalog,
`updateOrCreate` and **merge** (not replace) `limits.modules` with the new keys, so an existing `pro`
tenant gains the Plan B set without losing anything. Seed the new `business` row. Leave
`tenants.features_override` untouched where already set.
- Tests: `business` exists after migrate; `pro` gains `hrms.core`; `starter` gains **no** `hrms.*`;
  running the migration twice is a no-op.
- Verify: `php artisan migrate --database=system --path=database/migrations/system`, then inspect
  `subscription_plans`.

**P1.6 — Feature Management UI: module tree**
`resources/js/pages/FeatureManagement.jsx` currently renders a flat module x plan checkbox grid. Convert
it to a **tree** derived by splitting each module key on `.` (`hrms` -> `hrms.attendance` ->
`hrms.attendance.remote`). Toggling a parent checks all descendants; a partial parent is
indeterminate. Preserve the existing save endpoint and the `plan.module_toggled` audit. Verify
`pages/Plans.jsx` picks up the new `business` plan row (it is API-driven, so likely nothing to do).
- Verify manually: tick `hrms.attendance.remote` for `pro` only, reload, confirm persisted; confirm
  `business` and `enterprise` show the expanded tree.

**P1.7 — Super-admin per-tenant HRMS switch**
- `GET api/tenants/{tenant}/hrms` -> `{hrms: {enabled, modules, source, effective_modules, settings}}`
  where `source` is `override | plan | none`.
- `PUT api/tenants/{tenant}/hrms` body `{modules: [...]}` (additive) or `{enabled: bool}`; writes
  `tenants.features_override.modules` and an `audit_logs` row `tenant.hrms_updated` with
  `{before, after}`.
- Validation: every key must exist in `config('subscriptions.modules')` (422 otherwise) so typos cannot
  silently disable enforcement.
- Frontend: an **HRMS** section on `pages/TenantDetail.jsx` (toggle + module checklist mirroring the
  tree) and an `HRMS on` / `HRMS off` pill on `pages/Tenants.jsx`.
- Tests: unknown module -> 422; `{modules: ['hrms.payroll']}` -> 200 and `features_override` updated on
  `iso_system`; GET reflects it; an audit row is written; a tenant admin gets 403.

**P1.8 — Shared migration `000014`**
`database/migrations/tenant/2026_09_27_000014_create_hrms_shared_tables.php`:
- `approvals` — `approvable_type`, `approvable_id`, `subject`, `action`, `status`
  (`pending|approved|rejected|cancelled`), `requested_by_user_id`, `requested_by_employee_id`,
  `current_step`, `due_at`, `resolved_at`, `resolved_by_user_id`, `decision_note`, `meta` JSON,
  timestamps, `softDeletes`. Indexes `(approvable_type, approvable_id)`, `(status, current_step)`,
  `(requested_by_user_id, created_at)`.
- `approval_steps` — `approval_id` FK cascade, `step_order`, `approver_type`
  (`role|user|manager|department_head`), nullable `approver_role_id` / `approver_user_id` /
  `approver_employee_id`, `status` (`pending|approved|rejected|skipped`), `acted_at`,
  `acted_by_user_id`, `note`. Unique `(approval_id, step_order)`.
- `hrms_audit_logs` — `actor_user_id`, `actor_employee_id`, `subject_type`, `subject_id`, `action`,
  `data` JSON `{before, after}`, `ip_address`, `created_at` (**no** `updated_at` — append-only).
  Indexes `(subject_type, subject_id)`, `(actor_user_id, created_at)`, `(action, created_at)`.
- `hrms_data_access_logs` — `actor_user_id`, `model`, `record_id`, `action` (`view|download|export`),
  `fields` JSON, `ip_address`, `created_at`.
- `hrms_settings` — single row: `week_start`, `timezone`, `country`, `region`, `currency`,
  `fiscal_year_start_month`, `leave_year_start_month`, `attendance` JSON, `remote_clock_in` JSON,
  `statutory` JSON, `mask_sensitive` bool, `data_retention_months`, timestamps.
- **Backfill inside the migration** (1.3): `updateOrInsert(['id' => 1], config('hrms.settings_defaults'))`.
- Tests: fresh tenant DB migrates; running twice is idempotent; the settings row exists with defaults;
  `tenants:provision` creates the tables for a pre-existing tenant.

**P1.9 — Models, enums, audit + approval services**
- `app/Models/Hrms/`: `Approval`, `ApprovalStep`, `HrmsAuditLog`, `HrmsDataAccessLog`, `HrmsSetting`.
- `app/Enums/`: `ApprovalStatus`, `ApprovalStepStatus`, `ApproverType`.
- `app/Services/HrmsAuditLogger.php` — `log(subjectType, subjectId, action, before, after, actor, ip)`
  and `accessed(model, recordId, action, fields, actor, ip)`. Mirrors the `ActivityLogger` API shape so
  call sites read the same way.
- `app/Services/Hrms/ApprovalService.php` — `request($approverSpec, Model $subject, $action,
  $subjectLabel, $meta)`, `approve`, `reject`, `cancel`, `pendingFor(User)`, `canAct(Approval, User)`.
  Handles step resolution, `department_head` via `departments.head_employee_id` (added in P3; falls
  back to the employee's `manager_id`), and the `skipped` state when a step has no candidates.
- Tests: happy path; reject; multi-step sequential; a step with no approver is auto-skipped and the
  flow continues; a non-approver gets 403; `cancel` by the requester; an audit row per transition.

**P1.10 — Provisioning hook `provisionHrmsDefaults()`**
- `TenantProvisioner`: add `private function provisionHrmsDefaults(): void` called from `seed()` right
  after `provisionProjectRoles()`. It `updateOrInsert`s the `hrms_settings` row (`id = 1`) from
  `config('hrms.settings_defaults')`, so the config row exists for every tenant even when the module is
  off (tables always exist; the gate is behavioural).
- Keep the seed step free of `assertQuota` calls and of request-scoped dependencies.
- Tests: acme and globex (seeded by `TenantSeeder` inside `IsolatesDatabase`) each have exactly one
  `hrms_settings` row; running twice does not duplicate it; a tenant created via `ProvisionTenantJob`
  has it.

**P1.11 — Frontend scaffolding**
- `/hrms` overview placeholder: a card grid reading `user.modules`, listing enabled modules with links
  (each 403s until its phase lands, so keep the entries module-gated).
- `Sidebar.jsx`: a new **People** section with the `HRMS` item gated on `hasModule('hrms.core')`.
- `App.jsx`: `/hrms` inside `<ProtectedRoute module="hrms.core">`.
- `utils/deepLinks.js`: add `hrmsUrl(section, params)`.
- Verify: a `starter` tenant sees no People section; a `pro` tenant sees it; a non-impersonating super
  admin is redirected per the existing rules.

**Acceptance:** `hrms` can be switched on/off per tenant and per plan; a non-entitled module 403s every
HRMS route; the three primitives are usable; a new tenant is provisioned with an `hrms_settings` row;
full suite green; AGENTS.md test count updated.

---

### Phase 2 — Core HR / employee profiles

**Objective:** the employee record — the anchor every other phase hangs off.

**P2.1 — `000015` migration**
`employment_types` (`name`, `slug` unique, `code`, `is_active`, `position`, `is_system`).
`employees` (`user_id` FK nullable unique, `employee_code` unique, `name`, `preferred_name`,
`personal_email`, `phone`, `date_of_birth`, `gender`, `marital_status`, `nationality`, `address_line1`,
`address_line2`, `city`, `state`, `postal_code`, `country`, `emergency_contact_name`,
`emergency_contact_phone`, `emergency_contact_relation`, `photo_path` nullable, `joining_date`,
`probation_end_date`, `confirmation_date`, `employment_type_id` FK, `designation` string (normalized
into `designations` in P3), `manager_id` self-FK nullable, `work_mode` enum(`office|hybrid|remote`),
`status` enum(`active|probation|on_notice|suspended|exited|terminated`), `exit_date` nullable,
`exited_reason` nullable, `notes`, `created_by`, timestamps, `softDeletes`).
`employee_status_history` (`employee_id`, `from_status`, `to_status`, `effective_date`, `reason`,
`actor_user_id`, `note`, `created_at`).
Indexes: `employees(status)`, `employees(joining_date)`, `employee_status_history(employee_id, created_at)`.
**No `tenant_id` column.** `department_id` / `designation_id` / `location_id` / `shift_id` arrive in
P3/P5 via `ALTER` in those migrations.

**P2.2 — Model + enums + service**
`app/Models/Hrms/Employee.php` (tenant DB — **not** `CentralConnection`), relations `user()`,
`employmentType()`, `manager()`, `reports()`, `statusHistory()`; scopes `active()`, `onNotice()`.
`app/Enums/EmployeeStatus`, `WorkMode`.
`app/Services/Hrms/EmployeeService.php` — `list(filters)`, `show($id)`, `create(array)`, `update`,
`changeStatus` (writes `employee_status_history` + audit), `assignManager`, `terminate`, `photoUrl`.
- `create()` accepts either `user_id` (an existing user) **or** inline user fields
  (`name`/`email`/`password`/`roles`). The inline path creates the `users` row **and** the
  `TenantUserRouting` row (0.7 bug 1) in one transaction, then the `employees` row. This is the single
  place HRMS user provisioning lives.
- `assertQuota('employees')` before create.
- Employee code `EMP-{sequence}` allocated atomically, mirroring `KeyGenerator::nextTaskKey()`'s
  transactional pattern with a unique-index retry.

**P2.3 — Policy + FormRequests + controller**
`app/Policies/EmployeePolicy.php` — `view` (self OR `hrms.employees.view`); `create`/`update`
(`hrms.employees.manage`); `changeStatus`/`terminate` (`hrms.employees.manage`); `viewSensitive`
(`hrms.documents.view_sensitive`, for national ids/bank in the profile); `delete`
(`hrms.employees.manage`, refused when `status = exited` unless forced).
`app/Http/Requests/Hrms/EmployeeStoreRequest.php`, `EmployeeUpdateRequest.php`.
`app/Http/Controllers/Api/Hrms/EmployeeController.php` — `index/show/store/update/destroy/changeStatus/
assignManager/terminate/photo`. Routes in the domain group behind `ensure_module:hrms.core`:
`GET|POST api/hrms/employees`, `GET|PUT|DELETE api/hrms/employees/{employee}`,
`POST api/hrms/employees/{employee}/status`, `.../manager`, `.../terminate`.
`index` supports `q`, `status`, `employment_type_id`, `department_id`, `manager_id`, `work_mode`,
`joined_from`, `joined_to`, `sort`, `dir`, `per_page` and returns
`{employees, pagination, filters, my_role}`.

**P2.4 — PII masking + audit**
`present($employee, User $viewer)` consults `viewSensitive` and redacts `personal_email`, `phone`,
`emergency_contact_*`, `date_of_birth` for non-privileged viewers. Every `update`/`changeStatus`/
`terminate` writes an `HrmsAuditLogger` row with before/after. Reading a profile with sensitive fields
visible writes an `hrms_data_access_logs` row.

**P2.5 — Frontend directory page**
`resources/js/pages/hrms/Employees.jsx` — filter bar (search, status, employment type, department,
manager, joined range), sortable paginated table, avatar, status pill, and a "New employee" modal
(`EmployeeFormModal.jsx`: inline-user toggle, tenant role picker from `GET api/roles`, employment type,
joining date, manager picker). Follow existing UX rules: write endpoints return `{message}` only, so
refetch after save; `fieldErrors()` for form errors; `useToast()` un-destructured.

**P2.6 — Frontend profile page + deep link**
`resources/js/pages/hrms/EmployeeDetail.jsx` at `/hrms/employees/:employeeId?tab=...` with tabs
`overview|documents|attendance|leave|payroll|performance|assets` — only tabs whose modules are enabled
render. `utils/deepLinks.js` gains `employeeUrl(id, tab)`. 403 -> `navigate('/403')`.

**P2.7 — Seed `employment_types` + backfill existing users as employees**
- Add `employment_types` to `provisionHrmsDefaults()`.
- Add `php artisan hrms:backfill-employees --tenant=ID|--all` (Console command): for every user without
  an `employees` row, create an `active` employee with code `EMP-{id}` (the **default** user first).
  Idempotent, `--dry-run` supported, prints a summary table. This is what makes the module usable on
  the 100 seeded dev tenants without hand-creating 1,000 records.
- Tests: the command creates rows, a second run is a no-op, `--dry-run` writes nothing.

**Acceptance:** create an employee with an inline login in the UI and they can log in immediately; the
directory filters and paginates; a non-privileged viewer sees masked PII; a `viewer` role cannot write;
a Globex admin cannot fetch an Acme employee (404); audit rows exist for the mutations.

---

### Phase 3 — Organization structure

**Objective:** departments, designations, locations as first-class, with the tree editor HR managers
expect.

**P3.1 — `000016` migration**
`departments` (`name`, `slug` unique, `code`, `parent_id` self-FK nullable, `head_employee_id` nullable,
`description`, `is_active`, `position`), `designations` (`name`, `slug` unique, `code`, `level`,
`department_id` nullable, `is_active`, `position`), `locations` (`name`, `slug` unique, `address_line1`,
`city`, `state`, `postal_code`, `country`, `timezone`, `geo_lat` decimal(10,7), `geo_lng` decimal(10,7),
`geo_radius_m` int nullable, `is_geo_fenced` bool, `is_active`, `position`).
- `ALTER employees`: add `department_id`, `designation_id`, `location_id` (nullable FKs) + indexes.
- Backfill: set `department_id`/`designation_id` from the existing free-text `designation` where a
  matching slug exists, guarded with `whereNull` so it is re-runnable.
- PG: `geo_lat`/`geo_lng` as `decimal(10,7)`, never float — float round-trips badly.

**P3.2 — Models + service + tree logic**
`Department` (`parent()`, `children()`, `head()`, `descendants()`), `Designation`, `Location`
(`employees()`, `isGeoFenced()`).
`app/Services/Hrms/OrgService.php` — department tree read (`buildTree()` with depth and child counts),
create/update with slug auto-suffix on collision (mirror `WorkspaceService`), **cycle prevention** on
`parent_id` (BFS, like the task-dependency check in `TaskService`), `position` renumbering 1..N,
designations CRUD, locations CRUD.
- Setting `head_employee_id` requires an `active` employee.
- No hard delete: departments/descriptions/locations get `is_active = false`; deleting a department with
  children or employees returns 422 `form` suggesting reassignment or deactivation.

**P3.3 — Policies, requests, controller, routes**
`DepartmentPolicy` / `DesignationPolicy` / `LocationPolicy` (view `hrms.org.view`; manage
`hrms.org.manage`). `app/Http/Requests/Hrms/…` for each. `OrgController` with `GET|POST
api/hrms/departments`, `PUT|DELETE .../departments/{department}`, `POST .../departments/reorder`, the
same for `designations` and `locations`, plus `GET api/hrms/org` returning the whole tree with headcounts
in one request (one round trip for the org page).

**P3.4 — Frontend org page**
`resources/js/pages/hrms/Org.jsx` — a **tree + detail split view**: a collapsible department tree with
headcount badges on the left, the selected department's designations and members on the right, forms in
modals. The employee picker reuses `WorkspaceDetail`'s add-member pattern (gated by `users.view`).

**P3.5 — Seed starter org data**
Add `departments`/`designations`/`locations` to `config/hrms.php` and to `provisionHrmsDefaults()`
(generic starters only — do not invent a company structure for seeded tenants beyond
"Engineering / Sales / Operations" and "Headquarters / Remote").

**Acceptance:** the org page loads in one request; a cycle attempt returns 422; an employee with a
department shows it in the directory filter; `hrms.org.manage` is required for every write.

---

### Phase 4 — Onboarding & offboarding

**Objective:** a checklist-driven new-hire and exit workflow, with `document_requests` as its hinge.

> **Prerequisite:** Phase 13 (documents) should ship first, otherwise the document checklist items are
> plain checklist rows with a null `document_id` until then.

**P4.1 — `000017` migration**
`onboarding_templates` (`name`, `slug` unique, `description`, `is_active`, `is_system`),
`onboarding_template_tasks` (`template_id`, `title`, `description`, `category` enum
`document|task|asset|access|orientation|other`, `owner_scope` enum `hr|manager|employee|it`,
`due_offset_days`, `is_mandatory`, `position`), `onboarding_cases` (`employee_id` unique,
`template_id`, `status` enum `not_started|in_progress|completed|cancelled`, `started_at`, `completed_at`,
`created_by`, timestamps, `softDeletes`), `onboarding_case_tasks` (`case_id`, `template_task_id` nullable,
`title`, `description`, `category`, `owner_scope`, `owner_employee_id` nullable, `due_date`, `status`
enum `pending|in_progress|done|skipped|waived`, `completed_at`, `completed_by`, `note`).
`offboarding_cases` (`employee_id`, `last_working_day`, `reason` enum
`resigned|terminated|retired|contract_end|other`, `status` enum `initiated|in_progress|completed|cancelled`,
`notice_period_days`, `exit_interview_at` nullable, `exit_interview_notes` nullable,
`resignation_received_at` nullable, `created_by`, timestamps, `softDeletes`).
`offboarding_case_tasks` (the onboarding shape plus `asset_id` / `expense_claim_id` nullable linkage).
`exit_clearances` (`case_id` unique, `pending_assets_count`, `pending_leave_encashment_days`,
`pending_expense_amount`, `pending_documents_count`, `dues_settled` bool, `cleared_by_user_id`,
`cleared_at` nullable, `blocked_reasons` JSON).
`document_requests` (`employee_id`, `document_type_id` nullable, `title`, `due_date`, `status` enum
`pending|submitted|accepted|waived|rejected`, `source` enum `onboarding|offboarding|hr`, `case_type` +
`case_id` nullable pair, `note`).
Indexes on every filtered `*_id`, plus `(case_id, status)` and `(employee_id, due_date)`.

**P4.2 — Services**
`app/Services/Hrms/OnboardingService.php` — `templates()`, `createCase(Employee, Template)`
(materialises `onboarding_case_tasks` from the template with `due_date = joining_date + due_offset_days`),
`completeTask`, `waiveTask` (mandatory items need a reason + `hrms.onboarding.manage`), `progress(Case)`,
`complete(Case)`.
`app/Services/Hrms/OffboardingService.php` — `initiate(Employee, lastWorkingDay, reason, noticeDays)`,
`buildChecklist` (auto-adds: return assets, clear documents, settle leave encashment, settle expense
claims, revoke system access), `summary(Case)` (the clearance counters), `clear(Case)` — **refuses to
clear while any counter is non-zero** (422 `form` listing the blockers), `complete`.
- Changing an employee to `exited` (P2) suggests/initiates an offboarding case; `employees.exit_date`
  comes from `last_working_day`.

**P4.3 — Policies, requests, controller, routes**
`OnboardingPolicy` (view `hrms.onboarding.view` or case owner; manage `hrms.onboarding.manage`),
`OffboardingPolicy` (same shape; `clear` = `hrms.offboarding.manage`).
Routes: `GET|POST api/hrms/onboarding/templates`, `PUT|DELETE .../templates/{template}`,
`GET|POST api/hrms/onboarding/cases`, `GET .../cases/{case}`,
`POST .../cases/{case}/tasks/{task}/complete`, `.../waive`, `POST .../cases/{case}/complete`, and the
mirrored `/api/hrms/offboarding/*` set plus `POST .../offboarding/cases/{case}/clear`.
- **Nested-param rule:** every method under `cases/{case}/tasks/{task}` declares **both**
  `OnboardingCase $case` and `OnboardingCaseTask $task` and verifies `$task->case_id === $case->id`,
  else 404 (0.6).

**P4.4 — Frontend**
`pages/hrms/OnboardingCases.jsx` (list + filters + progress bars),
`pages/hrms/OnboardingCaseDetail.jsx` (checklist grouped by `owner_scope` with due dates, overdue
highlighting, waive-with-reason modal), `components/hrms/Checklist.jsx` (shared by onboarding and
offboarding), `pages/hrms/OffboardingCases.jsx`, `OffboardingCaseDetail.jsx` (clearance blockers panel
— this is the screen an HR manager signs off an exit on, so make the blockers unmissable),
`TemplateEditor.jsx` (add/reorder/remove items, mirroring `PageFormModal`'s block editor).

**P4.5 — Notifications + deep links**
`NotificationService` gains `onboardingTaskDue(Employee, CaseTask)` -> `hrms.onboarding.task_due` to
the task's owner (and the HR manager when `owner_scope = hr`), dispatched on case creation and by a
daily `php artisan hrms:onboarding-reminders`. `utils/notifications.js` gains the
`describeNotification` copy and a `notificationHref` branch -> `hrmsUrl('onboarding', {case})`.

**Acceptance:** create a template, start a case for a new hire, the checklist materialises with correct
due dates, completing all mandatory items closes the case, an offboarding case refuses to clear with
outstanding assets, a waived item records a reason and the actor.

---

### Phase 5 — Attendance management

**Objective:** shifts, clock-in/out, derived daily records, and regularization.

**P5.1 — `000018` migration**
`attendance_shifts` (`name`, `code` unique, `start_time`, `end_time`, `break_minutes`, `grace_minutes`,
`min_hours` decimal(5,2), `is_night` bool, `is_active`, `position`), `attendance_rosters`
(`employee_id`, `shift_id`, `effective_from`, `effective_to` nullable, `weekly_offs` JSON int[7],
`is_flexible` bool) unique `(employee_id, effective_from)`, `attendance_punches` (`employee_id`,
`punch_at`, `direction` enum `in|out`, `source` enum `web|mobile|kiosk|import|auto|regularized`,
`lat` decimal(10,7) nullable, `lng` nullable, `ip` nullable, `user_agent` nullable, `device_id`
nullable, `location_id` nullable, `is_out_of_range` bool, `out_of_range_reason` nullable, `note`
nullable, `created_by` nullable) with indexes `(employee_id, punch_at)` and `(punch_at)`,
`attendance_days` (`employee_id`, `work_date`, `shift_id` nullable, `roster_id` nullable, `first_in_at`,
`last_out_at`, `worked_minutes`, `break_minutes`, `late_by_minutes`, `early_by_minutes`,
`overtime_minutes`, `status` enum `present|absent|half_day|late|leave|holiday|week_off|remote|inactive`,
`is_regularized` bool, `regularized_by_user_id` nullable, `note` nullable, timestamps) **unique
`(employee_id, work_date)`**, `attendance_ip_rules` (`cidr` unique, `label`, `is_active`),
`attendance_regularization_requests` (`attendance_day_id`, `employee_id`, `work_date`,
`requested_punch_at` nullable, `requested_first_in_at` nullable, `reason`, `status` enum
`pending|approved|rejected`, `approval_id` nullable, `decided_at`, `decided_by_user_id`,
`decision_note`).
- `ALTER employees`: add a nullable `shift_id` FK as a convenience default only; the roster is
  authoritative.

**P5.2 — Punch service + day computation**
`app/Services/Hrms/AttendanceService.php`:
- `punch(Employee, direction, source, meta)` — validates the shift window and duplicate punches, checks
  the IP against `attendance_ip_rules` and lat/lng against the employee's geo-fenced `location`
  (setting `is_out_of_range` + a reason, never a hard block), inserts the punch, then recomputes the day.
- `computeDay(Employee, Carbon $date)` — resolve the shift from the active roster, find-or-create the
  `attendance_days` row, pair punches in/out, subtract the break, compute `late_by` / `early_by` /
  `overtime` from the rounding rules in `hrms_settings` (`{rounding_minutes, ot_after_minutes,
  half_day_minutes, full_day_minutes, allow_negative_ot}`), set `status`.
- `dayStatus(Employee, $date)` — merges attendance with **approved leave** dates, **holidays**, and
  **weekly offs**, so an approved leave day is `leave`, never `absent`. This join is why payroll is
  credible; it is also why an approved leave must be able to update an existing `attendance_days` row.
- `regenerate(Employee, $date)` — idempotent recompute, used after a roster change or a regularization.
- `summary(Employee, $from, $to)` — present/late/absent/leave/holiday/week-off/OT totals.

**P5.3 — Remote clock-in policy (`ensure_module:hrms.attendance.remote`)**
- `PUT api/hrms/attendance/settings` (tenant admin) writes the `attendance` + `remote_clock_in` JSON in
  `hrms_settings`: `{allow_remote, require_ip, require_geo, max_punches_per_day, allow_multiple_sessions}`.
- `POST api/hrms/attendance/punch` is gated by `ensure_module:hrms.attendance.remote`; the UI hides the
  clock widget when the module is absent and the API 403s.
- The 403 here is a **module** 403, not a permission 403 — the client shows the upgrade path (`/403`).

**P5.4 — Regularization (shared approval primitive)**
- `POST api/hrms/attendance/regularizations` — an employee requests a correction for a past date (<= N
  days back, from `hrms_settings`); creates the row plus an `approvals` row whose subject is the request.
- Manager approval patches the punch/day, sets `is_regularized`, records the approver, writes an
  `HrmsAuditLogger` row, and fires activity/notifications.

**P5.5 — Scheduled day roll-up**
`php artisan hrms:attendance-rollup --date=YYYY-MM-DD [--tenant=ID] [--all]` — for each active employee
ensure an `attendance_days` row exists for the date (`absent` if no punches and no leave/holiday).
Idempotent and safe to re-run. The UI "backfill" button dispatches
`app/Jobs/HrmsAttendanceRollupJob.php`. **Remember:** queued jobs only carry a tenant id when
`TenantContext::currentId()` is set at dispatch time, so this job must either be dispatched from inside
the tenant context or wrap its own `TenantDatabaseManager::using()`.

**P5.6 — Frontend**
`pages/hrms/Attendance.jsx` — month calendar grid (per day: status pill, first-in/last-out, worked hours,
late/OT badges), a right-hand punch panel, filters (team/department/shift/status), CSV export
(`hrms.attendance.view` + an `accessed('AttendanceDay', ..., 'export')` audit row).
`pages/hrms/AttendanceApprovals.jsx` — the regularization queue.
`components/hrms/ClockInWidget.jsx` — punch button, today's punches, out-of-range banner.
`components/hrms/AttendanceCalendar.jsx` — reusable month grid, reused by employee self-service.

**Acceptance:** punch in/out produces a day record with correct late/OT math; a leave day shows `leave`
not `absent`; a regularization changes the day and is audited; a tenant without
`hrms.attendance.remote` gets 403 and no clock widget; the rollup command is idempotent.

---

### Phase 6 — Leave management

**Objective:** leave types, policies, an accrual **ledger**, balances, requests, multi-step approval, and
per-day rows that attendance and payroll both read.

**P6.1 — `000019` migration**
`leave_types` (`name`, `slug` unique, `code`, `is_paid`, `accrual_method` enum
`none|annual|monthly|quarterly|per_payroll`, `accrual_rate` decimal(6,3), `max_balance`,
`carry_forward` bool, `carry_forward_cap` decimal(6,2), `encashable` bool, `requires_document_after_days`
nullable, `min_days_per_request` decimal(5,2), `max_days_per_year` decimal(5,2), `allow_half_day` bool,
`allow_negative_balance` bool, `color`, `position`, `is_system` bool, `is_active`),
`leave_policies` (`name`, `slug` unique, `accrual_period` enum `monthly|quarterly|biannual|annual`,
`start_month` tinyint, `carry_forward_date` tinyint, `max_carry_forward` decimal(6,2),
`negative_balance_allowed` bool, `max_negative_days` decimal(5,2), `description`, `is_default` bool,
`is_active`), `leave_policy_types` (`policy_id`, `leave_type_id`) composite PK, `leave_balances`
(`employee_id`, `leave_type_id`, `year` smallint, `opening`, `accrued`, `availed`, `encashed`, `lapsed`,
`carried_forward`, `adjusted`, `balance` — all decimal(6,2), `updated_at`) **unique
`(employee_id, leave_type_id, year)`**, `leave_adjustments` (`employee_id`, `leave_type_id`, `year`,
`kind` enum `opening|accrual|carry_forward|encashment|lapse|adjustment|availed`, `quantity`
decimal(6,2), `reference_type` nullable, `reference_id` nullable, `note`, `actor_user_id`,
`created_at`) — append-only ledger, index `(employee_id, leave_type_id, year)`, `leave_requests`
(`employee_id`, `leave_type_id`, `from_date`, `to_date`, `from_half` enum `full|first_half|second_half`,
`to_half`, `total_days` decimal(5,2), `reason`, `contact_during_leave` nullable, `document_id` nullable,
`status` enum `draft|submitted|pending|approved|rejected|cancelled`, `approval_id` nullable,
`decided_at`, `decided_by_user_id`, `cancel_reason`, `created_by`, timestamps, `softDeletes`),
`leave_request_days` (`leave_request_id`, `date`, `is_holiday` bool, `is_week_off` bool, `is_half_day`
bool) **unique `(leave_request_id, date)`**, `leave_exemption_requests` (`employee_id`, `leave_type_id`,
`from_date`, `to_date`, `days` decimal(5,2), `reason`, `status` enum `pending|approved|rejected|expired`,
`approval_id` nullable, `fiscal_year`, `decided_at`, `decided_by_user_id`).
- **The ledger is the source of truth;** `leave_balances` is a materialised projection rebuilt from it by
  `rebuildBalance(Employee, LeaveType, year)`. That makes "why is my balance 4.5?" answerable and makes
  accrual reruns safe.

**P6.2 — Balance engine**
`app/Services/Hrms/LeaveService.php`:
- `accrue(Employee, LeaveType, year, Carbon $asOf)` — writes ledger rows per the type's accrual method
  and policy period; idempotent per (employee, type, year, period) so reruns never double-credit.
- `rebuildBalance(Employee, LeaveType, year)` — sums the ledger into `leave_balances`.
- `availableDays(Employee, LeaveType, $from, $to)` — balance minus overlapping approved requests, capped
  at `max_balance`, honouring `allow_negative_balance`.
- `createRequest(Employee, data)` — validates min/max days, overlapping requests and balance (422
  `form` with the shortfall), splits into `leave_request_days` (week-offs/holidays excluded from
  `total_days`), and writes the `availed` ledger row **on approval**, not on submission.
- `approve/reject/cancel` via `ApprovalService`; on approval the ledger row is written, the balance is
  rebuilt, and `AttendanceService::regenerate()` runs for every affected date.
- `cancelRequest` releases the ledger row and regenerates attendance.
- `encash(Request)` — only for `encashable` types; writes an `encashed` ledger row and leaves a payable
  reference that P9's payroll picks up (store the reference, do not couple the services).

**P6.3 — Approval routing**
`ApprovalService::request(['steps' => [['approver_type' => 'manager'], ['approver_type' => 'role',
'approver_role_id' => $hrRoleId]]], $leaveRequest, 'leave.request', 'Leave request')` — manager first,
then HR. `department_head` resolves via `departments.head_employee_id` with a fallback to the employee's
manager. A step with no resolvable approver is `skipped` and the flow continues (explicitly tested in
P1.9).

**P6.4 — Policies, requests, controller, routes**
`LeaveTypePolicy` / `LeavePolicyPolicy` (view `hrms.leave.view`; manage `hrms.leave.manage`),
`LeaveRequestPolicy` (view self OR `hrms.leave.view`; create self OR `hrms.leave.manage`; approve
`hrms.leave.approve`; cancel self while `pending` OR `hrms.leave.manage`), `LeaveExemptionPolicy`.
`app/Http/Requests/Hrms/LeaveTypeRequest`, `LeavePolicyRequest`, `LeaveRequestRequest`,
`ExemptionRequest`.
Routes: `GET|POST api/hrms/leave/types`, `PUT|DELETE .../types/{leaveType}`, `GET|POST
api/hrms/leave/policies`, `PUT|DELETE .../policies/{leavePolicy}`, `GET api/hrms/leave/balances`
(+ `?employee_id=` for HR), `POST api/hrms/leave/accrue`, `GET|POST api/hrms/leave/requests`,
`GET|PUT|DELETE .../requests/{leaveRequest}`, `POST .../requests/{leaveRequest}/cancel`,
`GET|POST api/hrms/leave/exemptions`, `POST .../exemptions/{exemption}/decide`.
`POST api/hrms/leave/accrue` requires `hrms.leave.manage` and writes an audit row (bulk mutation).

**P6.5 — Frontend**
`pages/hrms/Leave.jsx` (tabs: requests | types | policies | balances | exemptions),
`components/hrms/LeaveRequestModal.jsx` (live available-days and a shortfall warning; a small month
calendar showing team absences), `components/hrms/LeaveBalanceTable.jsx` (accrued/availed/remaining per
type per year), `pages/hrms/MyLeave.jsx` (self-service: balances, request, cancel, history). Clicking a
calendar day opens a pre-filled request.

**P6.6 — Notifications + deep links**
`NotificationService::leaveRequested(LeaveRequest)` -> `hrms.leave.requested` to the approver (skips
self, mirroring `taskCommented`); `leaveDecided(LeaveRequest)` -> `hrms.leave.approved|rejected` to the
requester with the from/to status names. `utils/notifications.js` branches both types ->
`hrmsUrl('leave', {request})`.

**Acceptance:** accrue -> the balance reflects it; a request exceeding balance 422s with the shortfall;
approval writes the ledger and flips the attendance day to `leave`; rejection notifies the requester and
rebuilds the balance; an accrual rerun does not double-credit; a `viewer` role can request their own
leave but cannot manage types.

---

### Phase 7 — Comp-off

**Objective:** credit comp-off from weekends/holidays/special days, and redeem it.

**P7.1 — `000020` migration**
`comp_off_credits` (`employee_id`, `work_date`, `source_type` enum `weekend|holiday|special|manual`,
`minutes` int, `expiry_date` nullable, `note`, `actor_user_id` nullable, `created_by`, `created_at`)
**unique `(employee_id, work_date, source_type)`** so accrual reruns are idempotent,
`comp_off_requests` (`employee_id`, `from_date`, `to_date`, `total_minutes` int, `reason`, `status` enum
`submitted|pending|approved|rejected|cancelled`, `approval_id` nullable, `decided_at`,
`decided_by_user_id`, `document_id` nullable, timestamps, `softDeletes`), `comp_off_request_days`
(`comp_off_request_id`, `date`, `minutes`) unique `(comp_off_request_id, date)`.
Balance is **derived**: `SUM(credits) - SUM(approved request minutes)` where `expiry_date IS NULL OR
expiry_date >= today`. No balance table — the volumes are small and a materialised column would be a
staleness bug factory.

**P7.2 — Service**
`app/Services/Hrms/CompOffService.php` — `creditFromCalendar(Employee, $from, $to)` (walks dates, skips
days with approved leave or attendance `present`, credits weekly offs + holidays + special days per
`hrms_settings.comp_off.{from_weekends, from_holidays, validity_months}`), `creditManual`,
`balance(Employee, $asOf)`, `expiredBalance(Employee, $asOf)`, `createRequest` (splits days, validates
availability, 422 on shortfall), `approve/reject/cancel`.
`php artisan hrms:comp-off-accrue [--all] [--tenant=ID]` runs the calendar accrual monthly.

**P7.3 — Policies / requests / routes** — `CompOffPolicy` (view self or `hrms.comp_off.view`; approve
`hrms.comp_off.approve`; manage `hrms.comp_off.manage` = configure the accrual policy in
`hrms_settings`). Routes mirror Phase 6 under `/api/hrms/comp-off/{credits,requests,accrue,settings}`.

**P7.4 — Frontend** — `pages/hrms/CompOff.jsx` (credits table, requests queue, balance and
expiring-soon panel), `pages/hrms/MyCompOff.jsx`.

**P7.5 — Notifications** — `hrms.comp_off.requested|approved|rejected`, deep-linked via `hrmsUrl`.

**Acceptance:** a month of weekends credits correctly and re-running is a no-op; a request over balance
422s; expiry removes credits from the available balance; approval notifies the requester.

---

### Phase 8 — Holiday management

**Objective:** holiday calendars (optionally per region), assignment to employees, optional/restricted
holidays, and the single source of non-working days that attendance, leave, and comp-off all read.

**P8.1 — `000021` migration**
`holiday_calendars` (`name`, `slug` unique, `country` char(2), `region` nullable, `description`,
`is_default` bool, `is_active`, `position`), `holidays` (`calendar_id`, `name`, `date`, `type` enum
`public|restricted|optional`, `is_recurring` bool, `description` nullable) indexed `(calendar_id, date)`
(for `is_recurring` rows, `date` holds the current year's occurrence and the **month/day** is what
recurs), `employee_holiday_calendars` (`employee_id`, `calendar_id`, `effective_from`, `effective_to`
nullable) unique `(employee_id, calendar_id, effective_from)`, `holiday_optional_holidays` (`employee_id`,
`holiday_id`, `status` enum `taken|skipped`, `taken_date` nullable, `note`) unique `(employee_id,
holiday_id)`.

**P8.2 — Service**
`app/Services/Hrms/HolidayService.php` — `calendar(Employee, $year)` (assigned calendars plus the tenant
default, with recurring expansion for the year), `isHoliday(Employee, $date)`,
`isWorkingDay(Employee, $date)` (a date is non-working if a holiday or a weekly off),
`assign/unassign`, `declareOptional`, `seedFromConfig($year)` (expands `config('hrms.holidays')` per
country/region into concrete rows; idempotent on `(calendar_id, name, date)`).
`POST api/hrms/holidays/seed-year {year}` requires `hrms.holidays.manage`.

**P8.3 — Integration**
`AttendanceService::dayStatus()` and `LeaveService::createRequest()` call
`HolidayService::isWorkingDay()`; `CompOffService::creditFromCalendar()` reads the same calendars.
This service is the **only** source of truth for non-working days.

**P8.4 — Policies, requests, routes, frontend**
`HolidayCalendarPolicy` (view `hrms.holidays.view`; manage `hrms.holidays.manage`).
Routes: `GET|POST api/hrms/holidays/calendars`, `PUT|DELETE .../calendars/{calendar}`,
`GET|POST .../calendars/{calendar}/holidays`, `PUT|DELETE .../holidays/{holiday}`,
`GET|POST api/hrms/holidays/assignments`, `DELETE .../assignments/{assignment}`,
`GET api/hrms/holidays/calendar?year=&employee_id=` (the resolved per-employee view),
`GET|POST api/hrms/holidays/optional`.
Frontend: `pages/hrms/Holidays.jsx` (calendar list plus a year grid with month tabs, bulk add
common-holiday presets, optional-holiday declaration panel).

**Acceptance:** a public holiday turns an attendance day into `holiday` and reduces a leave request's
`total_days`; a restricted holiday is a working day; an optional holiday is honoured when taken;
`seed-year` twice does not duplicate.

---

### Phase 9 — Payroll (core)

> **Scope boundary:** salary structures, revision letters, payroll runs, payslip generation with
> **manual** attendance/leave/LOP inputs. **No statutory computation here** — that is Phase 10.

**P9.1 — `000022` migration**
`salary_components` (`name`, `slug` unique, `code`, `type` enum
`earning|deduction|employer_contribution|reimbursement`, `calculation_type` enum
`fixed|percentage_of_ctc|percentage_of_basic|formula`, `default_value` decimal(14,2), `is_taxable` bool,
`is_prorated` bool, `is_statutory` bool, `is_system` bool, `is_active`, `sequence`),
`salary_structures` (`name`, `slug` unique, `currency` char(3), `effective_from`, `description`,
`is_default` bool, `is_active`, `created_by`, timestamps), `salary_structure_components`
(`structure_id`, `component_id`, `value` decimal(14,4), `sequence`, `is_override` bool) unique
`(structure_id, component_id)`, `employee_salary_structures` (`employee_id`, `structure_id`,
`ctc_annual` decimal(14,2), `monthly_ctc` decimal(14,2), `gross_monthly` decimal(14,2),
`effective_from`, `effective_to` nullable, `reason`, `is_current` bool, `approved_by_user_id` nullable)
unique `(employee_id, effective_from)`, `salary_revisions` (`employee_id`, `from_ctc` decimal(14,2),
`to_ctc` decimal(14,2), `change_percent` decimal(6,2), `effective_from`, `reason`, `status` enum
`draft|approved|rejected|applied`, `approval_id` nullable, `approved_by_user_id`, `approved_at`,
`letter_document_id` nullable), `payslip_templates` (`name`, `is_default` bool, `content` JSON,
`is_active`), `payroll_runs` (`period_year` smallint, `period_month` tinyint, `pay_period_start` date,
`pay_period_end` date, `pay_date` date, `status` enum
`draft|calculating|review|approved|processing|paid|void|locked`, `employee_count` int, `totals` JSON,
`initiated_by_user_id`, `approved_by_user_id` nullable, `approved_at` nullable, `processed_at` nullable,
`locked_at` nullable, `notes` nullable, timestamps) **unique `(period_year, period_month)`**,
`payslips` (`payroll_run_id`, `employee_id`, `employee_salary_structure_id`, `earnings` JSON,
`deductions` JSON, `employer_contributions` JSON, `statutory` JSON nullable, `gross_pay` decimal(14,2),
`total_deductions` decimal(14,2), `net_pay` decimal(14,2), `working_days` decimal(5,2), `paid_days`
decimal(5,2), `lop_days` decimal(5,2), `ot_minutes` int, `absent_days` int, `leave_days` JSON, `status`
enum `draft|published|disputed|paid`, `published_at` nullable, `locked_at` nullable) unique
`(payroll_run_id, employee_id)`, `payslip_adjustments` (`payslip_id`, `component_id` nullable, `kind`
enum `earning|deduction`, `label`, `amount` decimal(14,2), `source_type` nullable, `source_id` nullable,
`note`, `actor_user_id`).

**P9.2 — Compensation service**
`app/Services/Hrms/CompensationService.php` — `structures()`, `createStructure`,
`assign(Employee, Structure, ctc, effectiveFrom, reason)` (materialises `employee_salary_structures`,
flips `is_current` on the previous row, audits), `revise(Employee, toCtc, effectiveFrom, reason)`
(creates a `salary_revisions` row plus an approval when the change crosses the threshold in
`hrms_settings`), `apply(Revision)` (closes the prior structure, opens the new one, materialises the
letter as a document via P13).
`ctcToComponents(Structure, ctc)` resolves `percentage_of_ctc` components; `gross_monthly =
ctc_annual / 12` minus employer contributions. All arithmetic in `decimal(14,2)` with string/bcmath
semantics — **never floats**.

**P9.3 — Payroll engine**
`app/Services/Hrms/PayrollService.php`:
- `openRun(year, month, payPeriodStart, payPeriodEnd, payDate)` creates a `draft` run.
- `calculate(Run)` sets `status = calculating`, then per employee builds the payslip:
  1. resolve the active `employee_salary_structures` row where `effective_from <= pay_date`;
  2. `working_days` from the calendar month (weekends and holidays via `HolidayService`);
  3. `paid_days = working_days - absent_days - lop_days`, where `lop_days` comes from `attendance_days`
     (absent + half-day 0.5) **minus** approved leave days (`leave_request_days` with
     `is_holiday = false`); unpaid leave types reduce `paid_days` fully, paid leave types not at all;
  4. `ot_minutes` from `attendance_days.overtime_minutes`, converted by `hrms_settings.payroll.ot_rate`;
  5. prorate `is_prorated` earnings by `paid_days / working_days`;
  6. apply `payslip_adjustments` (expense reimbursements from P11 via `source_type`, comp-off encashment
     from P6);
  7. write `earnings` / `deductions` / `employer_contributions` JSON **snapshots** — never recomputed on
     read;
  8. `status = review`.
- `approve(Run)` / `publish(Run)` / `markPaid(Run)` — a state machine where `locked` is terminal. Every
  transition writes an `hrms_audit_logs` row; `publish` fires `hrms.payroll.published` notifications.
- `recalculateEmployee(Run, Employee)` only while the run is `review`. `calculate()` on a
  `draft`/`review` run wipes and rebuilds that run's payslips — never a `locked` run.

**P9.4 — Sensitive access + payslip documents**
- `GET api/hrms/payroll/runs/{run}/payslips` requires `hrms.payroll.run`;
  `GET api/hrms/payroll/payslips/{payslip}` requires `hrms.payroll.view_all`, or self plus
  `hrms.payroll.view`. Both write an `hrms_data_access_logs` `view` row.
- Payslip rendering uses `payslip_templates`; the download is a **signed tenant-scoped route** (D2.13)
  and writes an `accessed(..., 'download')` row.
- `hrms.compensation.manage` and `hrms.payroll.manage` are the only permissions that may write
  compensation/payroll master data, and both are audited on every mutation.

**P9.5 — Policies, requests, routes**
`SalaryComponentPolicy`, `SalaryStructurePolicy`, `PayrollRunPolicy` (view/run/lock all
`hrms.payroll.run`), `PayslipPolicy`.
`app/Http/Requests/Hrms/{SalaryComponentRequest, SalaryStructureRequest, SalaryAssignmentRequest,
PayrollRunRequest, PayslipAdjustmentRequest}`.
Routes under `/api/hrms/payroll`: `components`, `structures`, `structures/{id}/components`,
`employees/{employee}/salary`, `employees/{employee}/revisions`, `runs`,
`runs/{run}/calculate|approve|publish|mark-paid|lock`, `runs/{run}/payslips`, `payslips/{payslip}`,
`payslips/{payslip}/adjustments`, `my-payslips`, and the signed `payslips/{payslip}/download` (outside
the auth groups, `signed`).

**P9.6 — Frontend**
`pages/hrms/Compensation.jsx` (components, structures, and a structure builder with a live monthly
preview), `pages/hrms/Payroll.jsx` (runs table plus a run wizard: period -> calculate -> review grid with
per-employee drill-down -> approve -> publish), `pages/hrms/PayrollRunDetail.jsx` (grid with LOP/leave/OT
columns, adjustment drawer, totals footer), `pages/hrms/MyPayslips.jsx` (list, payslip view, download).

**Acceptance:** a run calculates correct LOP from attendance plus leave; re-calculating a `review` run
is deterministic; a `locked` run rejects edits; an approver without `hrms.payroll.run` gets 403; a
non-privileged user cannot read another's payslip; every payslip read is logged.

---

### Phase 10 — Payroll — statutory compliance **(HIGH RISK)**

> Explicitly deferred out of Phase 9 and implemented here. Rules are **jurisdiction-configured**,
> never hard-coded per country in the service layer. Phase 9's payslips must keep working with this
> module disabled.

**P10.1 — `000023` migration**
`statutory_configurations` (`country` char(2), `region` nullable, `name`, `code` unique, `is_active`,
`config` JSON — e.g. `{pf: {enabled, employee_wage_ceiling, employer_rate, employee_rate}, esi: {...},
pt: {slabs: [{up_to, amount}]}, lwf: {enabled, months}}`), `statutory_profiles` (`employee_id` **unique**,
`pan` nullable, `aadhaar_last4` nullable, `uan` nullable, `esi_number` nullable, `pf_number` nullable,
`pt_state` nullable, `lwf_registration` bool, `bank_name` nullable, `bank_account_encrypted` text
nullable, `bank_ifsc` nullable, `tax_declaration` JSON nullable, `declarations` JSON nullable,
`verified_at` nullable, `verified_by_user_id` nullable, timestamps) with an **encrypted cast** on
`bank_account_encrypted`, `statutory_declarations` (`employee_id`, `fiscal_year`, `section`,
`declared_amount` decimal(14,2), `proof_document_id` nullable, `status` enum
`draft|submitted|verified|rejected`, `submitted_at`, `verified_at`, `verified_by_user_id`),
`tds_projects` (`employee_id`, `fiscal_year`, `quarter` tinyint, `declared_income` decimal(14,2),
`exempt_income` decimal(14,2), `projected_income` decimal(14,2), `tax_liability` decimal(14,2),
`tds_deducted` decimal(14,2), `tds_surrendered` decimal(14,2), `challan_ref` nullable) unique
`(employee_id, fiscal_year, quarter)`.
- Avoid partial indexes on booleans here unless the predicate is `true` (0.6).

**P10.2 — Jurisdiction engine (pure, table-driven)**
`app/Services/Hrms/Statutory/StatutoryEngine.php` — pure functions over a `StatutoryConfiguration`
array. Methods: `pf(Employee, Payslip)`, `esi(Employee, Payslip)`, `professionalTax(Employee, Payslip)`,
`lwf(Employee, Payslip)`, `tds(Employee, Payslip, Declarations)`. Each returns
`[['component' => slug, 'amount' => ..., 'meta' => ...]]` and **writes nothing**.
- Every amount is `decimal(14,2)`; every threshold comes from the config row, never a service constant.
- Unit-test each function with a fixture table (below / equal-to / above every threshold, plus negative
  cases). This is the highest-risk code in the project and earns the densest tests in the repo.

**P10.3 — Payroll integration**
`PayrollService::calculate()` calls the engine when the plan includes `hrms.payroll.statutory` **and** a
configuration exists for the employee's `country`/`region`. Results are snapshotted into
`payslips.statutory` JSON **and** added to the deductions/employer-contribution arrays. A later
configuration change must not alter a locked payslip — the snapshot is the record.
`php artisan hrms:statutory-recompute --run=ID [--force]` recomputes only `review`-state runs.

**P10.4 — TDS projection**
`StatutoryService::projectTds(Employee, fiscalYear)` — annualise the last 3 months of payslips, add
declared exemptions, apply the slab config, split into quarterly `tds_projects` rows, and surface
under-deduction as a warning on the payroll run. `POST .../tds-projects/{project}/surrender` records a
challan reference plus an audit row.

**P10.5 — PII protection**
`StatutoryProfileController` accepts `pan|aadhaar|uan|esi_number|pf_number|bank_account` on write and
returns only masked values plus `has_*` booleans on read (`pan: 'XXXXX1234Y'`, `bank_account:
'****1234'`). Reading an unmasked value requires `hrms.payroll.statutory.manage` and writes an
`hrms_data_access_logs` row. Add `StatutoryProfile` to a "never `$model->toArray()` in a list endpoint"
review checklist.

**P10.6 — Policies, requests, routes, frontend**
`StatutoryConfigurationPolicy` (manage `hrms.payroll.statutory.manage`), `StatutoryProfilePolicy` (view
self OR manage; write manage). The whole group sits behind `ensure_module:hrms.payroll.statutory`:
`/api/hrms/payroll/statutory/configurations`, `.../profiles`, `.../profiles/{employee}`,
`.../declarations`, `.../tds-projects`, `.../tds-projects/{project}/surrender`, `.../recompute`.
Frontend: `pages/hrms/Statutory.jsx` (config editor with a jurisdiction picker, per-employee profile
form, declarations, TDS projection table with under-deduction warnings), visibly marked as restricted
data with a read-receipt note in the UI copy.

**Risk gates before this phase ships (Part 8, R1-R4):** domain-expert review of each rule, a
`config/hrms.php` fixture corpus with expected outputs, and an explicit "statutory figures are advisory"
disclaimer in the UI.

**Acceptance:** a payslip with statutory enabled contains PF/ESI/PT lines matching the fixture corpus; a
payslip calculated with the module disabled contains none and matches a pre-Phase-10 run; a locked
payslip is unaffected by a configuration change; bank account/PAN never appear unmasked in any response
including error payloads; every unmasked read is logged.

---

### Phase 11 — Expenses

**Objective:** claims with receipts, category policy, approval, and a clean hand-off to payroll.

**P11.1 — `000024` migration**
`expense_categories` (`name`, `slug` unique, `description`, `requires_receipt_above` decimal(14,2)
nullable, `is_reimbursable` bool, `payroll_component_id` nullable, `is_active`, `position`, `is_system`),
`expense_claims` (`employee_id`, `claim_number` unique, `claim_date` date, `period_year` smallint,
`period_month` tinyint, `purpose`, `description` nullable, `currency` char(3), `total_amount`
decimal(14,2), `approved_amount` decimal(14,2) nullable, `reimbursed_amount` decimal(14,2) nullable,
`status` enum `draft|submitted|pending|approved|rejected|paid|cancelled`, `approval_id` nullable,
`paid_in_payroll_run_id` nullable, `paid_via` enum `payroll|manual` nullable, `decided_at`,
`decided_by_user_id`, timestamps, `softDeletes`) indexed `(employee_id, status)` and
`(period_year, period_month)`, `expense_claim_items` (`claim_id`, `category_id`, `description`, `amount`
decimal(14,2), `spent_at` date nullable, `vendor` nullable, `receipt_document_id` nullable,
`is_billable` bool, `notes`).

**P11.2 — Service**
`app/Services/Hrms/ExpenseService.php` — `create(Employee, data)` (validates receipts against
`requires_receipt_above`, and **recomputes `total_amount` from the items** — never trust the client
total), `submit` (locks the claim and routes approval: manager then the `hrms.expenses.approve` role),
`approve/reject` (records `approved_amount`, which may be **less** than `total_amount` with a reason),
`reimburse(Claim, Run)` (called by `PayrollService` when the claim's period is in the run: creates a
`payslip_adjustments` row with `source_type = 'expense'` and marks the claim `paid`).
- The claim -> payslip link is a **reference**, not a service dependency: payroll reads approved claims;
  expenses never call payroll.

**P11.3 — Policies, requests, routes, frontend, notifications**
`ExpenseClaimPolicy` (view self or `hrms.expenses.view`; approve `hrms.expenses.approve`; manage
`hrms.expenses.manage`; categories manage `hrms.expenses.manage`).
Routes: `/api/hrms/expenses/categories`, `.../claims`, `.../claims/{claim}`,
`.../claims/{claim}/items`, `.../claims/{claim}/submit`, `.../claims/{claim}/decide`.
Frontend: `pages/hrms/Expenses.jsx` (claims queue filtered by period/status, approve with a reduced
amount, receipts viewer), `components/hrms/ExpenseClaimModal.jsx` (line-item editor, receipt upload via
`employee_documents`), `pages/hrms/MyExpenses.jsx`. Notifications
`hrms.expense.submitted|approved|rejected|paid`.

**Acceptance:** a claim total is server-computed; a missing receipt above the threshold 422s; a partial
approval records the reduced amount and reason; an approved claim in a period's run becomes a payslip
adjustment exactly once; a claim cannot be edited after submission.

---

### Phase 12 — Performance management

**Objective:** cycles, goals, check-ins, one-on-ones, and task-derived **evidence** — never scores.

**P12.1 — `000025` migration**
`performance_cycles` (`name`, `slug` unique, `description`, `period_start` date, `period_end` date,
`stage` enum `goal_setting|check_in|self_review|manager_review|calibration|completed`, `anonymity` enum
`none|reviewer|peer`, `is_active` bool, `created_by`, timestamps), `performance_goals` (`cycle_id`,
`employee_id`, `title`, `description`, `category` nullable, `metric_type` enum
`none|task_completion|worklog_hours|manual`, `target_value` decimal(12,2) nullable, `weight`
decimal(5,2) default 100, `due_date` date nullable, `status` enum
`draft|active|achieved|missed|cancelled`, `progress_percent` decimal(5,2) default 0, `progress_source`
enum `auto|manual`, `progress_evidence` JSON nullable, `achieved_at` nullable, `created_by`, timestamps),
`goal_task_links` (`goal_id`, `task_id`, `created_by`, timestamps) unique `(goal_id, task_id)`,
`check_ins` (`cycle_id`, `employee_id`, `body`, `mood` enum `great|good|ok|low` nullable, `blockers`
nullable, `needs_support` bool, `created_at`) indexed `(cycle_id, created_at)`, `one_on_ones`
(`employee_id`, `manager_employee_id` nullable, `scheduled_at`, `duration_minutes` nullable, `agenda`,
`notes`, `follow_up` nullable, `action_items` JSON nullable, `status` enum `scheduled|held|cancelled`,
`created_by`, timestamps), `feedback_requests` (`cycle_id`, `from_employee_id`, `to_employee_id`,
`relation` enum `manager|peer|direct_report`, `status` enum `pending|submitted|declined`, `due_date`,
`created_by`, timestamps), `feedback_responses` (`feedback_request_id`, `from_employee_id`, `rating`
tinyint nullable, `body`, `submitted_at`) unique `(feedback_request_id, from_employee_id)`,
`review_summaries` (`cycle_id`, `employee_id`, `self_rating` tinyint nullable, `manager_rating` tinyint
nullable, `strengths`, `improvements`, `manager_comments`, `evidence_snapshot` JSON nullable,
`visibility_to_employee` enum `hidden|shared`, `status` enum `draft|calibrating|final|acknowledged`,
`submitted_at`, `acknowledged_at`, `manager_employee_id` nullable) unique `(cycle_id, employee_id)`.
- **There is no `overall_score` column.** Ratings are 1-5 per dimension, entered by humans.

**P12.2 — Evidence engine (read-only over task data)**
`app/Services/Hrms/PerformanceService.php::refreshGoalEvidence(Goal)` by `metric_type`:
- `task_completion`: count **completed** tasks assigned to the employee in the cycle window via
  `ScopesVisibleTasks::visibleTaskQuery($employeeUser)` so project-membership scoping is respected;
  compare with `target_value` -> `progress_percent` (capped 100) and store
  `progress_evidence = {completed, created, overdue, window}`.
- `worklog_hours`: `SUM(work_logs.duration_minutes)` in the window plus `days_logged`.
- `manual`: nothing — HR sets the percentage.
- It **never** writes a rating. `refreshCycleEvidence(Cycle)` sweeps all goals and is called from
  `php artisan hrms:performance-evidence --cycle=ID|--all` and after a task completion for a linked goal.

**P12.3 — Cycle workflow**
`createCycle` -> `openGoalSetting` (employees draft goals; the per-employee `weight` sum is validated to
be 100 +/- 0.01, else 422) -> `openCheckIn` -> `openSelfReview` -> `openManagerReview` (managers file
`review_summaries`) -> `openCalibration` (HR sees all ratings, needs `hrms.talent.manage`) -> `complete`
(notifies participants and locks the cycle). Feedback requests are generated at `openManagerReview`
(manager plus N peers, from `hrms_settings`), honouring `anonymity` — peer responses are shown
aggregated when `anonymity = peer`.

**P12.4 — Policies, requests, routes**
`PerformanceCyclePolicy` (view `hrms.performance.view`; manage `hrms.performance.manage`),
`PerformanceGoalPolicy` (view: self, the goal owner's manager, or `hrms.performance.view`; update: self
while `draft`, or manage), `ReviewSummaryPolicy` (**the viewer depends on `visibility_to_employee` and
`anonymity`** — a manager sees their reports' drafts; a peer never sees another person's rating),
`FeedbackRequestPolicy` (respond = the requested reviewer only).
Routes: `/api/hrms/performance/cycles`, `.../cycles/{cycle}/open|complete`, `.../cycles/{cycle}/goals`,
`.../goals/{goal}` (+ `progress` refresh), `.../cycles/{cycle}/check-ins`, `.../one-on-ones`,
`.../cycles/{cycle}/feedback-requests`, `.../feedback-requests/{request}/respond`,
`.../cycles/{cycle}/reviews`, `.../reviews/{review}` (+ `acknowledge`),
`.../cycles/{cycle}/evidence`.

**P12.5 — Frontend**
`pages/hrms/Performance.jsx` (cycle list + stage stepper),
`pages/hrms/PerformanceCycleDetail.jsx` (tabs: goals | check-ins | one-on-ones | feedback | reviews),
`components/hrms/GoalCard.jsx` (progress bar plus an **evidence disclosure** — "12 of 15 tasks completed
· 34h logged · 2 overdue" in a tooltip/panel, never as a score), `components/hrms/CheckInComposer.jsx`,
`pages/hrms/MyPerformance.jsx` (my goals, check-ins, 1:1s, feedback to/from). The UI must not compute or
display any composite score — enforce by review and by a comment in the page file.

**P12.6 — Goal <-> task linking (co-designed with Phase 20)**
`goal_task_links` is created by this phase's migration; **Phase 20 adds `hrms_task_links`** for the
general employee<->task association. Add `POST .../goals/{goal}/tasks` (attach by task id or by `?task=KEY`
deep link) and surface linked tasks on the goal card.

**Acceptance:** a goal's evidence refreshes from real task data and shows counts, not a score; weights
must total 100; a peer cannot see another person's rating; a manager sees only their reports; the cycle
state machine rejects out-of-order transitions; acknowledging a review notifies the employee.

---

### Phase 13 — Employee documents

**Objective:** the document store every other phase references — with the signed-download pattern done
correctly from the first commit.

**P13.1 — `000026` migration**
`document_types` (`name`, `slug` unique, `category` enum
`identity|education|employment|tax|bank|medical|asset|letter|other`, `is_mandatory` bool,
`requires_expiry` bool, `retention_months` nullable, `is_sensitive` bool, `position`, `is_active`,
`is_system`), `employee_documents` (`employee_id`, `document_type_id`, `title`, `file_disk` default
`local`, `file_path`, `original_name`, `mime`, `size` int, `issued_at` date nullable, `expires_at` date
nullable, `status` enum `pending|verified|rejected|expired`, `verified_at` nullable,
`verified_by_user_id` nullable, `rejection_reason` nullable, `visibility` enum
`employee|hr|manager|owner`, `confidential` bool, `source` enum
`employee|hr|onboarding|offboarding|expense|asset|payslip`, `created_by`, timestamps, `softDeletes`).
Indexes: `(employee_id, status)`, `(expires_at)`, `(status, expires_at)`.
- Files on the `local` disk at `hrms/{tenant_id}/{employee_id}/{uuid}.{ext}`; the same MIME whitelist as
  attachments (`File::types([jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,md,csv,zip,json])`,
  `max(10 * 1024)` KB, overridable in `hrms_settings`).

**P13.2 — Upload/verify service + signed download (D2.13)**
`app/Services/Hrms/DocumentService.php` — `upload(Employee, type, UploadedFile, meta)`, `verify`, `reject`,
`markExpired` (a `php artisan hrms:documents-expiry` command flags documents past `expires_at`),
`expireSoon(Employee, $days)`, `delete` (removes the file, the row, and writes an audit entry;
**soft-deleted rows 404** like the rest of the app).
`present()` builds `download_url` with
`url()->temporarySignedRoute('hrms.documents.download', now()->addHours(1), ['document' => $id, 'tenant' => TenantContext::currentId()])`.
The route `GET api/hrms/documents/{document}/download` is registered **outside** `switch_tenant`/`auth`
with `middleware('signed')`, and the controller takes `(Request $request, int $document)`, resolves
`Tenant::find($request->query('tenant'))`, and streams inside
`TenantDatabaseManager::using($tenant, fn () => ...)`.
- **Write the regression test first:** mirror
  `tests/Feature/CollaborationTest.php::test_signed_download_works_without_a_session_on_the_central_connection`
  — `flushSession()`, `DB::setDefaultConnection('iso_system')`, hand-sign a URL, assert 200 and the file
  bytes; assert a foreign tenant's signed id 404s; assert a tampered `tenant` param 403s.
- Confidential documents additionally require `hrms.documents.view_sensitive`, checked **inside** the
  `using()` closure after resolving the record, and an access row is written.

**P13.3 — Policies, requests, routes**
`EmployeeDocumentPolicy` (view self or `hrms.documents.view`; confidential additionally
`hrms.documents.view_sensitive`; upload self or `hrms.documents.manage`; verify/reject/delete
`hrms.documents.manage`).
`app/Http/Requests/Hrms/{DocumentTypeRequest, DocumentUploadRequest}` with the `File::types(...)->max(...)`
rule. Routes: `/api/hrms/documents/types`, `.../types/{type}`, `.../documents`, `.../documents/{document}`,
`.../documents/{document}/verify|reject`, `.../documents/{document}/download` (signed, outside),
`.../expiring?days=`, `my/documents`.

**P13.4 — Frontend**
`pages/hrms/Documents.jsx` (store filtered by employee/type/status, expiring-soon panel, bulk verify),
`pages/hrms/MyDocuments.jsx` (upload, status, download links, expiry warnings),
`components/hrms/DocumentUploader.jsx` (multipart with `fieldErrors`, the `AttachmentList` pattern). The
employee profile's `documents` tab embeds a filtered store.

**Acceptance:** a document uploaded in a fresh tab downloads via its signed URL with no session; a
tampered tenant param 403s; a confidential document is 403 without `hrms.documents.view_sensitive` and
logs an access row; an expired document flips to `expired` via the command; delete removes the file.

---

### Phase 14 — Asset tracking

**Objective:** a register with assignment, acknowledgement, condition, and return.

**P14.1 — `000027` migration**
`asset_categories` (`name`, `slug` unique, `description`, `default_condition` enum
`new|good|fair|poor` nullable, `is_active`, `position`, `is_system`), `assets` (`asset_code` unique,
`name`, `category_id`, `brand` nullable, `model` nullable, `serial_number` unique nullable,
`purchase_date` date nullable, `purchase_value` decimal(14,2) nullable, `vendor` nullable,
`invoice_document_id` nullable, `warranty_ends_at` date nullable, `condition` enum
`new|good|fair|poor|damaged`, `status` enum `available|assigned|maintenance|retired|lost`,
`assigned_to_employee_id` nullable, `assigned_at` nullable, `returned_at` nullable, `location_id`
nullable, `notes` nullable, `created_by`, timestamps, `softDeletes`), `asset_assignments` (`asset_id`,
`employee_id`, `assigned_by_user_id`, `assigned_at`, `condition_out` enum, `acknowledged_at` nullable,
`returned_at` nullable, `condition_in` enum nullable, `return_note` nullable, `status` enum
`active|returned|lost|damaged`, timestamps) unique `(asset_id, employee_id, assigned_at)`,
`asset_maintenance` (`asset_id`, `type` enum `repair|service|upgrade|inspection`, `description`,
`performed_by` nullable, `cost` decimal(14,2) nullable, `performed_at` date, `next_due_at` date nullable,
`notes` nullable, `created_by`).

**P14.2 — Service + the offboarding tie (the reason this phase matters)**
`app/Services/Hrms/AssetService.php` — `create`, `update`, `assign(Asset, Employee, conditionOut)` (422 if
already assigned; sets `status = assigned` and opens an `asset_assignments` row, and **notifies the
employee to acknowledge**), `acknowledge(Assignment)`, `returnAsset(Assignment, conditionIn, note)`,
`markLost`, `retire`, `maintenance(Asset, data)` (sets `status = maintenance` while a record is open),
`byEmployee(Employee)`, `overdueReturns`.
- `OffboardingService::summary()` counts assets whose `status = assigned`, and `clear()` refuses while that
  count is non-zero (P4.2). This is the cross-phase contract — test it in both directions.

**P14.3 — Policies, requests, routes, frontend, notifications**
`AssetPolicy` (view `hrms.assets.view`; manage `hrms.assets.manage`; acknowledge = the assignee only).
Routes: `/api/hrms/assets/categories`, `.../assets`, `.../assets/{asset}`,
`.../assets/{asset}/assign|return|maintenance|document` (signed download),
`.../assignments/{assignment}/acknowledge`, `my/assets`.
Frontend: `pages/hrms/Assets.jsx` (register with filters, condition badges, assign/return modals,
maintenance log), `pages/hrms/MyAssets.jsx` (assigned assets, acknowledge, report damage).
Notifications `hrms.asset.assigned|acknowledged|returned|return_overdue`.

**Acceptance:** assigning a laptop notifies the employee and blocks a second assignment; acknowledgement
is recorded; a return updates condition and status; an offboarding case with an outstanding asset cannot
be cleared; `my/assets` shows only the caller's assets.

---

### Phase 15 — HR inbox & notifications

**Objective:** one place to work ("My HR inbox") that aggregates every pending approval and assigned
action item, plus a complete notification taxonomy with deep links.

**P15.1 — `000028` migration**
`inbox_reads` (`user_id`, `item_key` string(120), `read_at` datetime) unique `(user_id, item_key)`.
`item_key` is a stable string: `approval:{id}`, `case_task:{id}`, `attendance_reg:{id}`,
`document_request:{id}`, `asset_assignment:{id}`, `payslip:{id}`.

**P15.2 — Inbox service (aggregation, no queue table)**
`app/Services/Hrms/InboxService.php::items(User)` merges, prioritises and paginates the sources:
- `ApprovalService::pendingFor(User)` -> `approval:{id}`
- onboarding/offboarding case tasks whose `owner_scope` maps to the user (self, their managed reports, or
  HR), `status = pending`, `due_date <= today + N` -> `case_task:{id}`
- `attendance_regularization_requests` they raised (`pending`) and ones they must approve
- `document_requests` assigned to them
- `asset_assignments` awaiting acknowledgement, and assets due for return
- documents expiring for employees they manage
- open payslip disputes on their own payslips

Each item: `{key, type, title, subtitle, priority, due_at, meta, source_id}` — the **server sends `meta`,
never a URL**; the client builds `href` with `utils/deepLinks.js`.
`markRead(User, key[])`, `markAllRead`, `unreadCount(User)` (cached 30s, busted on any write).

**P15.3 — Notification taxonomy**
Add small, testable methods to `NotificationService` (each following `taskAssigned`):
```
hrms.leave.requested | hrms.leave.approved | hrms.leave.rejected
hrms.comp_off.requested | hrms.comp_off.approved | hrms.comp_off.rejected
hrms.attendance.regularized
hrms.expense.submitted | hrms.expense.approved | hrms.expense.rejected | hrms.expense.paid
hrms.onboarding.task_due
hrms.offboarding.clearance_pending
hrms.document.expiring | hrms.document.verified | hrms.document.rejected
hrms.asset.assigned | hrms.asset.acknowledged | hrms.asset.returned | hrms.asset.return_overdue
hrms.payroll.published | hrms.payroll.dispute_opened
hrms.performance.cycle_opened | hrms.performance.review_shared | hrms.performance.acknowledge_due
hrms.survey.invited | hrms.survey.closing_soon
hrms.inbox.digest
```
Every `data` payload carries the ids the deep link needs, plus the existing shape (`task_id`/`key`/
`title`/`project_id`/`workspace_id`) so the current notification list keeps rendering without
special-casing.

**P15.4 — Frontend**
`pages/hrms/Inbox.jsx` (`/hrms/inbox`) — grouped by type with priority ordering, per-item mark-read,
"mark all read", and inline actions for the two most common item kinds (approve/reject a leave
regularization) so the inbox is useful without navigation. `Sidebar.jsx` shows a count badge on the
People section when `unreadCount > 0` (polled at the existing 30s `NotificationContext` cadence).
`utils/notifications.js`: `describeNotification` copy for every type plus `notificationHref` branches.
`utils/deepLinks.js`: `employeeUrl`, `hrmsUrl`, `payrollRunUrl`. `NotificationBell` renders HRMS items
with an HR icon and routes correctly.

**Acceptance:** an approval for the user appears in the inbox within one request; marking read persists
per user (not globally); every taxonomy entry has copy and a working deep link; a non-impersonating
super admin's inbox is empty, matching the existing `DetectsPlatformUsers` short-circuit.

---

### Phase 16 — Engagement & surveys

**Objective:** pulse/engagement surveys with anonymity thresholds and HR-safe results.

**P16.1 — `000029` migration**
`survey_templates` (`name`, `slug` unique, `description`, `type` enum
`pulse|engagement|onboarding_exit|exit|custom`, `is_anonymous` bool, `is_active`, `frequency` enum
`one_time|weekly|monthly|quarterly|annual`, `audience_scope` enum
`all|department|role|location|explicit`, `audience_meta` JSON nullable, `settings` JSON nullable,
`created_by`), `survey_questions` (`template_id`, `text`, `type` enum
`scale|text|multiple_choice|yes_no|nps`, `options` JSON nullable, `is_required` bool, `min`/`max`
nullable, `sequence`), `survey_campaigns` (`template_id`, `name`, `starts_at`, `ends_at`, `status` enum
`scheduled|open|closed`, `anonymity_threshold` int default 5, `notify_on_publish` bool, `created_by`,
timestamps), `survey_responses` (`campaign_id`, `employee_id` **nullable** (null when the campaign is
anonymous), `respondent_key` char(64), `started_at`, `submitted_at` nullable, `ip_hash` char(64) nullable,
`user_agent` nullable) with a unique index on `(campaign_id, employee_id)` and, for anonymous campaigns,
a unique index on `(campaign_id, respondent_key)` where
`respondent_key = md5(campaign_id . ip_hash . user_agent)` — spell this out in the migration and test
double submission, `survey_answers` (`response_id`, `question_id`, `value_text` nullable, `value_number`
decimal(12,2) nullable, `value_json` JSON nullable), `survey_results` (`campaign_id`, `question_id`,
`aggregates` JSON, `response_count` int, `computed_at`) unique `(campaign_id, question_id)`.

**P16.2 — Service + anonymity**
`app/Services/Hrms/EngagementService.php` — `createTemplate`, `schedule`, `open`, `close`, `invite`
(audience resolution + `hrms.survey.invited` notifications), `respond(Campaign, answers)` (**one
submission per campaign**, enforced by the unique index), `results(Campaign)`.
- Aggregation is **refused** (empty result, not an error) when `response_count < anonymity_threshold`.
  Free-text answers are never returned below the threshold and are redacted to counts only above it for
  `is_anonymous` campaigns.
- Results are `Cache::remember(..., 300)` and invalidated on close.

**P16.3 — Policies, requests, routes, frontend**
`SurveyTemplatePolicy` (manage `hrms.engagement.manage`; view results `hrms.engagement.view`; respond =
anyone invited, authenticated only).
Routes: `/api/hrms/engagement/templates`, `.../templates/{template}/questions`, `.../campaigns`,
`.../campaigns/{campaign}/open|close|invite|results`, `.../campaigns/{campaign}/respond`, `my/surveys`,
`my/surveys/{campaign}`.
Frontend: `pages/hrms/Engagement.jsx` (template builder, campaign list, results with Recharts bars for
scale/NPS questions), `pages/hrms/MySurvey.jsx` (one question per page with progress),
`components/hrms/ResultsChart.jsx`.
`php artisan hrms:surveys-open-close` opens and closes campaigns on schedule.

**Acceptance:** a campaign below its anonymity threshold returns no results and no free text; a double
submission is rejected; scale/NPS aggregation is correct; a scheduled campaign opens on schedule; a
non-recipient cannot see the campaign.

---

### Phase 17 — My Team & self-service

**Objective:** the employee-facing surface — one page with everything an individual needs, plus a
manager's read-only team view. **No new tables** (preferences live in `user_settings.settings['hrms']`).

**P17.1 — `GET api/my/hr` — the self-service aggregate**
One request returning the caller's profile summary, leave balances, upcoming approved leave, current
attendance month summary, pending requests across leave/expense/comp-off, inbox count, assigned assets,
document status (expiring soon), next pay date plus the latest payslip summary, active performance
goals and next check-in, pending onboarding/offboarding tasks, and a `quick_actions` array the UI
renders as cards. Cached per user for 60s, busted on any HRMS write.
- In the `auth -> tenant` group, gated by `ensure_module:hrms.core`; returns **404** for a
  non-impersonating super admin (D2.14).

**P17.2 — `GET api/my/team` (manager)**
For each direct report: today's attendance, this month's summary, on-leave-today, overdue tasks (via
`visibleTaskQuery`), pending approvals from them, and a compact leave balance. Requires
`hrms.attendance.view` **or** being their manager (policy). Paginated with `?from=&to=`.
**No salary data** — that stays behind `hrms.compensation.*`.

**P17.3 — `GET api/hrms/employees/{employee}/summary` (HR)**
The HR-side counterpart: employment status, tenure, department/manager, and attendance + leave + asset +
document + performance headline numbers for one employee in one request (the profile page needs it).

**P17.4 — Preferences**
`GET|PUT api/my/hr/preferences` writing `user_settings.settings['hrms']`:
`{email_digest, inbox_badge, attendance_reminders, leave_reminders, payroll_published_alerts,
document_expiry_alerts, weekly_summary}`. These drive the notification digests.

**P17.5 — Frontend**
`pages/MyHr.jsx` at `/my` (the "My HR" home: quick actions, balances, upcoming leave, clock-in, pending
items, payslip link) and `pages/hrms/MyTeam.jsx` at `/hrms/team` (manager view drilling down to the
employee profile). Both reuse the `components/hrms/*` widgets already built.

**Acceptance:** `/my` renders in one request with no N+1 (assert the query count in a test); a manager
sees only their reports; salary data is absent from `my/team` for a user without `hrms.compensation.view`;
preferences persist and drive the digests.

---

### Phase 18 — HR analytics & reporting

**Objective:** workforce dashboards and scheduled digests, reusing the existing analytics/caching
patterns.

**P18.1 — `000030` migration**
`hrms_report_schedules` (`name`, `slug` unique, `definition` JSON, `cadence` enum
`daily|weekly|monthly|quarterly`, `recipients` JSON (user ids and/or role slugs), `last_run_at` nullable,
`next_run_at` nullable, `is_active`, `created_by`).

**P18.2 — `HrmsAnalyticsService` (cached)**
`app/Services/Hrms/HrmsAnalyticsService.php` — each method `Cache::remember($key, $ttl, fn () => ...)`:
- `headcount(filters)` — total, by status, by department, by location, by employment type, new hires and
  exits this month, 3-month rolling attrition.
- `attendance(filters)` — present/absent/late/OT/leave days, average worked hours, top late/OT
  (respects `hrms.attendance.view`; **never** exposes per-person data without it).
- `leave(filters)` — by type, pending approvals, top consumers, expiry liability (days x rate from the
  salary structure — **payroll-gated**).
- `lifecycle` — onboarding/offboarding cycle durations, checklist completion rates, time-to-first-day.
- `performance` — goals by status, review completion %, **counts only, no ratings**, unless
  `hrms.talent.view` (and then per the anonymity rules).
- `payroll` — total CTC, total net, department-wise cost. **`hrms.payroll.run` required**; every call
  writes an `hrms_data_access_logs` row.
- `documents` — compliance (% of employees holding each mandatory type), expiring in 30/60/90 days.
- `assets` — assigned/available/maintenance/lost counts, assets per department.

**P18.3 — Controller + routes**
`GET api/hrms/analytics/overview` (headcount + 6 tiles) plus `.../attendance`, `.../leave`,
`.../lifecycle`, `.../performance`, `.../payroll`, `.../documents`, `.../assets` — each behind its **own**
route-level `permission:` gate, never a blanket `workspaces.view` (the Phase 7 precedent). Optional
`?from=&to=&department_id=&location_id=&employment_type_id=`, validated against the tenant's own org
tables (422 on an unknown id).

**P18.4 — Scheduled digests**
`php artisan hrms:report-digests` reads `hrms_report_schedules` whose `next_run_at` has passed, builds
the report, and either posts a notification (`hrms.inbox.digest`) or emails, then advances `next_run_at`.
Notification delivery is the existing `NotificationService` path (no new mail transport in this phase).

**P18.5 — Frontend**
`pages/hrms/Analytics.jsx` — a stat-tile row (Reuse `Dashboard.jsx`'s widget pattern) plus tabs per
domain, each with Recharts charts and CSV export buttons that write `accessed(..., 'export')` rows.
Reuse `components/time/TimeSummary.jsx` and `utils/time.js::formatMinutes()` for minutes-based panels.

**Acceptance:** a manager sees only their reports' aggregates; a user without `hrms.attendance.view` gets
403 on the attendance tab; payroll analytics 403 without `hrms.payroll.run` and log an access row when
allowed; every chart is module-gated; a digest runs on schedule and does not re-send.

---

### Phase 19 — Audit, security & data protection

**Objective:** cross-cutting hardening for the whole module, applied after each phase and consolidated
here. Most work is verification rather than new features.

**P19.1 — Audit-log viewer**
`GET api/hrms/audit` (filters: `actor_user_id`, `subject_type`, `subject_id`, `action`, `from`, `to`,
`q`, `sort`, `dir`, `per_page`) and `GET api/hrms/audit/{subjectType}/{subjectId}` (one record's full
before/after trail). Gated by `hrms.audit.view`. Read-only, paginated with the standard payload.
Frontend: `pages/hrms/AuditLog.jsx` (filter bar, expandable JSON diff view) plus an **Audit tab on the
employee profile** for `hrms.employees.view`.

**P19.2 — Data-access log + PII audit review**
`GET api/hrms/audit/data-access` (`hrms.audit.view`) for salary/bank/document read trails. Then do a
**manual review pass** over every HRMS controller for: (a) no `$model->toArray()` in a list/index
response for a model carrying sensitive columns, (b) an `accessed()` row on every sensitive read, (c) no
sensitive field inside a notification `data` payload or a log line, (d) no sensitive field in a
`ValidationException` message.

**P19.3 — Export controls**
Any HRMS export (attendance, leave, payroll, analytics CSV) requires the module + the specific `view`
permission, writes an `accessed(..., 'export')` row with the field list, and is rate-limited by the
existing throttle middleware. Payroll exports additionally require `hrms.payroll.run`.

**P19.4 — Retention + purge**
`php artisan hrms:retention --dry-run` reports rows past `hrms_settings.data_retention_months` per table
(payslips, exits, attendance, documents, audit). `--apply` deletes in batches. **Never** touches
`hrms_audit_logs` (append-only retention is a legal decision, out of scope).

**P19.5 — Security review checklist (execute, tick each in this document)**
- [ ] Every HRMS route requires `tenant_context`; a non-impersonating super admin gets 404 everywhere
- [ ] Every HRMS route is behind an `ensure_module:` gate for a module it actually uses
- [ ] No cross-tenant id is accepted in a request body without a tenant-scoped lookup
- [ ] Every policy has a test for: authorised, unauthorised-with-permission, unauthorised-without,
      and cross-tenant
- [ ] `mass_assignment` guarded: no HRMS controller blindly `fill()`s request input
- [ ] `hrms_data_access_logs` written for every salary/bank/statutory/document read and export
- [ ] Encrypted casts verified by a test asserting the raw column is not the plaintext
- [ ] `LOG_LEVEL`/`LOG_CHANNEL` output contains no salary, bank, PAN, or document content
- [ ] Soft-deleted employees/documents/claims 404 rather than returning data
- [ ] All file paths are tenant-prefixed, so a bug cannot cross tenants on disk
- [ ] Rate limits on punch, clock-in, survey-response, and export endpoints
- [ ] `EnsureModule` and any new middleware appear in `$middleware->priority` where required

**Acceptance:** the checklist is fully ticked with a test or a documented reason for each line; the
retention command is idempotent and defaults to `--dry-run`.

---

### Phase 20 — Task-management integration

**Objective:** make HRMS and task management one system — employees own tasks, tasks produce evidence,
and HRMS can surface work without leaving HR.

**P20.1 — `000031` migration**
`hrms_task_links` (`employee_id`, `task_id`, `kind` enum
`goal|onboarding|attendance|expense|payroll|leave|review`, `note` nullable, `created_by`, timestamps)
unique `(employee_id, task_id, kind)`, indexed `(task_id, kind)`.
Plus, on `tasks`, a nullable `hrms_employee_id` FK for the "HR-owned task" affordance (so an HR admin
can create a task — e.g. "submit your bank details" — and have it show up on the employee's HR home).

**P20.2 — Link service + endpoints**
`app/Services/Hrms/TaskLinkService.php` — `link(Employee, Task, kind)`, `unlink`, `forTask(Task)`,
`forEmployee(Employee)`.
`GET api/hrms/tasks/{task}/links`, `POST api/hrms/tasks/{task}/links` (`{employee_id, kind}`),
`DELETE .../links/{link}`, `GET api/hrms/employees/{employee}/tasks` (the employee's tasks via
`visibleTaskQuery`, filterable by `kind` and `status`).

**P20.3 — Onboarding/offboarding tasks become real tasks**
A checklist item with `category = task` can be converted to a project task: pick a project, create the
task assigned to the employee, and store the link. Status syncs one way (task completed -> checklist
done) and is recomputed rather than pushed, so a manual task edit is never overwritten by a stale
checklist state.

**P20.4 — Attendance derived from work logs (opt-in)**
`hrms_settings.attendance.derive_from_work_logs` (default **false**) plus
`php artisan hrms:derive-attendance --date=... --tenant=ID` marks `attendance_days` from `work_logs` when
enabled. Opt-in because `work_logs` are voluntary and a manager could otherwise manufacture attendance.
Every derived day records `is_regularized = false` and an `attendance_days.note` of
`derived:work_logs`.

**P20.5 — Performance evidence from tasks (hardened)**
Extend P12.2 to also consult `hrms_task_links` so a goal can be measured against explicitly linked tasks
as well as everything assigned to the employee. Restrict the count to tasks the reviewer can already
see (`visibleTaskQuery`), and store the exact task ids in `progress_evidence` so a reviewer can audit the
number. **Still no scoring** (D2.10).

**P20.6 — Frontend**
`components/hrms/TaskLinkPanel.jsx` on the task drawer (HR-only): link/unlink an employee to a task with
a `kind`, plus "create a task from this checklist item" on `Checklist.jsx`. On the employee profile add a
**Tasks** tab rendering the linked task list with status pills and links back into the project.
`utils/deepLinks.js` gains `taskUrl(projectId, key)` usage for these links (already exists — reuse it).

**Acceptance:** linking an employee to a task shows the link on both sides; unlinking removes it; a
checklist item converted to a task reflects completion; work-log-derived attendance is off by default and
only runs when explicitly enabled; a goal's evidence lists the exact task ids it counted.

---

### Phase 21 — Sidebar, navigation & UX polish

**Objective:** make the whole module feel like part of the product, and finish the cross-cutting UX.

**P21.1 — Sidebar "People" section**
`components/layout/Sidebar.jsx` — a new section with items for Overview, My Team, Inbox (badge count),
Employees, Org, Attendance, Leave, Shifts, Holidays, Comp-off, Expenses, Documents, Assets,
Compensation, Payroll, Performance, Talent, Engagement, Analytics, Audit. Each item carries its `module`
and `permission` so the existing filter hides it correctly; the section header renders when **any** item
is visible. Keep the section collapsed/sectioned like the existing nav.

**P21.2 — Onboarding wizard HRMS step**
Add a `hrms` step to `config/onboarding.php` (after `configuration`) with `'required' => false` and
`'module' => 'hrms.core'`, and add the key to `TenantOnboarding::COMPLETABLE_STEPS` — the const, not just
the config, because `completableSteps()` intersects against it and `markStep` validates against the const.
`TenantOnboarding::isComplete()` must **skip steps whose module the tenant does not have**, so a
`starter` tenant never sees the step and never gets blocked by it. `Onboarding.jsx`'s `stepContent` map
gains a `hrms` entry (company size, industry defaults, first-office location).

**P21.3 — Command-palette entries**
`components/search/CommandPalette.jsx` gains an `hrms` group (employees, then a shortcut row for each
enabled module), reusing the existing grouped result + keyboard-navigation machinery. Add
`GET api/search/global` results for employees only when the caller holds `hrms.employees.view` and the
`hrms.core` module is on.

**P21.4 — Empty states, loading, and error polish**
Every HRMS page gets: a module-specific `EmptyState`, `Spinner` while loading, a `403` redirect via the
existing pattern, and a permission-specific 403 message (not a generic one) for management surfaces.

**P21.5 — Accessibility + consistency pass**
Table headers, form labels, modal focus trapping, keyboard navigation for the org tree and the
checklist, and colour contrast for status pills. Confirm every action button is hidden (not merely
disabled) when the caller lacks the permission, matching the existing project/task UI convention.

**P21.6 — Documentation**
Update `AGENTS.md`: a new **HRMS** section summarising the module keys, the shared primitives, the
`config/hrms.php` catalog, the signed-download rule, the test gate, and the provisioning seed step. Add
`docs/hrms-architecture.md` if the module outgrows this plan (data model, service boundaries, payroll
pipeline). Update the test count.

**Acceptance:** the Sidebar shows exactly the items the current user may reach; a `starter` tenant sees
no People section; the wizard step appears only for HRMS tenants; the command palette finds employees;
`AGENTS.md` and (if created) the architecture doc are current; full suite green.

---

## Part 6 — Cross-cutting test matrix

Every phase must cover these rows for every endpoint it adds.

| Row | Setup | Expect |
|---|---|---|
| Happy path | admin, correct tenant, correct module + permission | 2xx, documented payload shape |
| Permission denied | `viewer` role | 403 with the field/key the UI surfaces |
| Module denied | plan without the module | 403 **and** the nav item absent |
| Cross-tenant | Globex admin hitting an Acme record id | 404 (never 403, never a row) |
| No tenant context | non-impersonating super admin | 404 (tenant-DB routes) / 200 (entitlement routes) |
| Validation | each field's boundaries | 422 with the exact key used by `fieldErrors()` |
| Business rule | e.g. a cycle change, a locked payroll run, a duplicate employee code | 422 `form` with a human message |
| Auth absent | no session | 302/401, never 500 |
| Sessionless file | signed URL, `flushSession()`, default connection `iso_system` | 200 with the file bytes; tampered `tenant` 403 |
| Soft delete | trashed record | 404 |
| N+1 | a list with 50 rows | query count bounded (mirror `HardeningTest`) |
| Pagination | >1 page | `{current_page,last_page,per_page,total}` |
| Audit | any sensitive mutation | an `hrms_audit_logs` row with before/after |
| Data access | any sensitive read | an `hrms_data_access_logs` row |
| **Logging** | any significant action / job / command | the dotted event logged to the `hrms` channel with `tenant_id` (+ `request_id`) in context, and **no** forbidden sensitive key present (D2.17.9) |
| Notification | any notification-triggering action | the right recipient, the right type, skips self |

Feature-test classes to add: `tests/Feature/Hrms/EmployeeTest.php`, `OrgTest.php`,
`OnboardingOffboardingTest.php`, `AttendanceTest.php`, `LeaveTest.php`, `CompOffTest.php`,
`HolidayTest.php`, `PayrollTest.php`, `StatutoryTest.php`, `ExpenseTest.php`, `PerformanceTest.php`,
`DocumentTest.php`, `AssetTest.php`, `InboxTest.php`, `EngagementTest.php`, `SelfServiceTest.php`,
`AnalyticsTest.php`, `HrmsSecurityTest.php`, `TaskLinkTest.php`. All use `Tests\IsolatesDatabase`.

Module-gating cases are added to `tests/Feature/ModuleGateTest.php` (one per `ensure_module:` usage).
Payroll statutory gets a dedicated fixture-driven unit test class.

---

## Part 7 — Manual verification harness

A proven pattern from the earlier attachment investigation. Reuse it for every module-gated endpoint.

```bash
BASE=http://localhost/app/api
JAR=$(mktemp)

# 1. log in (the -c is what makes the session + XSRF cookie persist)
curl -sS -c "$JAR" -b "$JAR" -o /dev/null "$BASE/sanctum/csrf-cookie"
XSRF=$(awk '/XSRF-TOKEN/{print $7}' "$JAR")

curl -sS -c "$JAR" -b "$JAR" -H "X-XSRF-TOKEN: $XSRF" \
     -H 'Content-Type: application/json' \
     -d '{"email":"admin@flowsync.test","password":"password"}' \
     "$BASE/login"

# 2. the XSRF token rotates on login — re-read it before any mutating call
XSRF=$(awk '/XSRF-TOKEN/{print $7}' "$JAR")

# 3. a mutating call with the fresh token
curl -sS -c "$JAR" -b "$JAR" -H "X-XSRF-TOKEN: $XSRF" \
     -H 'Content-Type: application/json' -d '{...}' "$BASE/hrms/employees"
```

Two mistakes this harness exists to prevent, both hit during the attachment investigation:

1. **Omitting `-c` on login** means the session cookie is never stored, so every later call is a 401 and
   the endpoint looks broken when it is fine.
2. **Reusing a pre-login XSRF token** produces a 419, which also looks like an application bug.

Per-task manual checks (0.4) on top of this:

```bash
# module gate: pro tenant -> 200; starter tenant -> 403
curl -sS -o /dev/null -w '%{http_code}\n' -b "$JAR" "$BASE/hrms/employees"

# entitlement switch (super admin)
curl -sS -X PUT -H "X-XSRF-TOKEN: $XSRF" -H 'Content-Type: application/json' \
     -d '{"modules":["hrms.payroll"]}' "$BASE/tenants/1/hrms"
```

After each frontend task: `npm run build`, then **hard-refresh the browser** (a stale bundle is the
single most common source of "my change did nothing").

After any migration task: run both the suite and the real PostgreSQL path
(`TENANT_DB_PG_ROLE=flowsync TENANT_DB_DRIVER=pgsql php artisan tenants:provision --tenant=<id>`).

---

## Part 8 — Risk register

| # | Risk | Phase | Mitigation |
|---|---|---|---|
| R1 | **Statutory computation errors** (PF/ESI/PT/TDS/LWF) cause real financial harm and legal exposure | 10 | Jurisdiction config only, no hard-coded thresholds; fixture corpus with expected outputs; domain-expert review; "advisory" disclaimer in the UI; snapshots so history never changes |
| R2 | **PII leakage** (salary, bank, PAN, national ids, documents) into responses, logs, notifications, or the command palette | all | Write-only ids + masked reads, encrypted casts, `accessed()` rows, the P19.5 checklist, no sensitive data in notification `data` |
| R3 | **N+1 on employee lists** — a directory of 1,000 employees with joins to users, departments, balances, and today's attendance | 2+ | Eager-load in the list service, `assertDatabaseCount`-style query bounds in tests, no per-row computation (the `memberRole()` lesson from Phase 1) |
| R4 | **Attendance/payroll correctness across timezones and DST** | 5, 9 | Store `punch_at` in UTC, derive `work_date` from the employee's location timezone via `hrms_settings`, never the server timezone; test a DST boundary explicitly |
| R5 | **Ledger drift** — balances disagreeing with the ledger after edits, cancellations, or reruns | 6, 7, 9 | The ledger is authoritative; `rebuildBalance` is idempotent and callable; a scheduled consistency check `hrms:leave-consistency --repair` |
| R6 | **Accrual double-credit** on reruns or re-provisioning | 6, 7 | Unique keys on accrual rows (`(employee, work_date, source)`, period-scoped accrual rows) and `firstOrCreate`/`updateOrInsert` everywhere |
| R7 | **Backfilling 102 live tenant databases** with HRMS data breaks an existing tenant | 1, 2 | Every migration repair-safe (`hasColumn`, `DROP INDEX IF EXISTS`, `whereNull` guards); `tenants:provision` runs pending-only; verify on one tenant first, then `--all`; a dedicated backfill command with `--dry-run` |
| R8 | **Module gating leaks** — a UI shows an HRMS nav item or an API responds when the module is off | all | One test per `ensure_module:` usage in `ModuleGateTest`; `AuthController::payload` `user.modules` is the single source for the client |
| R9 | **Circular phase dependencies** (documents needed by onboarding, expenses needed by payroll, performance needing task links) | all | The Part 4 dependency table; ship 13 before 4/11/14; payroll reads expenses by reference, never the reverse |
| R10 | **Over-scoped tasks** — a phase task that takes days and cannot be reviewed or reverted | all | 0.1 granularity; split and update this document before continuing |
| R11 | **Onboarding wizard deadlock** — a new required step that SA-provisioned tenants cannot complete (the never-started bypass) | 21 | Module-scoped steps; `isComplete()` skips steps whose module is off; only `business/admin/subscription` stay required |
| R12 | **Queue jobs losing their tenant connection** (HRMS reminders, rollups) | 5, 15, 18 | Dispatch from inside the tenant context or wrap `TenantDatabaseManager::using()`; add a test that a dispatched job runs against the right tenant DB |
| R13 | **Performance evidence leaking other people's tasks** | 12, 20 | Every evidence query goes through `ScopesVisibleTasks::visibleTaskQuery`; store the exact task ids counted |
| R14 | **Scale**: 1,000 employees x daily attendance x monthly payroll | 5, 9, 18 | Composite indexes on every filter column, `chunkById` in commands, `Cache::remember` on aggregates, batch inserts for rollups |
| R15 | **Scope creep** — HRMS quietly expanding into a full ERP | all | Anything not in Part 3.3 is out of scope; new ideas go into a "Deferred" list at the end of this document |
| R16 | **Undiagnosable failures** — a payroll run or leave batch produces wrong numbers and no trail explains why | all | D2.17: dedicated `hrms` channel, dotted event names, correlation id across request/job/command, `duration_ms` on every loop, start/success/failure on every job and command, Part 6 logging row |
| R17 | **Structural decay** — 22 modules collapse into fat controllers and stringly-typed arrays that no one can safely change | all | D2.16: strict layering, bounded-context folders, size ceilings, presenters, enums, FormRequests; the 0.8 gate runs on every task, so rot is caught at the commit that introduces it |

---

## Part 9 — Global definition of done

The module is done when **all** of the following hold:

1. Every phase's acceptance criteria are met and recorded in this document.
2. `php artisan test` passes with a count recorded in `AGENTS.md` and here; `npm run build` is clean;
   `./vendor/bin/pint` reports no changes.
3. A `starter` tenant sees no HRMS entry point anywhere; a `pro` tenant sees everything in Plan B; a
   `business` tenant adds Plan C; an `enterprise` tenant adds payroll and statutory.
4. A super admin can switch HRMS on/off for a single tenant without touching the plan, and the change is
   audited.
5. Every HRMS endpoint passes all 14 rows of the Part 6 matrix.
6. `hrms:backfill-employees` and `tenants:provision` have been run on the dev tenants so the module is
   demonstrable with realistic data.
7. The P19.5 security checklist is fully ticked.
8. The 0.8 structure & logging gate passes for every HRMS file (spot-check the 20 largest classes against
   the D2.16 ceilings), and `grep -r "dd(\|dump(\|ray(" app/` over the HRMS tree returns nothing.
9. Logging is genuinely useful in production: pick one payroll run, one leave approval and one failed job
   and reconstruct each from `storage/logs/hrms-*.log` using only the `request_id`, with no database
   access. If that is not possible, the phase is not done.
10. `AGENTS.md` has an HRMS section; this document has no unresolved "TODO" or "blocked" markers.

### Deferred (explicitly out of scope, recorded here for a future plan)

- Applicant tracking / ATS (candidates before they become employees)
- Recruitment interviews and job postings
- Training and LMS (e-learning, certifications)
- Recruitment, transfers, promotions, confirmation workflows beyond a status field
- Applicant-facing portals and employee self-onboarding outside the tenant
- Statutory return filing and challan generation (P10 produces the figures; filing is separate)
- Payroll vendor integration (bank file export formats) — P9 produces the data only
- Org-chart rendering beyond the department tree








