# HRMS Completion Track (Phases 22–26)

Audited 2026-10-14: plan Phases 0–21 are shipped — no stubs, no broken
controller references, no missing pages. What reads as "pending" is the
list below, verified in code (see each item's file:line).

## Pending list

- **A1 `hrms.talent` — sold but unshipped.** Catalog (`config/subscriptions.php:50,95`),
  permissions (`config/permissions.php:203-204`), plan sales (business/enterprise/
  hrms-enterprise) exist; zero routes/controllers/pages/tiles. Plan scope:
  `talent/*` reviews/ratings/calibration, `ensure_module:hrms.talent`.
- **A2 `hrms.exemptions` (Tax Exemptions) — catalog-only.** Zero surface anywhere;
  not in the §3.5 gate table. Overlaps statutory declarations (ship under
  `hrms.payroll.statutory`, `routes/web/hrms_payroll.php:93-98`).
- **B Shipped pages showing "Planned" tiles** — `HRMS_MODULE_ROUTES`
  (`resources/js/utils/hrmsModules.js:59-83`) has 10 entries; these existing
  pages render inert: Compensation, Payroll, Statutory, Performance,
  Engagement, Expenses, Assets, Inbox. (`hrms.attendance.remote`
  intentionally tile-less; `hrms.leave.exemption` is a `Leave.jsx` tab.)
- **C §3.5 gate deviations** — `hrms.compensation` / `hrms.payroll` gated
  nothing (routes carried `hrms.core` only). → Phase 22.1.
- **D Orphan** — `ApprovalChains.jsx` (working `/hrms/approvals/chains` API,
  no route/NAV entry). → Phase 23.
- **E Hardening** — `hrms:leave-consistency --repair` (R5, missing);
  impersonation attribution in audit writers (R7); `request_id` in audit
  rows/jobs; R14 explainer SPA panel; org-tree roving + touch reorder;
  `attendance/today` 404 diagnosis. → Phases 25–26.

Out of scope (plan Part 9): ATS, recruitment, LMS, transfer/promotion
workflows, applicant portals, statutory filing, bank-file export,
graphical org-chart.

## Phase log

- **22.1 ✅ compensation/payroll gates** — `routes/web/hrms_payroll.php`
  split: reads+runs+my-payslips → `hrms.payroll`, compensation writes →
  `hrms.compensation` (both beside `hrms.core`, §3.5 order). SPA routes +
  `HRMS_NAV` carry the same modules (route↔tab parity pinned). No plan
  changes: `ModuleCatalogTest` pins payroll as Enterprise-only, so
  Pro/Business/hrms-professional/hrms-starter tenants lose pay access
  they were never sold (intended). `ModuleGateTest` +2.
- **22.2** — tiles for the 8 shipped tile-less modules; no shipped page
  shows "Planned". (pending)
- **22.3** — resolve `hrms.exemptions`: recommended delist (precedent:
  `api`/`audit_export`). (pending)
- **23** — wire or delete `ApprovalChains.jsx`. (pending)
- **24** — Talent, sub-tasks 24.1 migration → 24.2 service/policies →
  24.3 HTTP → 24.4 SPA → 24.5 notifications. (pending)
- **25–26** — item E. (pending)

Conventions: one reviewed commit per task, focused tests per task, full
suite at the end-gate, `AGENTS.md` counts on every count-moving change.
