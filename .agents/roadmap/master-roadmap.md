# FLOWSYNC — MASTER PRODUCT & DEVELOPMENT ROADMAP

**Basis:** code at `9bd01ec` (2026-10-09, branch `development`), requirements in `.agents/functionality.md`.
**Built on, not replacing:** [`verification-report.md`](verification-report.md) (feature-by-feature audit at `d5bc70c`),
[`gap-register.md`](gap-register.md) (G-/H- IDs), [`phase-plan.md`](phase-plan.md) (phases 1-8, shipped),
[`member-access-plan.md`](member-access-plan.md) (scope model, shipped), [`end-to-end-flow.md`](end-to-end-flow.md).
This file adds: a re-verification at HEAD, new gaps (G-58+), the first cleanup audit, the target architecture, and one dependency-ordered roadmap.

**Status legend:** ✅ implemented · 🟡 partial · 🟠 needs hardening · 🔵 reserved/stub · 🔴 missing · ⏸️ deferred by design · ❓ needs runtime verification.
**Priority:** P0 security/data-loss/blocking · P1 enterprise-required · P2 important · P3 enhancement · P4 future. **Size:** S ≤1 day · M 2-4 days · L 1-2 weeks · XL split into slices.

> Method note. Everything below was checked by grep/read at HEAD; nothing was run in a browser and the full test suite was not re-run. Items I could not prove statically are marked ❓.

---

## 1. Executive Summary

- FlowSync is a **modular Laravel 12 + React 19 monolith** with database-per-tenant isolation, a very broad HRMS (P1-P21 shipped) and a solid TMS core. **The architecture is the right one; do not redesign it.** The remaining work is features, wiring, and hardening.
- All 23 "missing" claims in the older register are **still true at HEAD**. The 8 commits since `d5bc70c` only closed items already marked resolved (scheduler, login audit, exited-user login block, watchers, issue-type CRUD, SPA fields).
- **Three new P0s** the earlier audits missed (§21): a frontend route wrapper that very likely renders blank pages, four tenant routes with no authorization, and a tracked `.env.docker` containing secrets.
- The largest product gaps against the brief: **JIRA-class layer** (JQL, capacity, roadmap/timeline views, saved filters — workflow transitions/sprints/epics/automation now shipped), **shared engines** (approval v2, custom fields — event bus/outbound webhooks/API tokens shipped), **HRMS staples** (transfers/promotions, F&F, talent — shifts/rosters/bulk import/leave rollover/doc versioning now shipped), **billing completeness** (dunning now shipped vs invoices+portal), and **HRMS↔TMS depth** (only task-links and work-log derivation exist).
- **RBAC audit (§8A):** the permission/scope/tenant-isolation foundation is strong, but a role manager can grant permissions they do not hold (P0 escalation), system roles are unprotected, role changes are unaudited, the brief's `employee`/`hr_executive`/`finance_admin`/`auditor` roles do not exist, and impersonation has no reason, time-box, read-only mode or action blocklist. Fixed by P0.7 and Phase 1A (R1-R15).
- Roadmap: phases 0-9 plus **Phase 1A** (RBAC hardening), **Phase 10** (core freeze, 2-4 test accounts, end-to-end validation, §37) and **Phase 11** (marketing website, final, §38), ordered by dependency (§29); mandatory development rules in §36. **Do Phase 0 next** (§35).

## 2. Current Product State (delta at HEAD)

Unchanged from `verification-report.md` except: G-1, G-2 (trial half), G-6, G-12, G-27, G-2a/2b/3a, H-7 are resolved (see gap register). Test gate quoted in AGENTS.md is 1572 / 8115 (❓ not re-run in this audit). Scale facts: ~470 routes, ~640 PHP files under `app/`, 162 JS files (~29.6k lines), 41 tenant + 16 system migrations, 19 artisan commands, 94 FormRequests.

## 3. Architecture Verdict — Are We on the Right Path?

| Area | Rating | Why / what must change |
|---|---|---|
| Tenancy (central DB + DB-per-tenant) | **Excellent** | Real isolation, tested, repair-safe provisioning. Keep. Revisit only past ~1,000 tenants (§9). |
| Backend structure | **Good** (was Excellent) | Controller→FormRequest→Policy→Service→Presenter holds, but 19 files exceed the 300-line ceiling (`NotificationService` 963, `TaskService` 639), `routes/web.php` is 1,053 lines, 53 controllers still validate inline. |
| Eventing | **Architectural Risk** | No domain events. Services call `NotificationService` directly; the 3 existing events are UI sync broadcasts. Automation, webhooks and cross-module reactions have nothing to subscribe to. Fix in Phase 2. |
| Authorization | **Needs Improvement** | Policy/scope design is strong, but 4 tenant routes have no check and 2 module keys gate nothing (§21). Scopes stop at own/assigned/all. |
| Entitlements | **Needs Improvement** | `ensure_module` is fail-closed and tested, but `hrms.onboarding/offboarding/documents/compensation/payroll` sit under `hrms.core` only, so a plan cannot actually switch them off. |
| Frontend | **Needs Improvement** | Good IA and deep links; but a probable route-wrapper bug (P0), no JS tests/lint, no top-level error boundary, no code splitting, 83 files hand-roll fetching, 9 files >500 lines. |
| Database | **Good** | Indexed and migration-safe; duplicate goal↔task link tables; `platform_*` tables unused. |
| SaaS billing | **Needs Improvement** | Gateways, quotas, trial expiry good; no invoices/dunning/proration/period-end expiry. |
| Security | **Needs Improvement** | Isolation, signed URLs, throttles good; no 2FA/SSO/CSP, platform RBAC is schema-only, secret file tracked. |
| TMS depth | **Needs Improvement** | Core solid; JIRA layer absent (§6). |
| HRMS depth | **Good** | Very broad; staples missing (§5). |

**Verdict: right path, no redesign.** Required structural changes: introduce the event bus, extend (not replace) the approval engine, add a custom-field engine, split the oversize files.

## 4. Existing Feature Inventory

Authoritative table: `verification-report.md` §1-§16. **Do not rebuild anything marked ✅ there.** Disposition summary:

| Disposition | Items |
|---|---|
| **Keep** | Tenant DB model, provisioning, lifecycle state machine, scoped permission lattice (`PermissionScope`, `TaskScope`, `HrmsScope`), signed tenant-scoped downloads, payment gateway layer, HRMS bounded contexts, notification preferences, deep-link taxonomy |
| **Extend** | `ApprovalService` (modes, delegation, SLA, tenant config), `NotificationService` (split + digest), `TaskService` (transitions, hierarchy), `ReportsController`/analytics (agile metrics), `GlobalSearchController` (saved + JQL-lite), `TenantLimits`/`ensure_module` (unify module keys), `WorkLog*` (timer, billable) |
| **Refactor** | Goal↔task link tables → one; `NotificationService`, `TaskService`, `routes/web.php` splits; duplicated controller helpers (§23) |
| **Harden** | The 4 open routes, `.env.docker`, `ProtectedRoute`, queue health probe, export ZIP cleanup, geofence flag-vs-block |
| **Verify** | `ProtectedRoute` blank-page claim in a browser; `TaskService::update()` vs goal refresh; scoped-permission literals reported "unreferenced" (may be dynamic) |

## 5. HRMS Complete Capability Matrix

Format: status · evidence · action. Scope-lattice and approval notes apply throughout. Items not listed here are ✅ in `verification-report.md`.

| Area | Capability | Status | Action |
|---|---|---|---|
| Core | Directory, profile, PII redaction, hire modes, status lifecycle | ✅ | Keep |
| Core | Custom employee fields, tags | 🔴 | Custom-field engine (P2.6), tags (P5.14) |
| Core | Bulk import / bulk status change | 🔴 | P5.5 |
| Org | Departments/designations/locations | ✅ | Keep |
| Org | Graphical org chart | 🟡 tree list | P5.13 |
| Org | Matrix/dotted reporting, business units, legal entities, cost centers, teams | 🔴 | P5.13 (design: second reporting edge table; BU/entity as typed org nodes) |
| Lifecycle | Transfer, promotion, confirmation, probation workflows | 🟡 columns only | P5.6 reusable *Employee Lifecycle Workflow*: configurable states, dynamic approvers, effective dates, validation, letters, audit, tenant templates |
| Onboarding | Cases, templates, checklists, document requests, reminders | ✅ | Keep |
| Onboarding | Auto-start on `employee.joined`, buddy, e-sign, IT provisioning | 🔴 | Automation (P3.4), e-sign (P5.8) |
| Offboarding | Cases, clearance gate, access block | ✅ | Keep |
| Offboarding | Exit interview, **F&F settlement**, e-sign, experience letter | 🔴 | P5.7-P5.8 |
| Attendance | Punch, windows, regularization, rollup, export | ✅ | Keep |
| Attendance | Geofence/IP: flag-not-block; SPA sends no lat/lng | 🟠 | P5.15 (G-13) |
| Attendance | Break punches, biometric | 🔴 | P5.15 / P9 |
| **Shifts** | Catalog, rosters, swaps, approvals, UI | 🔵 tables + internal use | **P5.1-P5.3** |
| Leave | Types, policies, balances, accrual, approvals, calendar, exemptions | ✅ | Keep; wire `hrms.exemptions` or retire key (P1.14) |
| Leave | Carry-forward/lapse apply, manual adjustment | 🔴 | P5.9 |
| Leave | Approver delegation | 🔴 | Approval v2 (P2.4) |
| Leave | Hourly leave, blackouts | 🔴 | P5.9 |
| Comp-off, holidays | | ✅ | Holiday CSV/ICS import P5.16 |
| Expenses | Claims, receipts, finance step, payroll hook | ✅ | Keep |
| Expenses | Category limits, mileage, advances, project link | 🔴 | P5.11 |
| Compensation | Components, structures, revisions | ✅ | Letters P5.8 |
| Payroll | Runs, calc, lifecycle, payslips | ✅ | Keep |
| Payroll | **Reopen**, bank file, loans/advances, register export, PDF payslips | 🔴 | P5.10, P6.5 |
| Statutory | PF/ESI/PT/TDS/LWF engine, declarations | ✅ | Keep; filing stays ⏸️ (calc → prepare → file → integrate; only the first two exist) |
| Documents | Types, expiry, verification, confidential, signed download | ✅ | Versioning P5.12 |
| Assets | Lifecycle, handover, exit gate | ✅ | Replacement state (minor) P5.16 |
| Performance | Cycles, goals, check-ins, 1:1s, feedback, reviews, evidence | ✅ | Keep; PIP/competencies/calibration tooling P5.18 |
| **Talent** | Skills, matrix, career paths, succession, pools, IDP | 🔵 | **P5.17** |
| Engagement | Surveys, pulse, eNPS, anonymity | ✅ | Keep |
| Analytics | 8 readings, CSV, digests | ✅ | Cross-domain P7 |
| Self service | 10 My* pages, inbox | ✅ | Keep |
| ATS, training/LMS | | ⏸️ | Out of this roadmap (§22) |

## 6. TMS / JIRA Complete Capability Matrix

| Area | Capability | Status | Phase |
|---|---|---|---|
| Workspace/project | CRUD, members, roles, components, versions, issue types | ✅ | Keep |
| Workspace/project | Templates, clone, project health | 🔴 | P4.8 |
| Issues | Core fields, watchers, labels, deps (blocks/related), comments, attachments, activity | ✅ | Keep |
| Issues | Dep types `relates/duplicates/clones` | 🟡 | P4.9 (H-10) |
| Issues | Checklist items inside a task | 🔴 | P4.9 |
| Issues | Clone, move across projects, bulk edit/assign/transition | 🔴 | P4.6 |
| Hierarchy | Initiative→Epic→Story→Task→Sub-task | 🔴 (two levels only) | P4.1 |
| Views | List, Kanban | ✅ | Keep |
| Views | Backlog, scrum board, calendar, timeline/gantt, roadmap, workload, swimlanes, WIP | 🔴 | P4.2-P4.4, P4.10 |
| **Workflow engine** | Transitions, permissions, validators, conditions, post-actions, required fields, SLA | 🔴 | **P3.1-P3.3** |
| Sprints | Create/plan/start/close/carry-over, capacity | 🔴 | P4.2-P4.3 |
| Reports | Distributions, time summaries | ✅ | Keep |
| Reports | Velocity, burndown/burnup, cycle/lead time, throughput, workload, SLA | 🔴 | P4.5 |
| Search | Filter search, global search, command palette | ✅ | Keep |
| Search | Saved filters, shared filters, JQL-lite, `tsvector` | 🔴 | P4.7, P9.7 |
| Dashboards | Personal | ✅ | Team/project/workspace + saved widgets P4.11 |
| Time | Work logs, summaries | ✅ | Timer, billable, timesheet + approval P4.12 |
| Automation | WHEN→IF→THEN | 🔴 | **P3.4-P3.6** |
| Notifications | In-app, email, prefs, watchers | ✅ | Digest/throttle P2.9; push P9 |
| Service management | Request types, queues, SLAs, escalation | 🔴 | **Separate module** `service_desk`, after Phase 4 (§22); reuses workflow + approval + SLA |
| Custom fields | | 🔴 | P2.6 |

## 7. HRMS ↔ TMS Integration Matrix

| Link | Today | Target (phase) | Mode |
|---|---|---|---|
| Employee ↔ User | ✅ `employees.user_id` | Keep | sync |
| Employee ↔ Workspace/Project member | 🔴 nothing auto-adds | Policy-driven auto-membership on `employee.joined` / department / project template (P7.1) | event-driven, async |
| Employee ↔ Manager ↔ task scope | ✅ `ReportsTo` | Keep | read-only |
| Employee ↔ Work log → Attendance | ✅ opt-in nightly `WorkLogDerivation` | Keep; UI toggle exists | async, one-way |
| Employee ↔ Work log → Performance evidence | ✅ | Keep | read-only |
| Goal ↔ Task | 🟠 two tables | One table `hrms_task_links` (P1.10) | bidirectional read, pull sync |
| Goal ↔ Epic/Project | 🔴 | After P4.1: link kind `epic`/`project` (P7.5) | read-only |
| Task completed → goal progress | 🟡 `move()` only | Event `task.completed` consumer (P2.1, P1.11) | event-driven |
| Onboarding/offboarding checklist → task | ✅ pull-only | Auto-create on case open via automation (P3.6) | event-driven |
| Leave → task availability | 🔴 | Assign-time warning + capacity (P7.2) | sync read |
| Leave approved → adjust tasks | 🔴 | Automation action (P3.6) | event-driven |
| Expense ↔ Project/Task | 🔴 | Nullable `project_id`/`task_id` on claim items (P5.11) | sync |
| Attendance ↔ Work logs | ✅ (see above) | | |
| Payroll ↔ approved work | 🔴 | Optional hourly/billable component fed by approved timesheets (P7.4) | async, read-only |
| Workload ↔ capacity | 🔴 | Capacity = shift/leave/holiday minus planned (P7.3) | read model |
| Asset ↔ employee | ✅ | Keep | |
| Approvals / notifications / audit | 🟡 HRMS-only approvals; shared notify; two audit stores | One approval engine for TMS gates (P3.3); audit unification (P2.2) | |

Rule: cross-domain **reads** use read models built per tenant (§15); never cross-tenant joins inside tenant DBs.

## 8. Roles & Permissions Architecture

**Keep** `resource.action[_scope]` with `own ⊂ assigned ⊂ all` plus `.manage` (outside the lattice), project roles, custom roles, `tenants:scope-grants` migration pattern.

**Extend, only where a concrete need exists:**
1. **`team`/`department`/`location` scopes** — add as *resolvers* behind `HrmsScope`/`TaskScope` (`ReportsTo` already is the `assigned` resolver). Add a scope only when a shipped screen needs it (first candidates: location-scoped HR executives, department heads). Each new scope = catalog suffix + resolver + policy test row; never widen existing grants (use the dry-run/`--force` pattern).
2. **Platform personas** (Billing Admin, Support, Auditor, Operator): implement on the existing `platform_roles` tables (user decision: build, not delete) with `PlatformPermission` checks replacing the single `super_admin` gate route-by-route (P8.3). `is_super_admin` stays the break-glass role.
3. **Deny rules / inheritance:** not needed now. Document the grant-only decision; revisit if custom roles demand it.
4. **Custom-role UI**: group by domain (done); add scope badges (P1.x FE).

**Evaluation order (document and test):** platform gate → tenant status → subscription module (`ensure_module`, fail-closed) → tenant permission (`permission:`) → record policy (`Policy` + `TaskScope`/`HrmsScope`) → field redaction. Frontend `can()/hasModule()` only mirrors; every check repeats server-side.

### 8A. RBAC & authorization audit (added 2026-10-09)

Verified by reading `RoleController`, `UserController::updateRoles`, `ImpersonationController`, `EnsurePermission`, `config/permissions.php`, `routes/web.php` and the test list. Items I could not prove statically are ❓.

**Already complete (keep, do not rebuild):** tenant-scoped permission catalog (~99 slugs) with `own ⊂ assigned ⊂ all` lattice and `.manage`; config-selector default roles; custom tenant roles (create/edit, `Roles.jsx` with domain-grouped grid); project roles (35 slugs, scope-aware, system roles protected); row-scoping in `TaskScope`/`HrmsScope`/`ReportsTo` with per-record policies; fail-closed `EnsureModule` + `X-Module-Reason`; physical DB-per-tenant isolation with `SwitchTenant`/`SetTenantContext`/`EnsureTenantContext`; impersonation start/stop with `impersonation_logs` (IP, start/end), SA-cannot-be-impersonated and serviceable-tenant guards, `EnsurePermission` scoped to the target tenant while impersonating; last-owner / default-user protections; signed tenant-scoped downloads; frontend mirrors (`can/hasModule/check`, `ProtectedRoute`); tests: `PermissionTest`, `ScopePermissionTest`, `MemberAccessMatrixTest`, `MemberIsolationTest`, `HrmsScopeAccessTest`, `TaskScopeAccessTest`, `ProjectRoleScopeTest`, `ModuleGateTest`, `TenantContextMiddlewareTest`, `SecurityRegressionTest`, `BroadcastingChannelAuthTest`, `ExitedUserAuthTest`.

**Gaps and weaknesses found:**

| ID | Pri | Finding | Evidence | Action |
|---|---|---|---|---|
| RB-1 | **P0** | **Privilege escalation.** A holder of `roles.manage` can create/edit a role with *any* permission (`resolvePermissionIds` only checks the ids exist), and a holder of `users.manage` can assign *any* role, including `admin`, to anyone including themselves. No "cannot grant what you do not hold" rule. | `RoleController::store/update`, `UserController::updateRoles` (no ceiling check) | R1: grant-ceiling rule + only admins may assign/modify `admin` |
| RB-2 | P1 | **System roles unprotected and ambiguous.** `roles` has no `is_system`/`protected` flag; `PUT roles/{role}` can edit `admin`/`editor`/`viewer`/`manager`/`hr_manager`/`payroll_manager`. Meanwhile `tenants:provision` re-`sync()`s config-listed roles, so tenant edits to a system role can be silently overwritten on repair (the project-role side already solved this with union-add). | `RoleController`, `TenantProvisioner::seed` | R2: flag system roles; make them read-only or clone-to-customise; provisioning union-adds only |
| RB-3 | P1 | **No tenant-role delete / no "role in use" handling**; roles accumulate and a deleted-permission edge is undefined. Project roles do have delete. | no `DELETE roles/{role}` route | R2 |
| RB-4 | P1 | **Role, permission and user-role changes are not audited.** No audit write in `RoleController` or `UserController::updateRoles` (platform audit has none for tenants; HRMS audit is HRMS-only). | both controllers | R3: audit `role.created/updated/deleted`, `user.roles_changed` with before/after |
| RB-5 | P1 | **Role vocabulary does not match the brief.** Defaults are `admin/editor/viewer/manager/hr_manager/payroll_manager`. There is no `employee` (self-service) role — `editor`/`viewer` are legacy task-era names that double as employees — and no `team_lead`, `hr_executive`, `finance_admin`, `auditor`, or `tenant_owner` distinct from admin. | `config/permissions.php` roles | R4: add `employee`, `hr_executive`, `finance_admin`, `auditor`; keep legacy slugs as aliases; backfill by repair, never re-granting existing users |
| RB-6 | P1 | **Authorization by role name** in 6 places (`hasRole('admin')`: subscription mutations, billing, onboarding, user default rules). Custom roles can never be delegated these abilities, and the catalog says one thing while code does another. `billing.manage` exists but is not the only gate. | `MySubscriptionController`, `BillingController`, `UserController` | R5: replace with permissions (`billing.manage`, `tenant.manage`), admin keeps via `*` |
| RB-7 | P2 | **No role inheritance and no deny rules** (only config-time `!selector` subtraction). Acceptable; the lattice is the only implicit inheritance. | `PermissionSelector` | R6: decision = *no runtime inheritance*; add **clone role** and **role templates** instead; document precedence (grant-only union) |
| RB-8 | P1 | **Platform side is one flag** (`is_super_admin`); `platform_roles/permissions` unused. | §23, G-19/H-19 | P8.3 (shipped — personas on `platform_roles`, break-glass `is_super_admin` kept) |
| RB-9 | P1 | **Impersonation controls are thin**: start/stop + IP log + throttle only. Missing: mandatory reason/ticket; automatic time-box and server-side expiry (an abandoned session never writes `ended_at`); read-only mode; a blocklist of sensitive actions while impersonating (role/user changes, billing, password change, exports, delete); tenant-visible notice; per-action attribution (`impersonator_id` on audit/activity rows ❓ verify); alert on impersonating a tenant owner. | `ImpersonationController`, `ImpersonationLog` | R7-R8 (P8.4 consent sessions builds on these) |
| RB-10 | **P0** | **Open routes without authorization** (G-59) and no automated route-audit test. | §21 | P0.2 + R9 |
| RB-11 | P2 | **Global reads ignore row scope** (`search/tasks`, dashboard, reports use project membership only — H-13): a scoped project role sees counts/rows its board would refuse. | `ScopesVisibleTasks` | R10 |
| RB-12 | P2 | **Approve action has no catalog permission**: approvals authorise by step assignment (policy decides from the step); no tenant-admin override or "approve on behalf" with audit ❓. | `ApprovalStep::canDecide`-style checks | R11 (ties to approvals v2, P2.4) |
| RB-13 | P2 | **Frontend permission strings are scattered** (~80 inline `can()` literals, bare vs `permission:` forms); a typo hides UI silently. Wrapper bug G-58. | frontend audit | R12: generated `permissions.js` constants from the catalog + lint rule |
| RB-14 | P2 | **Manager/Team Lead mapping undefined.** `manager` role = own + assigned scopes for HRMS and `workspaces.view`; project/task management comes from **project roles** (lead/developer/viewer), not the tenant role. This is correct but undocumented, so "Manager can manage their projects" is satisfied only if the person is also project `lead`. | `config/permissions.php`, `config/project_roles.php` | R4 documents the mapping table below; optional auto-lead on project create already exists |
| RB-15 | P3 | **Super Admin rights over tenant data** are broad by design (non-impersonating SA is blocked from domain routes by `EnsureTenantContext`, good) but SA global search fans out into tenant DBs. Keep; add audit on SA cross-tenant search ❓. | `GlobalSearchController` | R13 |
| RB-16 | P2 | **No effective-permission explainer**: admins cannot ask "why can X not do Y?" (role → permission → module → scope). `/403` names the missing grant only. | — | R14 (admin diagnostic endpoint + UI) |

**Role catalogue vs brief (target):**

| Brief role | Today | Target mapping |
|---|---|---|
| Super Admin | `users.is_super_admin` (system DB) | keep; personas via P8.3; break-glass = `is_super_admin` |
| Tenant Admin | `admin` (`*`), default user protected | keep as `admin`; "Tenant Owner" = the protected default user |
| HR | `hr_manager` (all `hrms.*` minus payroll/compensation manage) | keep; add `hr_executive` (HR without delete/approve-final/sensitive), `finance_admin` (expenses + payroll read), `auditor` (read-only all, no sensitive reveal) |
| Manager / Team Lead | `manager` (own + assigned) + project role `lead` | keep; document; no new role needed |
| Employee / User | `editor`, `viewer` (self-service `_own` reads) | add `employee` as the named default for new users; `editor`/`viewer` stay for TMS-flavoured accounts |
| Custom | `POST/PUT roles` | add clone, delete-with-reassign, grant ceiling, audit |

**Authorization matrix (target; "today" differs only where stated).** Every cell is enforced server-side (route middleware → module → policy → row scope); the SPA only mirrors.

| Resource | Super Admin | Tenant Admin | HR (`hr_manager`) | Manager | Employee | Custom role |
|---|---|---|---|---|---|---|
| Tenants, plans, features, platform settings, CMS | full (`super_admin`) | none (own profile/subscription only; after P0.2 admin-gated) | none | none | none | never |
| Tenant users & roles | none directly; impersonate | full (`users.manage`, `roles.manage`) with grant ceiling (R1) | `users.view` | `users.view` | none | per grant, ceiling-limited |
| Subscription & billing | all tenants | view/switch/cancel/renew (`billing.*`, R5) | none | none | none | `billing.view/manage` |
| Employees (HRMS) | none (impersonate) | all | all; sensitive via `view_sensitive` | direct reports (`_assigned`) | self (masked PII unless granted) | per grant |
| Attendance, leave, comp-off, expenses | none | all | all | view + approve for reports | own: create/view/withdraw | per grant + scope |
| Payroll, compensation, statutory | none | all (`*`) | **no** (subtracted) | none | own payslip only | `payroll_manager` pattern |
| Performance | none | all | all | reports' goals/check-ins/reviews | own + assigned reviews | per grant |
| Documents (confidential) | none | all | non-confidential unless `view_sensitive` | none | own | per grant |
| Workspaces / projects | none | all (`workspaces.manage` bypass) | by membership | by membership; manage if project `lead` | by membership | by membership + project role |
| Tasks | none | all | by membership + `tasks.*` scope | by project role scope | own/assigned per project role | project role |
| Approvals (leave, expense, regularization, comp-off, revision) | none | decide only if step approver (admin override: R11) | step approver | step approver (manager step) | submit/withdraw own | step approver |
| Audit logs | platform feed | HRMS audit by `hrms.audit.view`; tenant-wide audit feed to be added (R3) | per grant | none | none | per grant |
| Export / backup | per tenant via SA | `export.full` module + `billing.view` (align, P0.6) | none | none | none | per grant |

**Impersonation security controls (target for R7-R8):** mandatory reason (+ optional ticket id) stored on the log; hard time-box (default 30 min, renewable, server-checked on every request); `ended_at` written on expiry/logout via scheduled sweeper; read-only default with explicit "allow changes" step-up; blocked actions while impersonating (role/user/permission edits, password/email change, billing, export, tenant delete, further impersonation); every audit/activity row records `impersonator_id`; tenant admins get an in-app notice and can list past sessions; SA cannot impersonate other SAs (exists) or a suspended/deactivated tenant (exists); throttle stays.

**Authorization test plan (extends the existing suites):**
1. **Route audit test** — enumerate `route:list`; every tenant route must have `permission:`/policy `authorize`/documented self-scope allowlist; fails on new unguarded routes.
2. **Generated matrix** — Tenant × Plan × Role × Permission × Scope × Module for each domain (extends `MemberAccessMatrixTest`): expected status per cell from the catalog, not hand-written.
3. **Negative suite** — every mutating endpoint called as: unauthenticated, wrong-tenant user, no-permission user, scope-too-narrow user, plan-without-module → 401/403/404 as defined.
4. **Escalation suite** (RB-1): role editor cannot grant ungranted permissions; user manager cannot assign `admin` or demote the default user; self-promotion refused.
5. **Cross-tenant suite** — two tenants with colliding local ids; session of A cannot read/write B via any route-model-bound id, signed URL tamper, channel auth, queue job, export, global search; SA non-impersonating blocked from domain routes.
6. **Impersonation suite** — time-box expiry, read-only enforcement, blocked actions, audit attribution, stop on expiry.
7. **Frontend suite** (Vitest) — route gates render/redirect for each permission+module combination; nav items match route gates.
8. **Unauthorized-access attempt log** — 401/403 spikes counted in metrics (P2.2/P9) for alerting.

## 9. Tenant Architecture

Keep central `system` DB + one PostgreSQL DB per tenant. Assessment by size: 10-100 tenants fine (current: 102 dev tenants); ~1,000 needs PgBouncer in transaction-pooling mode, migration fan-out as queued batches with per-tenant status and a version table, backups sharded and staggered; ~10,000+ needs multiple PG clusters with a `tenants.cluster` routing column (the manager already reads per-tenant host/port), and a rolling-migration controller; 100,000 is out of scope (consider schema-per-tenant tiers for small plans). Gaps: real restore command (H-14), clone (G-19), hard purge/erasure (G-55, needs legal policy), cross-tenant analytics stays a capped fan-out until the warehouse threshold (≥1,000 tenants or report latency >5s). Jobs already carry tenant context (`SwitchesTenantConnectionForQueuedJobs`) — every new job/listener must pass the existing tenant-stamping test.

## 10. Subscription & Entitlement Architecture

Chain: `Subscription → Plan → Module → Feature → Capability → Permission → Scope`.
- **Where decided:** module = `ensure_module` + `TenantLimits::hasModule` (server) mirrored by `user.modules`; permission = `EnsurePermission`; scope/record = policy. The frontend mirror reads only `/auth/me`.
- **Defect to fix (P1.14):** module catalog and routes disagree. Rule: *every module key has ≥1 `ensure_module` group or is removed; every route group names its module.* Add a test that diffs `config/subscriptions.php` against `routes/web.php`.
- **Features** below module level (e.g. `attendance.remote_punch`) already exist as `hrms.attendance.remote`; keep dotted keys, no new table.
- **Commercial packaging (§27)** drives which keys are in which plan; unlimited-when-no-subscription stays (demo tenants).
- **Missing:** invoices, dunning, grace, proration, period-end expiry, overage policy, grandfathered plans (P6).

## 11. Workflow & Approval Architecture

Existing `ApprovalService` is sequential-only with approver types `role|user|manager|department_head`; `approvals.due_at` is unused. **Extend to v2 (P2.4):** step `mode` (`sequential|parallel_any|parallel_all`), conditions on request payload (amount/days/department), delegation records (`approval_delegations`: from, to, window), SLA via `due_at` + reminder/escalation scheduler, resubmission, tenant-editable chain templates (`approval_templates` per domain with defaults from today's hard-coded chains so behaviour is unchanged on migrate). One engine serves leave, expense, regularization, comp-off, salary revision, document, offboarding, payroll (HRMS today) **plus** TMS transition gates and change requests (P3.3).

TMS **workflow engine** is a separate concern from approvals (P3.1): per-project (or scheme-level) `status_transitions(from, to, permission, validators[], post_actions[], requires_approval)`. Default scheme = "any→any" so existing projects are unchanged; enforcement in `TaskService::update/move`; transition history table feeds cycle-time metrics.

## 12. Automation Architecture

`event → conditions → actions`, stored per tenant: `automation_rules`, `automation_runs` (idempotency key = rule+event id). Dispatcher is a queued listener on the domain event bus (P2.1) preserving tenant context. Starter triggers: `task.created/assigned/status_changed/overdue`, `employee.created/joined/offboarded`, `leave.approved`, `expense.approved`, `payroll.completed`. Actions: create task, assign, change status, notify, send email, create approval, create checklist, update field, webhook. Time-based triggers (overdue, due soon) run from the scheduler. Quotas: `automation_executions` metered via `usage_metrics`. Loop guard: depth limit + per-run actor `automation`.

## 13. Notification Architecture

Keep in-app + email + prefs + deep links. Split `NotificationService` by event family behind a notifier interface; consume domain events instead of being called from controllers. Add: digest/batch/throttle/dedup (`notification_digests`, reuse `hrms_report_schedules` pattern), tenant policy layer (tenant can disable a channel/event), template table with locale (G-43), push-ready channel interface (web push later), webhook channel via outbound webhooks.

## 14. Search Architecture

Keep `GlobalSearchController` (permission- and tenant-aware). Phases: saved filters (`saved_filters`: owner, visibility private/project/workspace/tenant, JSON criteria) → JQL-lite parser (field/operator/value, `AND/OR`, `currentUser()`, compiles to the same `filteredQuery` builder so scoping stays in one place — never string-concatenated SQL) → per-tenant Postgres `tsvector` + `pg_trgm` indexes → pluggable `SearchIndex` interface for a future OpenSearch move. Extend entity coverage (documents, comments, assets, expenses) only behind the owning module's policy.

## 15. Reporting & Analytics Architecture

Tiers: operational (live queries) → reporting (per-tenant summary tables refreshed by jobs: `daily_task_stats`, `daily_headcount`, `daily_utilization`) → cross-tenant analytics (capped fan-out today) → warehouse (deferred until threshold in §9). Immediate fix: `ReportsController` must aggregate in SQL, not load every task. Agile metrics need `task_status_history` (from the transition engine). Cross-domain reports (utilization, attendance vs delivery, cost) read the summary tables.

## 16. API & Integration Architecture

Add Sanctum personal access tokens with ability scopes mapped to permission slugs (P2.7), `/api/v1` prefix for token routes only (the SPA keeps session routes), idempotency-key middleware for POSTs, per-token rate limits, `integration_logs`. Outbound webhooks (P2.8): `webhook_endpoints` (secret, events[]), signed HMAC payloads, retry with backoff, delivery log, SSRF guard (block private ranges, DNS-pin). Gate with re-added `api` and `webhooks` modules *together with* the gating routes. Integration tiers: MVP — Slack/Teams notify, Google/Microsoft SSO; Phase 2 — GitHub/GitLab link, calendar sync; Enterprise — SAML, SCIM, payroll/accounting, biometric; Future — Zoom, Jira import.

## 17. Security Architecture

Mitigations and tests per threat (all must have a test before the owning phase closes):

| Threat | Current | Action |
|---|---|---|
| Cross-tenant / IDOR | strong; isolation harness | add a generated route×tenant matrix test (P9.9) |
| Missing authz on routes | **4 open routes** | P0.2 + a route-audit test that fails when a tenant route has neither `permission:` nor a documented self-scope allowlist |
| Privilege escalation via roles | guarded | keep; add scope-widening test for new scopes |
| Subscription bypass | fail-closed | module-key diff test (P1.14) |
| Signed URL abuse | tenant+actor in signature | keep, add expiry/replay tests for new downloads |
| File upload | MIME allowlist, SVG removed | add AV hook point, size per plan |
| XSS/CSRF/SQLi | Laravel defaults, bound params | CSP shipped report-only (P8.5); lint rule against raw SQL interpolation |
| SSRF/webhook abuse | n/a yet | built into P2.8 |
| Replay | payment webhooks guarded | same for outbound consumers |
| Session / token theft | DB sessions | 2FA shipped (P8.1), token abilities+expiry |
| Impersonation abuse | logged, guarded | consent-based support sessions shipped (P8.4) |
| Secrets in repo | **`.env.docker` tracked** | P0.3 |
| Audit tampering | append-only HRMS audit; platform audit | hash chain + tombstoned retention shipped (P8.6) |

## 18. Data Lifecycle

Pattern per entity: `create → active → archived/suspended → soft-deleted → retention window → purge`. Today: tenant soft delete ✅, hard purge 🔴; employee terminate ✅; HRMS retention command ✅ (audit exempt); TMS retention 🔴; export ZIPs never cleaned 🟠. Roadmap: retention policy table per entity (default values require a **legal decision** — flagged in §33), purge jobs that are tenant-aware and audited, GDPR erasure workflow (anonymise rather than delete where payroll/statutory law requires retention), export cleanup (P1.5).

## 19. Scalability Architecture

Thresholds to act on (measure first): task list p95 > 300 ms at 1M tasks → partial indexes + keyset pagination; attendance/work-log tables > 50M rows → monthly partitioning; queue backlog > 5 min → separate queues per domain + Horizon-style supervision; PG connections > 70% → PgBouncer. Redis for cache/queue/session when the DB driver becomes the bottleneck (currently `database`). Immediate: fix the `NotificationService` N+1 (12 sites) and `ReportsController` in-memory aggregation; add lazy-loaded SPA routes.

## 20. Testing Strategy

Today: PHP feature tests only (isolated per-tenant sqlite), PG path exercised manually. Add: (1) **Vitest + React Testing Library** with a route-gate smoke test (would have caught the `ProtectedRoute` bug); (2) **Playwright** E2E for login→dashboard, task flow, leave flow, billing; (3) a **PG CI lane** for tenant migrations (the sqlite/PG divergence is a documented foot-gun); (4) **matrix test** Tenant × Plan × Role × Permission × Scope × Module generated from catalogs (extends `MemberAccessMatrixTest`); (5) route-audit test (§17); (6) module-catalog diff test; (7) migration up/down + backfill tests per phase; (8) load tests (k6) on board, search, payroll run, attendance rollup; (9) backup/restore drill in CI.

## 21. Gap Register (new items; older IDs stay in `gap-register.md`)

| ID | Pri | Domain | Gap | Impact | Recommendation |
|---|---|---|---|---|---|
| G-58 | **P0** | Frontend | `ProtectedRoute` returns only `<Outlet/>`; ~21 wrapper-form routes (`/subscription`, `/hrms/employees`, `/hrms/org`, `/hrms/leave`, `/hrms/payroll`, …) likely render blank. Code-verified; ❓ runtime | Core pages unreachable; no JS tests to notice | P0.1 |
| G-59 | **P0** | Security | `PUT tenant/profile`, `PUT onboarding/step`, `POST onboarding/complete`, `GET billing/history` have no permission check | Any tenant user edits company profile / completes wizard / reads payments | P0.2 |
| G-60 | **P0** | Security | `.env.docker` tracked (APP_KEY, DB and Reverb secrets) | Credential exposure | P0.3 |
| G-61 | P1 | Eventing | No domain event bus | Blocks automation, webhooks, loose coupling | P2.1 |
| G-62 | P1 | Entitlement | Module keys that gate nothing (`hrms.shifts/talent/inbox/exemptions`) and sub-modules under `hrms.core` only | Plans sell what they cannot switch off | P1.14 |
| G-63 | P1 | Approvals | Chains hard-coded; tenant admin cannot configure | Brief §13 | P2.4 |
| G-64 | P2 | TMS | No clone/move-between-projects/bulk ops, no templates (task checklists shipped, P4.9a) | Daily-driver gaps | P4.6, P4.8, P4.9 |
| G-65 | P2 | TMS | No timer/timesheet/billable/approval | Time→payroll link impossible | P4.12 |
| G-66 | P2 | Platform | No runtime feature flags / rollout rules | Safe rollout of new engines | P8.7 (closed) |
| G-67 | P2 | Platform | No consent-based support access; impersonation is the only path | Enterprise trust | P8.4 (closed) |
| G-68 | P2 | Org | No matrix reporting, BU/legal entity/cost center, employee tags | Enterprise modelling | P5.13-P5.14 |
| G-69 | P2 | Integration | No employee→project/workspace auto-membership, leave→task availability, expense→project, workload | HRMS↔TMS thesis unproven | Phase 7 |
| G-70 | P2 | Service mgmt | No request types/queues/SLAs | Brief §6 | separate module (§22) |
| G-71 | P3 | i18n | No `lang/`, locale stored but unused | Localisation | P9.8 |
| G-72 | P3 | Data | No TMS retention policy | Compliance | P9.6 |
| G-73 | P3 | Quality | Goal-completion refresh only in `move()`, not `update()` (❓ intended?) | Stale goal progress | P1.11 |
| G-74 | **P0** | RBAC | Privilege escalation via `roles.manage` / `users.manage` (RB-1) | Any role manager can mint admin-equivalent access | R1 |
| G-75 | P1 | RBAC | System roles unprotected / overwritten by provision sync; no role delete (RB-2/3) | Silent permission drift, lockout risk | R2 |
| G-76 | P1 | RBAC | Role/permission/user-role changes not audited (RB-4) | Compliance | R3 |
| G-77 | P1 | RBAC | Brief's roles missing (`employee`, `hr_executive`, `finance_admin`, `auditor`) (RB-5) | Role model does not match product vocabulary | R4 |
| G-78 | P1 | RBAC | Role-name checks bypass catalog (RB-6) | Cannot delegate billing/tenant admin | R5 |
| G-79 | P1 | RBAC | Impersonation lacks reason, time-box, read-only, blocklist, attribution (RB-9) | Highest-risk platform capability | R7-R8 |
| G-80 | P2 | Marketing | Public site is a 4-page CMS with project-management-only copy, no screenshots, pricing block, or lead capture | Cannot market HRMS+TMS | Phase 11 |
| G-81 | P2 | QA | No documented end-to-end validation with dedicated accounts across SA/tenant/plan/role | Release confidence | Phase 10 |
| G-82 | P3 | RBAC | No effective-permission explainer, global reads ignore row scope, no generated permission constants (RB-11/13/16) | Support burden, scoped-role leakage | R10, R12, R14 |
| G-83 | P0 | Export | Full data export crashed: `EmployeeStatus` enum written to CSV (FB-10) | Export unusable for HRMS tenants | **Fixed** (`ExportService::csvCell`) |
| G-84 | P0 | TMS | Kanban **Move** returns 403 for some users; board sometimes renders empty until Board→List→Board (FB-2) | Core TMS flow broken | FB-2a, FB-2b |
| G-85 | P1 | Access | Losing workspace membership does not cut every workspace-scoped action or hide its projects (FB-2) | Access leak after removal | FB-2c |
| G-86 | P1 | Tenancy | Tenant created from the SA panel is not gated on required fields; DB provisioned before the profile is complete; no multi-step form; not in sync with self-registration; no default user step (FB-3) | Half-configured tenants go live | FB-3 |
| G-87 | P1 | Billing | Trial without a card; no Stripe wiring beyond checkout skeleton (FB-3, FB-6) | Revenue leakage, untested payments | FB-3, FB-6 |
| G-88 | P1 | Entitlement | HRMS and TMS share one plan ladder and one tenant DB; no per-product plan, toggle or table set (FB-4) | Cannot sell products separately | FB-4 (owner decision) |
| G-89 | P2 | Platform | No customer-support ticketing (FB-7); SA panel lacks a subscriptions overview (FB-5) | Support runs off-platform | FB-7, FB-5 |
| G-90 | P2 | Users | Inline user edit, no CSV import, no ceiling-checked import, weak Roles UI (FB-8) | Onboarding large teams is manual | FB-8 |
| G-91 | P1 | Frontend | Tenant admin sees a blank `/subscription`; HRMS pages hard to find (FB-1, FB-9) | Likely the P0.1 `ProtectedRoute` bug; needs browser confirmation | FB-1, FB-9 |

## 22. Deferred Features

| Feature | Why deferred | Build? | Priority | Dependencies |
|---|---|---|---|---|
| ATS / recruitment / job postings / applicant portal | Not in HRMS plan; separate persona | Yes, after Phase 5, as its own module | P3 | workflow engine, custom fields, e-sign |
| Training / LMS / certifications | Out of plan | Later; start as "certification tracking" on documents | P4 | talent (P5.17) |
| Statutory filing (challan/returns/Form 16) and filing integrations | Calc→prepare→file split; high risk | Preparation exports P3, direct filing P4 | P3/P4 | payroll reopen, bank file |
| Payroll/bank/accounting integrations | Needs API platform | After P2.7-P2.8 | P3 | API tokens, webhooks |
| Service desk | Distinct module | Yes, after Phase 4 | P3 | workflow, SLA, custom fields |
| Data warehouse / OLAP | Below threshold | When §9 threshold hit | P4 | summary tables |
| Microservices | Rule 21 | No | — | — |
| Biometric devices, push | Hardware / client | After PWA | P4 | API tokens |

## 23. Technical Debt Register (cleanup audit)

**Backend — safe now**
| Item | Where | Action |
|---|---|---|
| Unused imports (11) | `DocumentUpload`, `ExportService`, `DepartmentRequest`, `EmployeeUpdateRequest`, `AnalyticsController`; tests: `HrmsOrgApiTest`, `TaskScopeAccessTest`, `SecurityRegressionTest`, `HrmsEntitlementTest`, `HrmsDocumentTablesTest` | Pint `no_unused_imports` |
| Dead scopes | `Employee::scopeManagedBy`, `HrmsAuditLog::scopeForAction` | delete |
| Unused relations/helpers (grep `with()`/`load()` strings first) | `ApprovalStep::approverUser`, `ExitClearance::clearedBy`, `ImpersonationLog::impersonatedUser`, `Tenant::impersonationLogs`, `PayrollRun::initiator`, `ExpenseClaim::paidInRun`, `ExpenseCategory::payrollComponent`, `AttendanceDay::regularizedBy`, `User::watchedTasks`; `ApproverSpec::acceptsRole/fromArray`, `OffboardingService::buildChecklist`, `Department::directHeadcount`, `Task::isWatchedBy/remainingMinutes`, `SubscriptionPlan::limitsFor`, several enum/state helpers | delete after grep |
| Unreferenced permissions/config (granted to roles but checked nowhere literally; confirm no dynamic use) | `content.manage`, `settings.view`, `config/hrms.php shift_patterns` (used by P5.1), `config/services.php` postmark/resend/ses/slack boilerplate | remove or wire |
| `platform_roles/permissions` | models + tables | **keep — used by P8.3** (user decision) |

**Backend — refactor**
| Item | Action |
|---|---|
| Goal↔task duplicate tables (`goal_task_links` vs `hrms_task_links`) | one table + data migration + drop (P1.10) |
| `seesAll()` ×4 perf controllers; `clean()` ×9; `range()` ×2; `uniqueSlug` ×2; `currentTenant` ×4; `snapshot()` ×19; `per_page` rule ×10; escape-aware `LIKE` ×7 | shared traits/helpers (P1.8) |
| 53 controllers validate inline | move to FormRequests opportunistically when touched (do not mass-rewrite) |
| 19 files > 300 lines; `routes/web.php` 1,053 lines | split (P1.6-P1.7) |
| Perf: 12 `User::find` in loops (`NotificationService`), `ReportsController::33` loads all tasks, per-row updates in `PayrollService`/`PerformanceService` | fix (P1.3-P1.4) |
| `api/api/exports/...` route prefix (`routes/web.php:~1012`) | fix with signed-route test (P0.4) |

**Frontend**
| Item | Action |
|---|---|
| 7 unused-import files: `MySurvey`, `Performance`, `PerformanceCycleDetail`, `Expenses` (check if modal should be wired), `Engagement`, `MyPerformance` (check if `CheckInComposer` is a missing feature), `Employees` | remove / wire (P1.12) |
| No ESLint/Prettier/Vitest | add (P1.12) |
| 35 `window.confirm` | `ConfirmDialog` |
| Duplicated `Tabs` ×2 + ad-hoc tab bars, 8 status colour maps, ~20 files with raw date formatting, `ROLE_LABELS` ×3 | shared primitives |
| 83 files with bespoke fetching; 11 abort guards | `useResource`/`usePaginated` |
| ~27 hard-coded enum mirrors | serve from `/meta` endpoint or generated module |
| 9 files > 500 lines (`ProjectDetail` 1,159; `Leave` 686; `Tenants` 651; `WorkspaceDetail` 644 …) | split per tab |
| No top-level error boundary; no `React.lazy`; 964 hard-coded gray classes; raw tables without overflow | boundary, lazy routes, theme tokens, `ui/Table` everywhere |
| `export` route lacks `billing.view` that the nav item requires | align gate |

## 24. Recommended Target Architecture

```
Platform
├── Identity (users, SSO, 2FA, tokens)      ├── Tenancy (routing, provisioning, lifecycle)
├── Billing (plans, subs, invoices, dunning) ├── Entitlements (modules, limits, flags)
├── Events (domain event bus)  ← NEW         ├── Audit (platform + HRMS, one writer contract)
├── Workflow (approvals v2 + transitions)    ├── Automation (rules, runs)   ← NEW
├── Notifications (notifiers, digest)        ├── Files (signed, tenant-scoped)
├── Search (saved, JQL-lite, index)          ├── Reporting (summary tables)
├── CustomFields  ← NEW                      └── API (tokens, webhooks)  ← NEW
HRMS: People · Organization · Lifecycle · Attendance · Shifts · Leave · Expenses · Compensation · Payroll · Statutory · Performance · Talent · Engagement · Documents · Assets · Analytics
TMS:  Workspaces · Projects · Issues · Workflow · Backlog/Sprints · Boards/Views · Roadmaps · Reports · Time
Service Desk (later, separate module)
```
Modular monolith stays. New folders follow the existing folder-per-context rule (`app/Services/<Context>/`).

## 25. Database Architecture (additions only)

**System DB:** `invoices`, `invoice_lines`, `dunning_attempts`, `feature_flags`, `flag_overrides`, `support_sessions`, `api_clients` (if OAuth), `webhook_deliveries_central` (optional); existing `platform_roles/permissions` get seeders and pivots in use.
**Tenant DB:** `domain_events` (outbox), `automation_rules`, `automation_runs`, `status_transitions`, `task_status_history`, `approval_templates`, `approval_delegations`, `custom_field_definitions`, `custom_field_values` (or JSONB `custom` column + definitions), `saved_filters`, `sprints`, `sprint_tasks`, `task_checklists`, `project_templates`, `webhook_endpoints`, `webhook_deliveries`, `personal_access_tokens`, `employee_tags`, `reporting_edges` (matrix), `org_units`, `employee_transfers`/`employee_lifecycle_events`, `shifts` roster tables (exist), `final_settlements`, `exit_interviews`, `loans`, `skills`, `employee_skills`, `career_paths`, `succession_plans`, `talent_pools`, `daily_*` summary tables. Each ships with a repair-safe migration and a `tenants:provision` backfill; indexes named in the owning task.

## 26. Event Architecture

Outbox pattern: services write a `domain_events` row in the same transaction; a queued dispatcher fans out to consumers (tenant context stamped). Events: `EmployeeCreated/Joined/Offboarded`, `LeaveSubmitted/Approved`, `AttendanceRegularized`, `ExpenseApproved`, `PayrollCompleted`, `TaskCreated/Assigned/StatusChanged/Completed`, `SprintStarted/Completed`, `ProjectCreated`, `SubscriptionChanged`. Consumers: notification, audit, automation, analytics (summary refresh), integration (webhooks). Existing `TaskSynced/CommentSynced/NotificationSent` stay as UI broadcasts and become consumers.

## 27. Subscription Packaging

| Option | Shape | Pros | Cons |
|---|---|---|---|
| A. Unified plans (Starter / Pro / Business / Enterprise) | tiers bundle TMS+HRMS | simple, matches current catalog | HRMS-only or TMS-only buyers overpay |
| B. TMS base + HRMS add-on | TMS plans; HRMS per-employee add-on | fits the two buyer personas, lets TMS land-and-expand | two meters to explain |
| C. Modular consumption | pay per module/usage | flexible | complex billing, unpredictable bills |

**Recommendation: B on top of existing plumbing.** Seat = login user (TMS, per-user); HRMS priced per *employee record* (not login), tiers Core / Plus (attendance, leave, expenses, payroll) / Advanced (performance, talent, analytics); Enterprise adds SSO, API/webhooks, audit export, retention, support SLA. Metered extras: storage, automation executions, API calls. Because `plans.limits.modules` already carries dotted keys, B is a catalog change plus an `employees` quota (exists), not a rebuild. Pricing numbers are a business decision (❓ not derivable from the code).

## 28. Product Differentiation

Table stakes to match: SSO, API, bulk import, shifts, sprints, JQL-lite. Differentiators FlowSync can actually deliver because both domains share one identity and one tenant DB: workload/capacity that respects leave, shifts and holidays; goal↔epic evidence; onboarding that spawns real tasks; expense/time tied to projects; one approval inbox across HR and work. Avoid: building full ATS, LMS and a Jira clone's every view before the engines exist. Vision: *one place where the people system and the work system share the same facts.*

## 29. Master Development Roadmap

```
P0 stop-the-bleed → P1 cleanup + test foundations → P2 shared primitives
   → P3 TMS workflow + automation → P4 TMS agile layer
   → P5 HRMS completion (parallelisable with P4 after P2) → P6 billing
   → P7 HRMS↔TMS integration (needs P3, P4.1-4.3, P5.1) → P8 enterprise security (P2.7 first)
   → P9 scale, ops, mobile
   → P10 core freeze + test accounts + end-to-end validation
   → P11 marketing website (FINAL) + final regression gate
Phase 1A (RBAC hardening) runs with P1 and gates P2.
```
Dependencies: P3 needs P2.1 (events) and P2.4 (approvals v2); P4.5 needs P3.1 (history); P5.10-P5.11 need P2.4; P7 needs P3.6 and P4.3; P8.2 needs P2.7; P6 independent after P1.

## 30. Phase-by-Phase Implementation Plan

Applies to **every** phase unless stated: *Backward compatibility* — defaults reproduce today's behaviour (e.g. "any→any" workflow scheme, hard-coded approval chains seeded as templates); *Migration* — repair-safe, pending-only, backfill in `tenants:provision`, dry-run + `--force` for anything touching grants; *Entitlement* — new capability gets a module key *with its route gate in the same commit*; *Audit* — sensitive writes use `PlatformAudit`/`HrmsAuditLogger`; *Tests* — focused feature test per task, full suite at phase end (repo policy); *Rollback* — migrations have `down()`, features behind flags once P8.7 lands (before that, behind plan modules); *Delivery* — one reviewed commit per task, pushed (AGENTS.md).

| Phase | Objective | Why now | Acceptance (summary) |
|---|---|---|---|
| **P0** | Close exploitable/blocking defects | Cheap, high risk if left | Pages render; 4 routes 403 for unauthorised users; secret file untracked |
| **P1** | Remove debt, add JS test/lint, fix perf hot spots, settle module keys | Every later phase touches these files | Pint clean, Vitest+lint in CI, N+1 gone, one link table, route files split |
| **P2** | Event bus, correlation IDs, approvals v2, custom fields, API tokens, webhooks, digest | Prerequisites for automation/integrations | Events emitted and consumed; chains configurable; token API works with scopes |
| **P3** | Transition engine, automation v1 | JIRA-class core | Illegal transitions refused with reason; rule fires once per event |
| **P4** | Agile layer | Largest TMS value | Sprint lifecycle + burndown on real data |
| **P5** | HRMS completion | KEKA-class staples + reserved modules | Each shipped with page, API, policy, entitlement, audit |
| **P6** | Billing completeness | Revenue integrity | Invoice per payment; dunning; proration tested |
| **P7** | HRMS↔TMS depth | Product thesis | Capacity respects leave; auto-membership policy works |
| **P8** | Enterprise security | Sales gate | 2FA, SSO, personas, support sessions, CSP |
| **P1A** | RBAC & authorization hardening | Escalation + audit + impersonation risk; new engines must inherit safe authz | Escalation closed, system roles protected, route-audit and matrix tests green |
| **P9** | Scale/ops/mobile | Run it in production | SA ops pages, restore, PWA, search index, E2E |
| **P10** | Core freeze, test accounts, end-to-end validation | Release confidence before marketing anything | Validation report signed off, no open P0/P1 |
| **P11** | Marketing website (final) | Must show only what is real | `site:check` green; final regression green |

## 31. Task-by-Task Development Plan

Columns: ID · task · key files · DB · tests · deps · size · risk. API/UI/permission are stated when non-obvious. Acceptance criteria are the test named plus the behaviour stated.

### Phase 0 — Stop-the-bleed ✅ DONE 2026-10-09
Shipped: P0.1 `e77cbee`, P0.2 `c6b4035`, P0.7 `89adede`, P0.3 `f56aec5`, P0.4 `c7c2d71`, P0.5 `ae73795`, P0.6 `36fb89f`. New tests: `OpenRouteAuthorizationTest` (7), `RoleEscalationTest` (10), `ModuleGateTest` +1, `TenantExportTest` URL-shape assertion. P0.3 caveat: the old `.env.docker` values remain in git history — rotate `APP_KEY`/`REVERB_*` if that stack was ever deployed.
| ID | Task | Files | DB | Tests / acceptance | Deps | Size | Risk |
|---|---|---|---|---|---|---|---|
| P0.1 | Confirm in browser, then make `ProtectedRoute` render `children ?? <Outlet/>` (or convert wrapper routes to layout form) | `components/ProtectedRoute.jsx`, `App.jsx` | — | manual check of `/app/subscription`, `/app/hrms/employees`; later covered by P1.12 smoke test. Acceptance: all 21 wrapper routes render and still redirect without the permission | — | S | Low |
| P0.2 | Authorise `tenant/profile` (PUT), `onboarding/step`, `onboarding/complete`, `billing/history`; add route-audit test | `routes/web.php:161-184`, `TenantController`, `OnboardingController`, `BillingController` | — | feature test: non-admin → 403, admin → 200; wizard still works for the registrant (admin). Authorization: `tenant/profile` PUT and the two onboarding writes use the same `hasRole('admin')` 403 that `MySubscriptionController` mutations use (there is no `settings.manage` slug; a new `tenant.manage` slug is the alternative, repaired via `tenants:provision`); `billing/history` requires `billing.view` | — | S | Med (don't lock out wizard) |
| P0.3 | Untrack `.env.docker` → `.env.docker.example`, gitignore, document rotation | repo root, `docker-compose.yml`, runbook | — | `git ls-files` shows no env secrets. Rotate keys if ever deployed (owner action) | — | S | Low |
| P0.4 | Fix `api/api/exports/...` prefix | `routes/web.php` | — | signed-download test (`flushSession` + `iso_system`) green | — | S | Low |
| P0.5 | Top-level error boundary in `AdminLayout` | `layouts/AdminLayout.jsx` | — | thrown render error shows fallback, not blank | — | S | Low |
| P0.6 | Test pinning stacked `ensure_module` pairs and `export` route `billing.view` alignment | `routes/web.php`, `App.jsx`, tests | — | plan lacking either module → 403 with `X-Module-Reason` | — | S | Low |


**Added by the RBAC audit:**

| ID | Task | Files | DB | Tests / acceptance | Deps | Size | Risk |
|---|---|---|---|---|---|---|---|
| P0.7 | **Escalation guard (R1 hotfix):** reject granting permissions the actor does not hold; only holders of `admin` may assign/modify the `admin` role; default user keeps `admin` | `RoleController`, `UserController::updateRoles`, new `GrantCeiling` support class | — | escalation suite: editor with `roles.manage` cannot create a `*`-equivalent role; `users.manage` non-admin cannot grant `admin` or self-promote; admin unaffected. Existing roles untouched (no data migration) | — | S | Med (custom roles that relied on the loophole will 422; report, don't auto-fix) |

### Phase 1 — Cleanup & foundations
Progress **2026-10-10** (validated by the focused runs below; full-suite gate still pending): **P1.6–P1.8 ✅ + P1.13 ✅ + P1.15 ✅ + P2.2 ✅ + P2.3 ✅** (track/foundation merged — `NotificationService` split, `TaskService`/route split, shared helpers, `Auditable::snapshot`), **P2.7 ✅** (track/platform-engines merged — personal API tokens `api.manage`, `/api/v1`, idempotency, per-token throttle, integration log; tests green after fixing the un-run track's test-isolation gaps), **P4.1 ✅** (track/tms-p4 merged — issue hierarchy, levels, epic links, tree view), **P4.10 ✅** (per-status WIP limits + assignee swimlanes), **P4.9a ✅** (task checklists, already on the development line), **P5.1/5.2/5.5/5.9/5.12/5.15/5.16 ✅** (track/hrms-p5 merged — shifts, rosters/rotation templates, bulk CSV import + bulk status, leave rollover/adjustments/blackouts, document versioning, geofence/break punches, holiday CSV/ICS + asset replacement), **P6.1–P6.5 ✅** (track/billing merged — Stripe subscription checkout/verify, recurring webhooks, plan change, portal), **P8.1 + P8.3–P8.7 ✅** (track/security merged — 2FA, platform personas, support sessions, CSP, hash-chained audit, feature flags; P8.2 SSO deferred). Earlier: **P1.1–P1.5, P1.9–P1.12, P1.14 ✅**, **P2.1 ✅ + P2.8 ✅**, **P3.1 ✅ + P3.2 ✅ + P3.4 ✅ + P3.5 ✅**, **P4.2 ✅ + P4.5 ✅**. Open: P1.12 remainder (shared ConfirmDialog/Tabs/useResource, enum constants, lazy routes, splits), P1.10 (goal↔task link merge), P2.4/P2.5/P2.6/P2.9 (approvals v2, chains, custom fields, digest), P3.3, P3.6, P4.3–P4.4, P4.6–P4.9, P4.11–P4.12, P5.3/P5.4/P5.6–P5.8/P5.10–P5.11/P5.13–P5.14/P5.17–P5.18, P6.6, P7–P11.
| ID | Task | Notes | Size |
|---|---|---|---|
| P1.1 | Pint unused imports (11) | code + tests | S |
| P1.2 | Delete verified-dead scopes/relations/helpers/enum helpers | grep each incl. string `with()`; run focused tests | S |
| P1.3 | `NotificationService`: batch-load recipients (12 sites) | test query count | S |
| P1.4 | `ReportsController`: SQL `GROUP BY` aggregates; paginate `UserController::index`; bound audit/system lists | query-count test | M |
| P1.5 | Export ZIP cleanup (TTL job + delete after download) | H-12 | S |
| P1.6 | Split `NotificationService` per event family behind interface | no behaviour change; existing NotificationTest | M |
| P1.7 | Split `TaskService` (move/reorder, bulk, watchers) and `routes/web.php` into per-domain files; other >300-line classes opportunistically | route:list diff = identical | M |
| P1.8 | Shared helpers: `NormalizesFilters` (clean), `ResolvesDateRange`, `ChecksPerformanceScope`, slug and tenant helpers, `Auditable::snapshot` | | M |
| P1.9 | Escape-aware `LIKE` helper used by all searches | injection-of-wildcard test | S |
| P1.10 | Unify goal↔task links. **Design correction (2026-10-09):** the tables are not duplicates — `goal_task_links` pins a task to a specific GOAL (`goal_id`), `hrms_task_links` is (employee, task, kind) with no goal. A merge needs `hrms_task_links.goal_id` (nullable, kind=`goal` only, unique `(goal_id, task_id)`), a data migration deriving `employee_id` from the goal, switching `PerformanceService`/`PerformanceGoalController`/`Task` relation, then dropping the old table. Touches `HrmsGoalTaskLinkTest`, `HrmsPerformanceTablesTest`, `HrmsPerformanceEvidenceTest`, `HrmsTaskLinkApiTest` — do it when tests can be run per step | data-migration test both grammars | M |
| P1.11 | Decide + fix goal refresh on `TaskService::update()` completion (❓) | test | S |
| P1.12 | Frontend foundation: Vitest+RTL, ESLint (no-unused-vars), route-gate smoke tests, remove unused imports, `ConfirmDialog`, shared `Tabs`, status tone map, `useResource`/`usePaginated`, enums from server `/meta`, `React.lazy` routes | split `ProjectDetail`, `Leave`, `Tenants`, `WorkspaceDetail`, `TaskDetail` by tab | L |
| P1.13 | CI: add PG migration lane, Pint, Vitest, lint | | M |
| P1.14 | Module-key integrity: test diffing `config/subscriptions.php` vs routes; add `ensure_module` for `hrms.onboarding/offboarding/documents/compensation/payroll/inbox`; retire `hrms.exemptions` in favour of `hrms.leave.exemption`; keep `hrms.shifts`/`hrms.talent` as *reserved until P5.1/P5.17 land* (mark in catalog as `reserved`, hidden from plan editor) | tenants on current plans keep access: migrate plan module lists so no tenant loses a feature | M |
| P1.15 | Docs truth pass: AGENTS.md counts, pointer to this file, runbook | | S |

### Phase 1A — RBAC & authorization hardening (runs alongside Phase 1; must finish before Phase 2 builds new engines on top)
Progress 2026-10-09: **R1 ✅** (`89adede`), **R3 ✅** (`7f909ad`), **R5 ✅ for billing/profile/onboarding** (`19ca3f8`; export stays role-bound), **R7 ✅ core + R8 ✅ core** (reason, time box, read-only, blocklist, sweeper, banner; `ActivityLogger`/`HrmsAuditLogger` attribution still open), **R9 ✅** (`54aedb2`). R4 ✅ (config roles; run `tenants:provision` to create them in existing tenants), **R2 ✅** (`is_system`, read-only built-ins, delete, union-add provisioning), **R6 ✅** (clone; role templates beyond the four new defaults not built). **R10 ✅** (`visibleTaskQuery` applies per-project `TaskScope`), **R13 ✅** (cross-tenant search audited; impersonation-time exports are blocked outright by R8). **R14 ✅ backend** (`GET users/{user}/access`; the Roles-page panel is not built). **R12 ✅ as a guard, not a rewrite** (`FrontendPermissionLiteralsTest` requires every slug the SPA passes to `can/check/hasPermission/permission=/capabilities` to exist in the catalog — 46 literals, 0 typos today; generated constants were judged churn for no extra safety). **R11 ✅** (`hrms.approvals.override`, audited, reason required, never on own request). Open: R15 (generated matrix) and the Roles-page SPA panel for R14. Tests for these are written and first run at the final gate (defer-tests instruction).
| ID | Task | Files / DB | Tests / acceptance | Deps | Size |
|---|---|---|---|---|---|
| R1 | Grant-ceiling rule as a reusable policy (actor ⊇ granted set), applied to role create/update and user-role assignment; `admin` assignment restricted to admins | `app/Support/GrantCeiling.php`, `RoleController`, `UserController` | escalation suite green | P0.7 | S |
| R2 | System-role protection: `roles.is_system` (repair-safe migration, backfill for the 6 config roles); system roles read-only or "clone to customise"; `DELETE roles/{role}` for custom roles with reassign-or-block when in use; provisioning union-adds only (mirror project-role behaviour) | tenant migration, `Role`, `RoleController`, `TenantProvisioner::seed`, `Roles.jsx` | edits to system roles 422; delete blocked while assigned; re-provision keeps tenant customisations | R1 | M |
| R3 | Audit `role.*` and `user.roles_changed` (actor, tenant, before/after permission slugs, correlation id) into a tenant-readable audit feed; surface in Roles page and admin audit view | `RoleController`, `UserController`, audit writer contract (P2.2) | each mutation writes one masked diff row | R1 | M |
| R4 | Add default roles `employee`, `hr_executive`, `finance_admin`, `auditor` to `config/permissions.php`; document legacy aliases (`editor`/`viewer`); backfill via `tenants:provision` (roles added, **no existing user reassigned**); dry-run listing | config, `TenantProvisioner`, docs | new tenants get roles; existing tenants gain roles but no user changes; role snapshot test | R2 | M |
| R5 | Replace `hasRole('admin')` checks with permissions (`billing.manage`, `tenant.manage`, `onboarding.manage`); admin retains via `*`; backfill grants through selectors, custom roles untouched | 6 call sites + catalog | custom role with `billing.manage` can switch plan; without it 403 | R2 | S |
| R6 | Decision record: no runtime role inheritance / deny rules; add **clone role** + **role templates** (HR, Finance, Auditor) in UI | `Roles.jsx`, `RoleController@clone` | clone copies grants, never links | R2 | S |
| R7 | Impersonation hardening I: mandatory reason, time-box + server expiry check middleware, `ended_at` sweeper (scheduled), attribution `impersonator_id` on audit/activity writers | `ImpersonationController`, `ImpersonationLog` (+columns), `SetTenantContext`, scheduler | expired session refused; sweeper closes logs; rows carry impersonator | P2.2 | M |
| R8 | Impersonation hardening II: read-only default (write verbs blocked unless step-up), blocklist of sensitive routes, tenant admin notice + session history | middleware `BlockWhileImpersonating`, SPA banner, tenant page | blocked actions 403 with reason; notice delivered | R7 | M |
| R9 | Route-audit test + CI gate (no unguarded tenant route; allowlist file for documented self-scope routes) | `tests/Feature/RouteAuthorizationAuditTest.php` | fails when a new route lacks guard | P0.2 | S |
| R10 | Fold `TaskScope` row scope into `search/tasks`, dashboard, reports, analytics (H-13) | `ScopesVisibleTasks` | scoped project role gets same rows via search as via board | R9 | M |
| R11 | Approval authorization: explicit `approvals.override` permission (audited "approve on behalf"), catalog slugs for approve actions where missing | `ApprovalService` policy | override writes audit; absent permission 403 | P2.4 | M |
| R12 | Generated frontend permission/module constants from the server catalog (`/meta`), lint rule against raw literals, route-gate Vitest suite | `utils/permissions.js`, ESLint | typo'd slug fails lint | P1.12 | M |
| R13 | Audit SA cross-tenant search and impersonation-time exports | `GlobalSearchController` | row per fan-out | R7 | S |
| R14 | Effective-permission explainer (`GET users/{user}/access?check=slug`) + Roles-page "why can't they?" panel | controller + SPA | returns chain: role → grant → scope → module → plan | R4 | M |
| R15 | Generated authorization matrix test (Tenant × Plan × Role × Permission × Scope × Module) and cross-tenant suite from §8A | `tests/Feature/AuthorizationMatrixTest.php` | all cells match catalog-derived expectations | R2, R9 | L |

### Phase 2 — Shared primitives
| ID | Task | DB | Deps | Size |
|---|---|---|---|---|
| P2.1 | Domain event bus: `DomainEvent` base, outbox table, dispatcher job, tenant stamping; emit from task/leave/expense/payroll/employee/subscription services | `domain_events` | P1.6 | L |
| P2.2 | Audit contract: both audit writers implement one interface + correlation id; request-id middleware; JSON log channel | none/small | — | M |
| P2.3 | Convert `NotificationService` callers to event consumers (behaviour-identical) | — | P2.1, P1.6 | M |
| P2.4 | Approvals v2: modes, conditions, delegation, SLA/reminders/escalation scheduler, resubmission; `approval_templates` seeded from today's hard-coded chains; tenant UI to edit chains | `approval_templates`, `approval_delegations`, use `due_at` | P2.1 | XL (3 slices: engine; delegation+SLA; UI) |
| P2.5 | Migrate leave/expense/regularization/comp-off/revision/document to template-driven chains | — | P2.4 | M |
| P2.6 | Custom-field engine: definitions per entity, typed JSONB values, validation, filter integration, entitlement `custom_fields` | tables above | P1.12 | XL (employees → tasks → projects → others) |
| P2.7 | Sanctum tokens: model, abilities↔permissions, expiry, UI, `/api/v1` for token routes, idempotency, per-token throttle, integration log; module `api` re-added with its gate | `personal_access_tokens`, logs | P0.2 | L (**shipped, non-Sanctum self-built; `ApiTokenTest` green**) |
| P2.8 | Outbound webhooks: endpoints, HMAC signing, retries, delivery log, SSRF guard, UI; module `webhooks` | tables | P2.1, P2.7 | L |
| P2.9 | Notification digest/throttle/dedup + tenant channel policy + locale-aware templates | | P2.3 | M |

### Phase 3 — Workflow & automation
| ID | Task | Deps | Size |
|---|---|---|---|
| P3.1 | `status_transitions` + `task_status_history`; default any→any scheme; enforcement in `TaskService::update/move`; error reason in 422 | P1.7 | L |
| P3.2 | Transition permissions, validators (required fields, open blockers, subtasks done), post-actions (assign, set field, notify) | P3.1 | L |
| P3.3 | Approval-gated transitions via approvals v2; workflow editor UI in project Workflow tab | P2.4, P3.1 | L |
| P3.4 | Automation engine core: rules/runs, dispatcher on event bus, loop guard, idempotency, metering | P2.1 | L |
| P3.5 | Time-based triggers (overdue, due-soon) via scheduler; rule builder UI | P3.4 | M |
| P3.6 | Starter rule packs: onboarding on `employee.joined`, leave approved → task adjust, priority critical → notify lead, blocked → notify owner | P3.4 | M |

### Phase 4 — TMS agile layer (each a vertical slice)
| ID | Task | Deps | Size |
|---|---|---|---|
| P4.1 | Issue hierarchy: `parent` rules per issue type, epic link, hierarchy validation, tree views, permission inheritance | P3.1 | L (**shipped; `IssueHierarchyTest` green**) |
| P4.2 | Sprints & backlog: tables, planning board, start/close, carry-over | P4.1 | XL |
| P4.3 | Capacity (hooks into shifts/leave later) | P4.2 | M |
| P4.4 | Roadmap/timeline & calendar views (versions, epics, due dates) | P4.1 | L |
| P4.5 | Agile reports: velocity, burndown/burnup, cycle/lead time, throughput, workload | P4.2, P3.1 | L |
| P4.6 | Clone, move across projects (key/status remap), bulk edit/assign/transition | P3.1 | L |
| P4.7 | Saved/shared filters, then JQL-lite parser | P1.9 | L |
| P4.8 | Project/workspace templates & clone, project health | P4.6 | M |
| P4.9 | Task checklists; dependency types `relates/duplicates/clones` (H-10) | — | M |
| P4.10 | Swimlanes, WIP limits | P4.2 | M (**shipped; `TaskBoardWipSwimlaneTest` green**) |
| P4.11 | Team/project/workspace dashboards, saved widgets | P4.5 | L |
| P4.12 | Timer, billable flag, weekly timesheet + approval (approvals v2) | P2.4 | L |

### Phase 5 — HRMS completion (module key + route gate + nav + shell test in every slice)
| ID | Task | Deps | Size |
|---|---|---|---|
| P5.1 | Shifts catalog + seed `shift_patterns` + CRUD + assignment | P1.14 | L |
| P5.2 | Rosters (weekly/rotational), weekly offs, night/split | P5.1 | L |
| P5.3 | Shift swap + approval, exceptions, SPA | P5.2, P2.4 | M |
| P5.4 | Employee lifecycle workflow engine (states, effective dates, dynamic approvers, templates) | P2.4 | L |
| P5.5 | Bulk CSV import + bulk status change (validate→preview→commit, quota-aware, audited) | P2.2 | L |
| P5.6 | Transfer / promotion / confirmation on P5.4, with letters | P5.4 | L |
| P5.7 | Exit interview + F&F settlement (uses clearances, leave encashment, payroll) | P5.4 | L |
| P5.8 | E-signature (internal signed-hash flow first), letter generation | P5.6 | L |
| P5.9 | Leave carry-forward/lapse job + manual adjustment endpoint, hourly leave, blackouts | P2.4 | M |
| P5.10 | Payroll reopen (reverse transitions with approval + audit), bank file, loans/advances, register export | P2.4 | L |
| P5.11 | Expense limits, mileage, advances, project/task link | P2.4 | L |
| P5.12 | Document versioning | — | M |
| P5.13 | Org extras: matrix reporting edge, BU/legal entity/cost center, graphical chart | P1.12 | L |
| P5.14 | Employee tags | P2.6 | S |
| P5.15 | Geofence block-vs-flag setting, SPA lat/lng, break punches | — | M |
| P5.16 | Holiday CSV/ICS import; asset replacement state | — | S |
| P5.17 | Talent: skills, matrix, career paths, succession, pools, IDP | P2.6 | XL |
| P5.18 | Performance depth: competencies, KPI entities, PIP, 360, calibration tools | — | L |

### Phase 6 — Billing
| ID | Task | Size |
|---|---|---|
| P6.1 | Invoice entity + generation on payment events + PDF | L |
| P6.2 | Dunning sequence + grace period + suspension on schedule | L |
| P6.3 | Period-end expiry/renewal job (G-2 remainder) | M |
| P6.4 | Proration on plan change/seat change; overage policy decision | L |
| P6.5 | PDF payslips | M |
| P6.6 | Packaging change to option B in `config/subscriptions.php` + migration of existing plans | M |

### Phase 7 — HRMS↔TMS integration
P7.1 auto-membership policy (L) · P7.2 leave/holiday awareness on assignment (M) · P7.3 workload & capacity read model (L) · P7.4 timesheet→payroll component (L) · P7.5 goal↔epic/project links (M) · P7.6 cross-domain reports on summary tables (L). Deps: P3.6, P4.3, P4.12, P5.1.

### Phase 8 — Enterprise security
P8.1 TOTP 2FA + recovery codes + enforce-per-role (**shipped**, ✅) · P8.2 OIDC SSO then SAML (**deferred**) · P8.3 platform personas on `platform_roles` replacing blanket `super_admin` route by route (**shipped**, ✅) · P8.4 consent-based time-boxed support sessions (**shipped**, ✅) · P8.5 CSP + security headers (**shipped**, ✅) · P8.6 audit export/WORM/hash chain + retention per plan (**shipped**, ✅) · P8.7 runtime feature flags with tenant overrides (**shipped**, ✅) · P8.8 SCIM (Enterprise, L, **deferred**).

### Phase 9 — Scale, ops, mobile
P9.1 SA pages: health, queues, failed jobs, backups (M) · P9.2 real queue probe (S) · P9.3 `tenants:restore`, clone, hard purge/erasure (L, legal first) · P9.4 summary-table refresh jobs (M) · P9.5 PWA manifest + service worker + mobile punch/leave/approvals/my-tasks (L) · P9.6 TMS retention (M) · P9.7 `tsvector` indexes (M) · P9.8 localisation scaffolding (`lang/`, tenant locale) (M) · P9.9 Playwright E2E, k6 load, route×tenant matrix test (L).

### Phase 10 — Core freeze, test accounts & end-to-end validation (after Phases 0-9 and the Phase 1A tasks)
Entry gate: full regression suite green, Playwright suite green, no open P0/P1 items. Feature freeze for core. Spec in §37.
| ID | Task | Size |
|---|---|---|
| V1 | `validation:seed` command (refuses in production) creating the 2-4 accounts and two isolated tenants with different plans; credentials generated per run, written only to an untracked file | M |
| V2 | Scenario scripts (Playwright + API) for SA operations, tenant admin setup, HRMS, TMS, role-based access, plan restrictions, impersonation, cross-tenant attempts | L |
| V3 | Run, triage, fix, re-run; every defect becomes a regression test | L |
| V4 | Write `.agents/roadmap/validation-report.md` (accounts by role/email alias, scenarios, expected vs actual, defects, sign-off) | S |

### Phase 11 — Marketing website (FINAL phase; runs only after Phase 10 sign-off)
Spec in §38. Tasks W1-W10 (structure, content registry, screenshot pipeline, pricing bound to live plans, pages, lead capture, SEO, sync guard, QA, final regression).
| ID | Task | Size |
|---|---|---|
| W1 | Audit of the existing CMS site (done below) and information architecture / sitemap | S |
| W2 | Extend CMS blocks: `image/screenshot`, `feature-grid`, `pricing` (reads active `subscription_plans`), `faq`, `contact-form`, `logo/stats`; keep `hero/features/text/cta` | L |
| W3 | Screenshot pipeline: Playwright script drives the seeded validation tenant and writes versioned PNG/WebP to `public/site-assets/` with a manifest (page, route, plan, app commit) | L |
| W4 | Claims registry `config/marketing_claims.php`: every marketed feature → module key + release state; website build and a test fail on a claim whose module is reserved/unreleased | M |
| W5 | Pages: Home, Features, HRMS, Task Management, Pricing, About, Contact, Security, Privacy, Terms (+ Solutions/Customers only if real content exists) | L |
| W6 | Lead capture: contact/demo form with validation, rate limit, honeypot, stored in system DB + notify, audit-free of PII leaks | M |
| W7 | SEO/perf/accessibility: meta, OG images, sitemap, robots (exist), Core Web Vitals budget, a11y pass | M |
| W8 | Sync guard: `site:check` command + CI test comparing pricing block to plans, claims to modules, screenshots to app version; checklist added to the plan-change DoD | M |
| W9 | Responsive/browser QA, content review for accuracy against validation report | S |
| W10 | **Final gate:** complete regression suite + Playwright + `site:check`; final implementation and testing status written to the roadmap | M |

## 32. Migration & Rollout Strategy

1. Every phase merges only with the repo gate: focused tests, `./vendor/bin/pint --test`, `npm run build`; full suite at phase end.
2. New tenant migrations are pending-only and backfill inline; rollout = deploy → `tenants:provision` (repairs all tenants) → verify demo logins.
3. Permission changes: add slugs by repair; grant to roles only via selector or dry-run report; **never widen custom roles silently**.
4. Module changes (P1.14, P6.6): compute each tenant's effective modules before/after and fail the migration if any tenant would lose a feature it uses.
5. Data migrations (P1.10): copy → switch reads → verify counts → drop in a later release.
6. Engines (P3, P2.4) ship with a default that reproduces current behaviour, switchable per tenant.

## 33. Risk Register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| `ProtectedRoute` bug is real and users already hit it | Med | High | P0.1 first, browser check |
| Locking out the onboarding wizard while adding auth (P0.2) | Med | Med | admin-only test with registrant flow |
| Event bus retrofit changes notification timing | Med | Med | outbox + behaviour-identical consumers, snapshot tests |
| Approvals v2 breaks in-flight approvals | Med | High | templates seeded from current chains; in-flight rows keep old shape |
| sqlite fast-path hides PG-only failures | High | Med | PG CI lane (P1.13) |
| Scope creep: "build everything" (reserved modules, talent, service desk) | High | High | phases are gated; P5/P4 slices ship independently; re-rank after P3 |
| Legal retention/erasure unknown | High | Med | decision required before P9.3/P9.6 |
| Pricing/packaging unsettled | Med | Med | P6.6 last; engines don't depend on it |
| Single maintainer throughput (repo convention: one reviewed commit/task) | High | Med | tasks sized S/M where possible |

## 34. Definition of Done (every task)

Backend: route → FormRequest → authorize (policy) → service → presenter; tenant-safe; queued work tenant-stamped. Entitlement: module key + `ensure_module` + `user.modules` mirror + shell test. Audit written for sensitive actions. Notifications/events emitted where the domain defines them. Frontend: gated route, loading/empty/error states, responsive, no raw `window.confirm`. Tests: focused feature test incl. authorization negative cases and tenant isolation where data crosses a boundary. `pint --test`, `npm run build` clean. AGENTS.md updated where architecture/routes/counts changed. **Authorization checklist:** route guard + policy + scope + module + negative tests (unauth, wrong tenant, no permission, narrow scope, plan without module) + audit row + frontend mirror from generated constants. **Marketing sync:** if the change adds, removes or renames a module, plan, limit or user-visible feature, update `config/marketing_claims.php` and queue screenshot regeneration (enforced by `site:check` once Phase 11 exists; before then, add a line to the website backlog in this file). Committed and pushed.

## 35. Recommended Immediate Next Phase

**Top 10 (in order):**
1. **P0.1** `ProtectedRoute` — blocks ~21 pages; XS effort; confirm first.
2. **P0.2 + P0.7** authorise 4 open routes and close the role-escalation hole — real privilege holes; small.
3. **P0.3** untrack `.env.docker` — secrets in git history; tiny.
4. **P0.4-P0.6** route prefix, error boundary, module test — quick wins.
5. **P1.12 (JS test+lint slice only)** — prevents repeats of #1.
6. **P1.14** module-key integrity — entitlement truth before selling more modules.
7. **P1.3-P1.4** N+1 and in-memory report fixes — scale risk with no feature cost.
8. **P1.10** one goal↔task link table — before P7 builds on it.
9. **P2.1 + P2.2** event bus and correlation IDs — unlocks P2.3, P2.8, P3.4.
10. **P2.4** approvals v2 — unlocks P3.3, P4.12, P5.4+.

**Single phase to hand to a coding agent next: Phase 0 (P0.1 → P0.7; P0.7 is the RBAC escalation hotfix)**, one commit per task, then Phase 1 in the order listed. Open owner decisions needed before later phases: retention periods (P9.3/P9.6), pricing for option B (P6.6), whether `automation_executions` and `api_calls` are metered or just module-gated (P2.7/P3.4), flag-vs-block geofence default (P5.15).

## 36. Mandatory Development Rules (apply to every phase and every agent)

1. **Audit before changing.** Re-read the affected code and this file's status for the item; if the item is already ✅, stop.
2. **Preserve completed functionality; do not duplicate.** Extend existing services, policies and engines (§4 dispositions). A second implementation of an existing capability is a defect.
3. **Follow the phase/task order and dependencies** in §29-§31. Do not start a task whose dependencies are open; record any reordering in this file with the reason.
4. **Enforce authorization, tenant isolation and subscription limits on both sides.** Backend is authoritative (route middleware → `ensure_module` → policy → row scope); the frontend mirrors from generated constants only. No feature merges with only a hidden button.
5. **Improve quality as you go.** Remove dead code and unused imports in files you touch, respect the 300-line class ceiling, prefer shared helpers over copies; no drive-by rewrites of unrelated files.
6. **Test each feature and its edge cases before committing** — focused tests including negative authorization cases; `./vendor/bin/pint --test`; `npm run build` (and Vitest once it exists) when JS changed.
7. **Keep plan and tracking docs current** — update this file's status/IDs, `gap-register.md`, and `AGENTS.md` (counts, routes, architecture) in the same commit that changes them.
8. **Marketing website is the last phase** and must be synchronised with the finished application (§38); until then, log marketable changes in the website backlog.
9. **Create and validate 2-4 test accounts after core development** to prove end-to-end flows, permissions and plan restrictions (§37).
10. **Finish with the complete regression suite** (`php artisan test` in full, Vitest, Playwright, `site:check`); resolve every failure; record the final implementation and testing status in this file.

Repo conventions that remain in force: one reviewed commit per task, pushed immediately; never commit `.env*`/credentials/`storage/`; never `--force`; feature-level tests per task, full suite at phase gates and for cross-cutting changes (routes, middleware, shared config, `TenantLimits`).

## 37. Test Accounts & End-to-End Validation (Phase 10)

**Current state (audit):** demo logins exist from `TenantSeeder` (`superadmin@flowsync.test`, acme `admin/editor/viewer`, globex `owner`) all with the shared password `password`; there is no validation plan, no plan-restricted tenant, and no recorded results. Seeding in production is not blocked ❓ (verify the seeder refuses when `APP_ENV=production`).

**Accounts (4, `.test` domain; passwords generated per run, kept in an untracked file or secret store, never committed or written into docs):**

| Alias | Role | Tenant / plan | Purpose |
|---|---|---|---|
| `val.sa` | Super Admin | platform | tenants, plans, features, settings, billing, impersonation, audit, health |
| `val.admin.a` | Tenant Admin | Tenant A on the full plan | org setup, users/roles, HRMS, TMS, billing; creates scenario users in-run: HR, Manager, Employee and one custom role |
| `val.user.a` | Employee (`employee` role from R4, plus project member) | Tenant A | own-data access, denied actions, scoped views, approvals as requester |
| `val.admin.b` | Tenant Admin | Tenant B on the lowest plan (HRMS add-on off) | plan restrictions (403 + `X-Module-Reason`), quota limits, cross-tenant probes |

HR, manager and custom-role behaviour is validated by users the Tenant Admin creates during the run (this also tests role creation, assignment, grant ceiling and audit), then removed.

**Scenario groups:** (1) Super Admin: provision tenant, switch plan, module toggle, suspend/reactivate, impersonate with reason and time-box, read-only enforcement, audit trail. (2) Tenant admin: onboarding, users, custom role create/clone/delete, grant-ceiling refusal, org structure. (3) HRMS: employee lifecycle, attendance punch and regularization, leave request→approval→balance, expense→payroll, payroll run lifecycle, documents with confidential rules, performance cycle. (4) TMS: workspace→project→task, workflow transition rules, sprint, comments/mentions, watchers, time log, automation rule. (5) Role-based access: employee cannot see another's data; manager sees reports only; HR cannot see payroll; custom role limits; frontend hides and backend refuses (direct API call). (6) Subscription restrictions: Tenant B blocked from HRMS modules and over-quota actions; upgrade unlocks. (7) Isolation: A's session against B's ids, signed URLs, channels, exports, search; SA outside impersonation blocked from domain routes. (8) Negative: unauthenticated, expired impersonation, tampered signed URL, replayed webhook.

**Deliverable:** `.agents/roadmap/validation-report.md` — accounts by alias/role/plan (no secrets), scenario table (id, steps, expected, actual, pass/fail, evidence link), defects found with fix commits, regression tests added, final sign-off. Safe-credential rule: no real emails, no reuse of the demo password, accounts deleted or rotated after the run.

## 38. Marketing Website (Phase 11 — final phase)

**Current state (audit):** `/` and `/page/{slug}` are server-rendered from DB-backed `website_pages` via `PublicSiteController` (84 lines) and two Blade views; admin CRUD at `/admin/pages` with block editor (`hero|features|text|cta`), SEO fields, sitemap.xml, robots.txt, publish/unpublish and audit rows. Seeded pages: home, about, privacy, terms. **Gaps:** copy describes project management only (no HRMS), no screenshot/image block, no pricing or plan comparison, no contact/demo form or lead storage, no features/HRMS/Task Management pages, no analytics or conversion tracking, no link between marketed claims and real modules. **Decision: extend the existing CMS site; do not build a separate site.**

**Requirements (from the brief):** modern, professional, responsive; pages Home, Features, HRMS, Task Management, Pricing, About, Contact (+ Security, Privacy, Terms and other relevant pages); real screenshots captured from this application; clear CTAs (start trial → `/app/register` when `public_registration` is on, otherwise request demo); content that matches what is implemented and the plans actually sold.

**Accuracy and sync mechanisms (so the site cannot drift):**
1. **Claims registry** — each marketed feature is an entry `{claim, module_key, status}`; only entries whose module is released (not 🔵 reserved, not planned) can be rendered. A test fails the build if a page references an unregistered or unreleased claim.
2. **Pricing block bound to live data** — reads active `subscription_plans` (price, trial, limits, module list) at render time; a plan change updates the site with no copy edit. Packaging option B (§27) is applied in the catalog first.
3. **Screenshot manifest** — screenshots are generated by a script against the seeded validation tenant, stamped with app commit and plan; `site:check` flags screenshots older than the last change to the pages they depict.
4. **Change hook** — any later change to a plan, module or user-visible feature triggers: update claim entry → regenerate affected screenshots → review pages (checklist in the Definition of Done).
5. **Never marketed as complete:** anything marked 🔵/🔴/🟡 in §5-§6 at release time; partial features are described accurately or omitted.

**Page plan:** Home (value prop, HRMS+TMS together, proof screenshots, plans teaser, CTA) · Features (module overview linked to claims) · HRMS (people, attendance/leave, payroll & statutory, performance, engagement, documents/assets — only released parts) · Task Management (boards, workflows, sprints/backlog only if shipped, time, automation only if shipped) · Pricing (live plans, comparison, FAQ) · About · Contact/Demo · Security (isolation, RBAC, audit — claims limited to what Phase 8/10 verified) · Privacy · Terms.

**Done when:** `site:check` green, accessibility and performance budgets met, every page reviewed against `validation-report.md`, and the final full regression gate (rule 10) passes.

---

## 39. Field Feedback Backlog (added 2026-10-09)

Source: owner testing feedback. Items are ordered by what unblocks the others. Same rules as §36 apply (audit first, authorize both sides, tests, tracking docs). IDs `FB-n`; each maps to gap rows G-83..G-91 in §21.

**Phase placement:** FB-10 done; FB-2, FB-9, FB-1 verification join **Phase 1A/1** now (they are defects); FB-3, FB-5, FB-6, FB-8 form a new **Phase 1B — Tenant lifecycle & billing completeness** run before Phase 2; FB-4 is an architecture decision that gates Phase 6 (§27 packaging) and is not started until the owner decides; FB-7 sits in Phase 9 unless the owner wants it sooner.

### FB-1 HRMS pages and per-service visibility
- **Finding:** the HRMS surface exists as one sidebar entry `/hrms` (hub with tabs from `utils/hrmsNav.js`). Wrapper-form routes were blank before P0.1 (`ProtectedRoute` returned only an `Outlet`), which matches "could not find the pages". Several modules are still pending (shifts, talent, exemptions) and have no page.
- **Rule to enforce everywhere:** tenant admin sees and can use **every module the tenant's effective plan (plan modules + SA `features_override`) includes — and nothing else**. Missing module ⇒ hidden in the SPA (`hasModule`) **and** 403 from `ensure_module` (fail-closed). SA enabling a module for a tenant must make it appear for that tenant's admin with no further step.
- **Tasks:** FB-1a browser-verify all `/hrms/*` routes as admin after P0.1 (record in §37 run log); FB-1b audit every route group and sidebar/overview tile for a missing module gate (P1.14 already did onboarding/offboarding/documents; compensation, payroll, inbox, shifts, talent, exemptions remain — needs the owner's decision in §35); FB-1c test: for each module key, tenant admin with and without it (SPA nav payload `modules`, API 403); FB-1d build the pending HRMS pages per Phase 5.

**Progress (2026-10-09, FB-1):** root cause of "tenant admin can't find the HR pages" beyond the P0.1 route bug: `User::hasPermission()` read the admin role's stored snapshot, so every permission added after a tenant was provisioned (all of HRMS, `support.manage`) was missing until `tenants:provision` re-ran. The admin role is `*` by definition, so `hasPermission()`/`permissionSlugs()` now give an admin every catalog slug (`PermissionCatalog`); the plan alone decides what an admin sees — modules off = hidden in the SPA and 403 + `X-Module-Reason` from `ensure_module`. `AdminEntitlementTest` walks every parameterless module-gated GET route: admin with a stale role and a full plan is never refused; with a thin plan every other module route is refused with its reason. SA enabling a module via `features_override` flows through the same `TenantLimits::hasModule`. Still open: pending HRMS pages (shifts, talent, exemptions) are Phase 5 builds; compensation/payroll module gating needs the owner decision.

### FB-2 Workspace/project access and Kanban
- **FB-2a (Move 403):** reproduce first. Suspects: `TaskMoveController` + `TaskPolicy::move` (project-role `tasks.move_*` scope grants, row scope via `TaskScope`), or the `workspaces.view` route group. Write a failing test for each role (lead/developer/viewer, tenant admin, scoped custom role), then fix at the cause; the UI must hide drag for roles that cannot move.
- **FB-2b (empty board until List→Board):** investigate `ProjectDetail` Tasks-tab load order (`tab === 'tasks'` lazy fetch, `view`/`filters` effect, Echo refetch, `openedDeepTaskRef`). Likely a stale/aborted first fetch or state reset on remount; add a Vitest regression around the loader.
- **FB-2c (revoked access):** membership is the root. Removing a user from a workspace must (1) remove their workspace-derived access to projects (hidden from `/projects`, search, dashboard, reports, notifications deep links), and (2) 403 every task action (move, comment, attach, work-log, watch). Decide and document whether removal also removes `project_members` rows (recommended: yes, transactionally, audited) or only gates by workspace membership; enforce in `ProjectPolicy`/`TaskPolicy`/`ScopesVisibleTasks` so no endpoint relies on the UI. Tenant admins keep tenant-wide access by design.
- **Tests:** a membership-revocation matrix across every task/collab endpoint.

**Progress (2026-10-09):** FB-2c **done** — `WorkspaceService::removeMember` now also drops the user's `project_members` rows for that workspace's projects in one transaction (`WorkspaceAccessRevocationTest`). Existing orphaned memberships from before this change are not cleaned up (follow-up: a one-off `tenants:` repair command). FB-2b mitigated — `ProjectDetail.loadTasks` is now latest-request-wins (stale board/list responses are dropped); root cause not reproduced in a browser, so keep ❓ until confirmed. FB-2a — server policy matrix is correct for lead/developer/admin (existing `TaskTest`); the 403 most likely seen was a **read-only impersonation session** (R8 default) or a role without `tasks.move`; the UI now hides write controls in a read-only session and shows the server's message + reverts the card on any 403. Needs the reporter's role/session to close.

### FB-3 Tenant onboarding and activation (Super Admin + registration)
- **Required-before-active rule:** a tenant stays `pending`/draft — **no tenant database is created** — until all required steps are complete: business profile, default user (see below), plan/subscription, payment method when a trial is chosen. Only then does provisioning run and the lifecycle move to `trial`/`active`.
- **One definition of the steps** (shared config + one `TenantOnboarding` implementation + one set of FormRequests) used by both the SA create/edit flow and `POST /register`, so they can never drift. Required fields are validated server-side identically; the SPA renders one multi-step component for both.
- **SA UI:** replace the modal with a full multi-step page (Business → Default user → Plan & trial → Review). The existing "View details" becomes **Edit tenant** and shows the default user and current subscription; editing the default user is allowed to the tenant admin afterwards (existing `makeDefault` rules).
- **Trial:** 14 days, only after a card is attached (Stripe SetupIntent, see FB-6); no card ⇒ no trial.
- **Needs from the owner:** is a draft tenant row persisted (resume later) or held client-side until submit? Recommended: persisted `draft` status with `onboarding_meta`, no DB.
- **Tests:** cannot activate with missing fields; DB not created while draft; SA path and register path reject identical payloads; default user is created and routed.

**Progress (2026-10-09, FB-3):** backend + SPA shipped. Draft status, `TenantIntake`/`TenantActivation`/`DefaultUserClaim`, shared wizard for SA and registration, default-user step, 14-day trial, `TenantDetail` shows default user + subscription (`TenantIntakeTest`, `RegisterTest` updated). **Card capture shipped (same day):** self-service sign-up saves a card on Stripe's hosted setup page before any database exists, then starts a Stripe trial subscription against it (`RegisterCardController`, `CardCaptureGateway`, `tenants:prune-drafts`; live-smoked on the Stripe test API). Enable with `ONBOARDING_REQUIRE_CARD=true`. SA-made tenants are exempt by design. Drafts are persisted (owner decision taken: recommended option). Public `RegisterRequest` now requires industry/company_size/country (breaking for API clients).

### FB-4 Separate HRMS and TMS products (architecture — owner decision required)
- **Request:** independent plans (Starter/Professional/Enterprise) per product, independent SA toggles, a database per product per tenant, and table sets that depend on the plan.
- **Assessment:** independent **plans and toggles** are cheap and consistent with the current design (the `hrms.*` module keys and `ensure_module` already work this way) — do that first. A **physical DB split** conflicts with deliberate cross-links: `users` shared by both, `hrms_task_links`, `tasks.hrms_employee_id`, goal↔task evidence, approvals, notifications, `activities`, and the §7 integration matrix all rely on one tenant DB; cross-DB foreign keys do not exist, so each becomes application-level with weaker guarantees, and every existing tenant needs a data migration. **Plan-dependent table subsets** add migration-state complexity (a plan upgrade then has to create tables, a downgrade must never drop data).
- **Options:** (A) per-product plans and toggles, one tenant DB, tables always present (recommended first step, low risk); (B) A + per-product migration sets created on first enable (still one DB, lazy tables, no drops on downgrade); (C) separate `tenant_hrms` / `tenant_tms` DBs with a shared identity DB (the Atlassian-style model — largest effort, breaks FKs, needs a cross-product link service).
- **Recommendation:** A now (Phase 6 packaging), B when a customer needs it, C only if the owner accepts losing DB-level integrity and a multi-month migration. **Blocked on owner decision**; record the choice in §27 before any code.
- **Whatever is chosen:** one tenant has one user directory visible in both products; SA can enable/disable each independently; end-to-end test: tenant with TMS only, HRMS only, both.

**Decision & progress (2026-10-09, FB-4):** owner chose **option B**. Shipped in four stages: (1) per-product plans (six Starter/Professional/Enterprise plans), one subscription per product, merged entitlements, `ensure_product`, SA product switches + split-bundle; (2) product-aware SA tenant page, plan CRUD, tenant self-service page and `/auth/me` `products`; (3) intake/registration take one plan per product (and a Stripe trial per product); (4) HRMS tables created lazily (`tenant_hrms` migration set + `TenantProductSync`), TMS-only tenants provably run without them. **Limits:** TMS tables are core for every tenant; Stripe per-product subscriptions reuse the existing flow (a checkout per plan) but a combined two-product checkout is not built; the legacy bundle plans remain and a tenant is on a bundle *or* product plans; removing a product keeps its data (no drop/purge command yet).

### FB-5 Super Admin improvements
- Extend the existing tenants table (do not add a duplicate tab): enabled/disabled counts by product and plan, trial/past-due/expiring columns, MRR-style totals, filters, per-tenant usage. A **Subscriptions** sidebar entry is a filtered view of the same data, not a second source of truth. More SA options follow the Phase 8/9 platform pages (health, queues, backups).

**Progress (2026-10-09, FB-5):** shipped on the existing Tenants page (no duplicate tab): `GET api/tenants/summary` (`TenantSummaryController` — enabled/disabled/draft counts, trials, paying, past due, est. MRR, "needs attention" list), new `subscription_status` filter, a renews/trial-ends column, and fixed the always-null `subscription_ends_at`. Per-product (HRMS vs TMS) counts wait on FB-4. More SA options remain tied to Phase 8/9 pages.

### FB-6 Stripe subscriptions
- Keys are in the backend `.env` (not to be committed or logged). Scope: Checkout/SetupIntent for card capture, subscription create/switch/cancel/renew, webhook handling (`invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated/deleted`, `setup_intent.succeeded`) with the existing signature + replay guard, proration, invoice list, billing portal link, trial-requires-card (FB-3). Tests use Stripe test mode via fakes for CI and one documented manual run with test cards. Depends on Phase 6 tasks; pull the Stripe-specific ones forward.

**Progress (2026-10-09, FB-6):** shipped — Stripe subscription-mode checkout, return-URL verification (the SPA never called verify before, so a successful payment only activated via webhook), recurring webhooks, in-place plan change, cancel-at-Stripe, billing portal, `STRIPE_PUBLIC` env mismatch fixed; live-smoked against the Stripe **test** API (customer, price, subscription checkout, portal, verify guards). Needs from you: run the new system migration; set `STRIPE_WEBHOOK_SECRET` (Stripe CLI or dashboard endpoint). **Open:** SetupIntent/card-on-file at registration + flip `ONBOARDING_REQUIRE_CARD`; proration/dunning rules (retry schedule) are Stripe defaults; no invoice PDF list yet (portal covers it); Razorpay path unchanged.

### FB-7 Support ticketing
- New central + tenant surface: tenant admin creates tickets (subject, category, priority, attachments); SA inbox with status workflow (open → in progress → waiting → resolved → closed), assignment, internal notes vs public replies, email + in-app notifications, audit trail, SLA fields. Tickets live in the **system** DB keyed by central tenant id (support staff must not need tenant DB access); consent-based support access is the Phase 8 link. Permissions: `support.create` (tenant admin by default), platform support role for SA.

**Progress (2026-10-09, FB-7):** shipped — system-DB `support_tickets`/`support_ticket_messages` (migration `2026_10_21_000026`), `SupportDesk` rules, tenant API (`support/tickets…`, permission `support.manage`, admin by default) + SPA `/support`, Super Admin inbox API (`system/support/tickets…`) + SPA `/admin/support` (priority/age ordering, filters, assign, status, **internal notes** hidden from tenants), in-app notification to the requester on staff reply/resolve/close, audit rows, impersonation write-block. **Not done:** attachments, email notifications, SLA timers, tenant-side notification to other admins. Deploy: run the system migration, then `tenants:provision` so existing admin roles pick up `support.manage`.

### FB-8 Roles, users and CSV import
- Roles page UI rework (grouped, searchable, diff on save, effective-access preview using the R14 explainer endpoint). User edit moves to a dedicated page (`/users/:id`), not inline.
- **CSV import:** download-a-sample button; template columns name, email, roles, optional fields; dry-run validate → preview → confirm; every row passes `GrantCeiling::assertCanAssignRoles` (cannot assign a role that exceeds the importer), role/permission names validated, per-row error report downloadable; plan seat limits enforced (`TenantLimits`); routing rows written for every user (`TenantUserRouting`); audit one import event with counts; chunked and queued for large files; never emails passwords in plaintext (invite/reset flow).

**Progress (2026-10-09, FB-8):** shipped — user edit moved to `/users/:id` (`GET|PUT users/{user}`: name + login email, routing row follows, audited `user.updated`; roles/default/delete live there too); CSV import at `/users/import` (`UserImporter` + `UserImportController`: sample, dry-run preview, commit; per-row errors; roles resolved by slug or name; **every row passes `GrantCeiling`**, plan user limit, global email uniqueness, 500-row cap; generated passwords returned once; audited `users.imported`); Roles permission grid now has search, per-domain collapse, All/None and counts. **Not done:** emailing invites/reset links to imported users (no mail flow assumed), a deeper Roles page redesign (role cards, diff-on-save, effective-access preview).

### FB-9 Blank Subscription page
- Probable cause is the P0.1 bug (`/subscription` is a wrapper-form route). Confirm in a browser; if it still blanks, capture the console error (error boundary now shows it) and fix the cause; add a route smoke test for it.

### FB-10 Export crash — done
- `ExportService::writeCsv` now normalizes cells (`csvCell`: backed enums, dates, arrays) so no object reaches `fputcsv`. Regression test to add with the next export test pass.

### Suggested order
FB-10 (done) → FB-9/FB-1a verification → FB-2a/2b/2c → FB-3 → FB-6 → FB-5 → FB-8 → FB-7 → FB-4 (after the owner's decision).
