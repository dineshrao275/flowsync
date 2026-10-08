# 12 — Glossary & Architecture Decisions (ADRs)

## ADRs (binding unless superseded by a newer entry here)

1. **Isolated-only tenancy (Phase 13).** One DB per tenant + central system DB;
   `TENANCY_DRIVER=shared` removed. No `tenant_id` columns in tenant tables —
   the physical DB is the boundary. Rationale: hard isolation, per-tenant backup/
   scale, no row-scope漏 bugs.
2. **Central-connection trait over config switching.** All central models use
   `CentralConnection::getConnectionName()` → `centralConnectionName()`; shared
   vs isolated test wiring lives in one place.
3. **Middleware-before-binding.** All DB-switching middleware registered in
   `bootstrap/app.php` priority ahead of `SubstituteBindings` (Laravel
   `SortedMiddleware` reorders; without this, binding runs on stale connections).
4. **Routing-index login.** Central `tenant_users` resolves tenant before auth;
   tenant-local ids are meaningless platform-wide (every tenant's owner is id 1).
5. **Signed tenant-scoped downloads.** Central id inside the signature, route
   outside `switch_tenant`, lookup inside `using()` — avoids the central-binding
   bug where tenant-local ids resolve to nothing.
6. **No-subscription ⇒ unlimited.** Keeps seeded/demo/legacy tenants running;
   explicit states (trial/active/canceled/expired) backfilled in roadmap Phase 5.
7. **Module keys are flat dotted strings** (`hrms.attendance.remote`), compared as
   strings in `TenantLimits::hasModule()`; per-tenant override is additive only.
8. **Payroll statutory is a separate layer**, jurisdiction-configured, no hard-coded
   thresholds, snapped per payslip (config change never rewrites locked payslips).
9. **Performance evidence never auto-rates.** Task signals shown to reviewers only.
10. **300-line class ceiling** (HRMS plan): folder-per-context splits over long files.
11. **(PENDING, roadmap Phase 5) Export Data: add-on module vs 4th plan.**
    Recommendation: add-on `export.full` grantable on any plan.

## Glossary

- **Central id** — PK in the system DB (`tenants.id`); travels in sessions, job
  payloads, signed URLs. **Tenant-local id** — PK inside a tenant DB (user 1 in
  every tenant). Never mix them.
- **Serviceable tenant** — lifecycle state permitting domain traffic
  (trial/active); provisioning repairs in place instead of illegal transitions.
- **Platform user** — super admin WITHOUT tenant context (`DetectsPlatformUsers`);
  short-circuits notifications/theme. **Impersonating SA** — logged in AS a tenant
  user (`is_super_admin` false); bound to target tenant's plan.
- **Restricted payload** — same keys, masked values + `restricted: true`.
- **HRMS shell test** — `HrmsShellTest` pins pushed-commits, no-debug-leftovers,
  JS-string branches, module/route parity.

## Stale-doc warnings (do not trust blindly)

- `docs/hrms-implementation-plan.md` header says "plan only — no HRMS code exists
  yet": FALSE, HRMS is extensively implemented. Per-phase `Status: done` markers stop
  after ~P2 although later phases landed. The plan's literal paths
  (`Api/Hrms/`, single `OrgService.php`/`EmployeeService.php`) were superseded by
  repo conventions (flat `Controllers/Hrms/`, folder-per-context services).
- `IMPLEMENTATION_TRACKER.md` early phases describe pre-cutover (`TenantScoped`,
  shared-driver) reality — historical, not current.
- `README.md` at repo root is stock Laravel (59 lines) — not the product readme;
  `AGENTS.md` is the live reference.
