# Realistic demo dataset — plan, implementation and report

Development/demo data only. `php artisan tenants:seed-realistic` creates **150 named tenants** (50 HRMS-only, 50 TMS-only,
50 HRMS+TMS) with interconnected people, projects, tasks, time, leave, attendance, payroll and billing history.
Code: `database/seeders/RealisticDemoSeeder.php` + `database/seeders/Realistic/*`, config `config/demo_seed.php`,
verification `tenants:verify-realistic` (+ `tests/Feature/RealisticSeedTest.php`).

## 1. How it works

| Concern | Decision |
|---|---|
| Orchestration | `RealisticDemoSeeder::run()` — one tenant at a time, each in its **own database**: tenant row → `provisionIsolated` for *only* the subscribed products → subscriptions/billing → people → TMS → HRMS → usage/limits → lifecycle status. |
| Determinism | Every draw is `mt_srand(seed + tenantIndex·7919 + crc32(section))`; a tenant is identical on every run, whatever the order or worker count. Names come from a fixed vocabulary (`NameBank`), blueprints from pure code (`TenantBlueprints`). |
| Reruns | `settings.demo_seed = {version, complete}` on the tenant. Complete + current version → skipped. `--reseed` drops and rebuilds **only** tenants carrying that marker; a tenant with the same slug but no marker aborts with an error. Nothing else (users, platform rows, other tenants) is touched. |
| Speed | Caller-assigned primary keys, in-memory FK wiring, chunked `INSERT`s (`Bulk`), sequences re-synced at the end. ≈ 3–8 s per tenant, no N+1. |
| Size | Everything is configurable (`DEMO_SEED_*` env keys, see `config/demo_seed.php`), e.g. `DEMO_SEED_TENANTS_HRMS=2 … DEMO_SEED_TASKS_MIN=10` for a laptop slice. `--from/--to/--category/--slug` select tenants; run ranges in parallel. |
| Safety | Refuses outside local/testing without `--force`. Demo logins use one password (`config('demo_seed.password')`, hashed once). Email domains are `*.test` (reserved), so nothing can be delivered to a real address. |

Run it:

```bash
./scripts/dev.sh docker up
docker exec flowsync-app php artisan tenants:seed-realistic --dry-run                # print the 150-tenant plan
docker exec flowsync-app php artisan tenants:seed-realistic --from=0 --to=49 &       # three workers in parallel
docker exec flowsync-app php artisan tenants:seed-realistic --from=50 --to=99 &
docker exec flowsync-app php artisan tenants:seed-realistic --from=100 --to=149 &
docker exec flowsync-app php artisan tenants:verify-realistic --report=/tmp/seed-report.md
```

## 2. Tenant distribution and lifecycle matrix

Subscription state → tenant status: `trialing → trial`, `active → active`, `past_due → suspended`, `expired → expired`,
`canceled → deactivated`. Every (plan × state) pair is generated first; the rest is filled from a weighted pool
(trial 10 · active 20 · expired 7 · suspended 6 · cancelled 7 per 50).

| Category | Plans (all of them) | Notes |
|---|---|---|
| 50 HRMS-only | `hrms-starter`, `hrms-professional`, `hrms-enterprise` — 15 plan×state pairs, 3–4 tenants each | HRMS schema only |
| 50 TMS-only | `tms-starter`, `tms-professional`, `tms-enterprise` — same | TMS schema only |
| 50 HRMS+TMS | 4 bundles (`starter`, `pro`, `business`, `enterprise`) × 5 states = 20, plus 30 tenants on **two per-product subscriptions** drawn from the 9 TMS×HRMS plan pairs | both schemas + the integration set |

Subscription history per tenant (central DB): `trial_started`, `trial_expired`, `subscribed`, `renewed` (one per billing period,
up to six), `plan_changed` (upgrade or downgrade on a third of active tenants), `payment_failed` + `paused` (suspended),
`canceled` (cancelled), `expired` — each with a matching `payments` / `invoices` / `invoice_lines` row for paid plans, and
`usage_metrics` for the last three months.

## 3. Dates stay consistent (`Timeline`)

A trial tenant is 3–11 days old; an active one 3–30 months; an expired, suspended or cancelled tenant has **no activity after the day
it stopped**. Every dated record (joining dates, tasks, comments, work logs, attendance, leave, payslips…) lies inside
`[tenant created, tenant end]`; payroll only covers fully elapsed months after the tenant existed.

## 4. Entitlements

Data is generated only for modules the tenant's *effective plans* include (`TenantLimits::hasModule`): a starter tenant gets
employees, org, onboarding/offboarding and the inbox data it can reach — never leave, payroll or sprints rows it could not open.
`Verifier` fails when a module-gated table has rows without the module. The demo deliberately exceeds small plans' numeric limits
(25–200 users, ~4 000 tasks) so the seeder records a `limits_override` per tenant (the supported mechanism) rather than editing plans.

## 5. Table inventory and seeding order

See the generated report at the end of this file. Dependency order inside a tenant:

```
central: tenants → (provision schema) → subscriptions → subscription_events → payments → invoices → invoice_lines
tenant : roles/permissions/priorities/issue_types/leave_types/… (provisioned catalogs, reused)
         users → role_user → departments → designations → locations → employees → employee_status_history
         [TMS] workspaces → workspace_members → labels → projects → project_members → task_statuses → project_components
               → project_versions → sprints → tasks → task_status_history → task_label/task_component → comments → activities
               → work_logs → timesheets → task_watchers/checklist/views → task_dependencies → notifications
               → automation_rules → automation_runs → sprint_task_events → saved_filters → project_dashboards
         [HRMS] holiday_calendars → holidays → approvals → approval_steps → leave_requests → leave_request_days → leave_balances
               → attendance_days → attendance_punches → expense_claims → expense_claim_items → asset_categories → assets
               → asset_assignments → onboarding_cases → offboarding_cases → resignation_requests → salary_structures
               → employee_salary_structures → payroll_runs → payslips → performance_cycles → performance_goals → check_ins
               → skills → employee_skills
central: tenant_users (login routing) → usage_metrics
```

## 6. Validation

`Verifier` (run by `tenants:verify-realistic`, and on a small slice by `RealisticSeedTest`) checks: tenant/slug uniqueness,
50/50/50 split, every plan, state and tenant status present, product schema matches subscription, lifecycle mapping, user range,
login routing parity, employee ⇔ user parity, acyclic reporting lines, project/task counts per configured range, assignee/reporter
are project members, status/parent/epic/sprint stay inside the project, `completed_at` ⇔ done status, no finished task blocked,
no data after a tenant stopped, work-log overlap and duration, timesheet = work logs, automation run counts, leave overlap and balance
arithmetic, approved leave shows as `leave` in attendance, payslip arithmetic and run totals, expense totals, one active holder per asset,
and module-gated tables empty without their module.

## 7. Final report (run of 2026-10-18 on the Docker dev stack)

**Result:** 150 tenants — 50 HRMS-only, 50 TMS-only, 50 HRMS+TMS — seeded in ≈ 8 minutes with three parallel workers
(3–8 s per tenant); `tenants:verify-realistic` passes every check for all 150; `RealisticSeedTest` passes (2 tests, 767 assertions).
Disk: ≈ 4.5 GB across the 150 tenant databases.

| Central | Rows |
|---|---:|
| tenants (49 active · 34 trial · 24 expired · 21 suspended · 22 deactivated) | 150 |
| subscriptions (30 combined tenants hold one per product) | 180 |
| subscription_events | 853 |
| payments / invoices | 373 / 373 |
| tenant_users (login routing) | 15,820 |

Key volumes: 15,814 users and 10,864 employees (every HRMS-tenant user has an employee); 2,477 projects (20–30 per TMS tenant);
383,919 tasks (150–160 per project); 459k comments; 265k work logs; 1.58M activity rows; 1.1M status-history rows;
7,563 sprints; 2,535 automation rules / 74k runs; 310k attendance days; 8,435 leave requests; 10,717 payslips.

### Rows per table (all tenant databases together; tables with no rows are omitted)

| table | rows |
|---|---:|
| activities | 1,584,621 |
| approval_steps | 12,740 |
| approval_templates | 900 |
| approvals | 12,740 |
| asset_assignments | 19,960 |
| asset_categories | 340 |
| assets | 20,457 |
| attendance_days | 309,948 |
| attendance_punches | 428,048 |
| attendance_shifts | 300 |
| automation_rules | 2,535 |
| automation_runs | 73,976 |
| check_ins | 2,375 |
| comments | 459,244 |
| departments | 953 |
| designations | 6,410 |
| document_types | 1,100 |
| employee_holiday_calendars | 8,903 |
| employee_salary_structures | 5,770 |
| employee_skills | 17,327 |
| employee_status_history | 11,291 |
| employees | 10,864 |
| employment_types | 400 |
| expense_categories | 600 |
| expense_claim_items | 8,094 |
| expense_claims | 4,043 |
| holiday_calendars | 268 |
| holidays | 2,088 |
| hrms_settings | 100 |
| issue_types | 600 |
| labels | 2,712 |
| leave_balances | 17,806 |
| leave_policies | 100 |
| leave_policy_types | 500 |
| leave_request_days | 28,312 |
| leave_requests | 8,435 |
| leave_types | 500 |
| locations | 400 |
| migrations | 10,600 |
| notifications | 86,214 |
| offboarding_cases | 363 |
| onboarding_cases | 547 |
| payroll_runs | 69 |
| payslip_templates | 100 |
| payslips | 10,717 |
| performance_cycles | 61 |
| performance_goals | 16,115 |
| permission_role | 58,650 |
| permissions | 15,900 |
| priorities | 500 |
| project_components | 7,360 |
| project_dashboards | 748 |
| project_members | 32,773 |
| project_roles | 300 |
| project_versions | 6,197 |
| projects | 2,477 |
| resignation_requests | 276 |
| role_user | 21,099 |
| roles | 1,500 |
| salary_components | 600 |
| salary_structure_components | 216 |
| salary_structures | 36 |
| skills | 360 |
| sprint_task_events | 53,202 |
| sprints | 7,563 |
| task_checklist_items | 199,378 |
| task_component | 283,682 |
| task_dependencies | 25,778 |
| task_label | 351,084 |
| task_status_history | 1,125,377 |
| task_statuses | 11,637 |
| task_views | 83,824 |
| task_watchers | 144,196 |
| tasks | 383,919 |
| users | 15,814 |
| work_logs | 264,965 |
| workspace_members | 23,277 |
| workspaces | 339 |

### Not seeded (and why)

- **No such module in the application:** recruitment (job openings, candidates, interviews), training, announcements.
- **Need real files:** `attachments`, `employee_documents`, `document_signatures`, letter/receipt/invoice PDFs.
- **Not generated yet (schema exists):** comp-off, shift rosters/rotations/swaps, loans, letters, surveys/engagement, succession,
  OKRs/development plans, improvement plans, statutory profiles/TDS, regularisation requests, SLA policies, webhooks, API tokens,
  custom fields, saved reports, notification preferences, domain-event outbox, audit logs. Each is gated by its own module and can
  be added as a further `Realistic/*Seeder` without touching the others.
- **Provisioned catalogs are reused, not duplicated:** roles, permissions, priorities, issue types, leave types, expense categories,
  salary components, shifts, employment types.

### Limitations

- Plans' numeric limits are lower than the requested volume (e.g. a starter plan allows 5 users / 500 tasks). The demo records a
  `limits_override` on the tenant so usage stays within its *effective* limits; plan definitions are untouched.
- "Inactive users" exist only where the product models them: HRMS tenants have exited, terminated, on-notice and suspended employees.
  TMS-only tenants have no user status column, so all their users are active.
- A plan has one billing cycle (monthly or annual), so cycles vary by plan, not by tenant.
- Users have no profile photos (the schema has none yet), so avatars show initials.
- Payroll is generated only for fully elapsed months after a tenant existed, so trial tenants have none.
- All logins use one demo password (`config('demo_seed.password')`).
