---
name: hrms-contributor
description: Add or change an HRMS surface in FlowSync the repo-consistent way — migration, models, services, policies, requests, controllers, presenters, signed downloads, module gates, SPA pages, and tests.
---

# HRMS Contributor Skill

Use this checklist whenever adding a new HRMS context or extending an existing one
in this repo (`/home/drao/Personal/flowsync`, branch `new/hrms-development`).
Conventions reference: `.agents/02-backend-conventions.md`, `.agents/07-hrms.md`.

## 1. Migration (`database/migrations/tenant/`)

- One monolithic tenant migration per phase with the plan's pre-assigned number
  (`2026_MM_DD_NNNNNN_…`). Out-of-order landing is safe (pending-only, filename order).
- Repair-safe: `Schema::hasColumn` guards, `DROP INDEX IF EXISTS`, `whereNull` backfills.
- FK columns on EXISTING tables: raw `ADD COLUMN … REFERENCES` (grammar-quoted via
  `Schema::getConnection()->getQueryGrammar()->wrap()`, never backticks) + separate
  index — never `constrained()` inside `Schema::table()` (destroys sqlite CHECKs).
- `foreignId`: chain `unique()` BEFORE `constrained()` (post-`constrained()` modifiers
  are silently dropped). `down()`: drop index before column.
- Exercise the PG path (`TENANT_DB_DRIVER=pgsql … tenants:provision`); sqlite hides
  PG-only failures (boolean `= 1`, backticks, missing constraints).
- New catalog table seeded at provision? Add a guarded step in
  `HrmsDefaultsProvisioner::provision()` (never a single early-return) + extend
  `tests/Feature/HrmsCatalogTest.php` both directions (catalog key → real column,
  required column → catalog key). No invented facts in starters.

## 2. Models (`app/Models/Hrms/<Context>/`) + enums (`app/Enums/Hrms/`)

- Folder-per-context (D2.16.2). State as backed enums; `color()` returns HEX
  (`#rrggbb`), never Tailwind palette names — pinned by
  `tests/Unit/Hrms/HrmsEnumColorTest.php`.
- `employees.user_id`-style links: unique-nullable for at-most-one; think about
  `nullOnDelete` vs `cascadeOnDelete` explicitly (deleting a manager must not delete
  a department; history rows die with their record, actor FKs null).
- Snapshot enums as plain strings when they describe old rows (new catalog values
  must not require migrating history).

## 3. Services (`app/Services/Hrms/<Context>/`, 300-line ceiling)

- Thin orchestrator + bounded-context classes; split, don't trim comments.
- Per-record answers in `Policies/Hrms/…` (named subclass per model for convention
  discovery); set-scoping (`indexFor`/`casesFor`) in services — same permissions
  both sides. `view` includes self.
- Corrections supersede, never edit (insert new rows, recompute) where audit matters.
- No-decision maintenance (rollups) writes NO audit rows; real decisions log via
  `HrmsAuditLogger` (field names only, values masked).
- PII: `SensitiveFieldRedactor` + same-keys-`restricted:true` payloads; access rows
  via `EmployeeAccessLogger`/`accessed()` only when sensitive data was actually shown.
  Confidential rows invisible without the sensitive permission.

## 4. HTTP (`Requests/Hrms/` → `Controllers/Hrms/` → routes)

- FormRequests for all writes; no `sometimes`-before-conditional-`required`; reject
  (don't silently drop) immutable fields by name.
- Routes inside the tenant stack + `ensure_module:hrms.<domain>` (+ `hrms.core`
  for the shell) + `permission:hrms.view`; literal routes (`reorder`, `expiring`,
  `mine`) BEFORE `{resource}` siblings; verify nested belonging (foreign id → 404).
- Presenters in the service folder; pagination `{current_page,last_page,per_page,total}`;
  feeds ordered by `id` desc.
- Files: signed tenant-scoped downloads ONLY (central id + reader id in signature,
  outside `switch_tenant`, lookup inside `using()`), plus the `flushSession()` +
  `iso_system` regression test. Exports: session routes, per-domain permission,
  `throttle:30,1`, one `accessed(…, Export)` row.

## 5. Subscription & SPA

- New user-facing capability needs a module key? Add to `config/subscriptions.php`
  (+ `module_meta`) AND `resources/js/utils/hrmsModules.js` (shell test fails on
  drift) + sidebar entry + route gate + overview tile via `HRMS_MODULE_ROUTES`.
- SPA in `resources/js/pages/hrms/` (+ shared bits in `components/hrms/`); URL-driven
  tabs; never submit masked values back; pickers list everyone, not report-holders.
- Enums served from PHP (`filterOptions()`), never hardcoded in JS.

## 6. Verify

Focused feature tests for the context + `HrmsCatalogTest` if catalogs touched +
`./vendor/bin/pint --test` (+ `npm run build` when JS changed). Full suite only at
phase end-gate or on cross-cutting changes. Update `.agents/memory/state.md` and the
relevant `.agents/*.md` file(s) in the same commit. Commit + push immediately on
`new/hrms-development` (focused tests + pint green first).
