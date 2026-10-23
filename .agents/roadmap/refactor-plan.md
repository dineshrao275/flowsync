# FlowSync Refactor Plan (behaviour-preserving)

## Context
FlowSync (Laravel 12 + React 19, ~1,040 PHP files / 194 controllers / 337 JS files, 308 PHP tests + ~42 Vitest specs) grew phase by phase. It is functionally rich and clean of debug leftovers, but the audit found structural debt that raises regression risk for every future change: no static analysis or strict types, controllers doing query work, inline validation, oversized classes/pages, no frontend code splitting, thin frontend tests, and a 1,505-line AGENTS.md that mixes history with rules and duplicates `.agents/`. Goal: a clean, secure, maintainable codebase with **zero functional change**, done in small verified slices, and a permanent standards document so future work (human or AI) follows the same rules by default.

Planning only. No refactoring starts until this plan is approved.

## Audit findings (evidence-backed)

**Backend**
- 26 classes over the project's own 300-line ceiling (StripeGateway 626, PaymentService 584, CustomFieldService 565, TenantDatabaseManager 461, PayrollService 454, ...). `routes/web.php` 451 lines; route files split inconsistently (`routes/web/*`, `hrms_p5.php`, `security.php`); middleware stack repeated by hand.
- 140/194 controllers call `DB::`/Model statics directly (worst: CareerController 14, UserController 10, SystemAnalyticsController 10; AuditLogsController builds a union query inline).
- 200 inline `->validate()` calls across 113 controllers vs 120 FormRequests.
- 233 raw `abort()` calls put HTTP semantics in domain code; 75 broad catches, a few returning null silently (TenantController:153, SsoController:141, ExpireTrials:88).
- Task-visibility scoping duplicated: `Concerns/ScopesVisibleTasks` and `Support/TaskScope`.
- 82 private `present/format` helpers in controllers (candidates for Resources/presenters).
- 0/1,270 files declare `strict_types`; ~92% methods have return types. No phpstan/larastan, no `pint.json`, composer has only `test`.
- Performance: 154 unbounded `->get()` vs 15 `paginate()`; ~51 queries inside loops; no `preventLazyLoading`; only 5 controllers use `DB::transaction`.
- Security: no mass-assignment holes, throttling present on auth. Interpolated raw SQL reviewed: `UnifiedAuditController:74`, `ReportsController:81`, `JqlCompiler:163-164` all interpolate internal literals/whitelisted columns, **not user input** (hardening, not vulnerabilities). Still to verify: public endpoints' throttles (`CspReportController`, `ClientLogController`).
- Clean: no `dd/dump/TODO`, no empty catches.

**Frontend**
- Zero `React.lazy`; `App.jsx` statically imports 104 pages (recharts, tiptap, gsap, dnd-kit all in main bundle).
- 133 files fetch with `api.get` (271 calls); only 11 use `useResource`; 2 use `AbortController`.
- 18 files > 400 lines (ProjectDetail 1067, CmsPages 735, Leave 697, WorkspaceDetail 668 ...).
- Raw primitives: 109 `<select>`, 184 `<button>`, 17 `<table>`, ~74 confirm matches (verify; some may be ConfirmDialog).
- ESLint: `exhaustive-deps` off, 68 `eslint-disable`, no prop validation, no TS. `axios` sits in devDependencies but is used at runtime.
- ~12 inline formatters duplicate `utils/format.js`; 96 permission checks in 46 files not routed through `utils/permissions.js`.
- Tests: ~15% of components/pages have a spec; 2 specs for 114 pages; no e2e tool.
- Good: i18n coverage (4.8k `t()` calls, parity tests), contexts are sane, a11y primitives exist.

**Tests / tooling / docs**
- Strong backend suite (308 files) but slow: per-test sqlite provisioning (`IsolatesDatabase`), full suite ~3h serial; CI shards by alphabetical regex (brittle). CI has Pint, ESLint, Vitest, build, Postgres migration lane; **no static analysis, no e2e**.
- AGENTS.md = 1,505 lines of mostly phase history; same conventions repeated in `.agents/02,03`; no single coding-standards doc; no CLAUDE.md. A tech-debt register already exists (`.agents/roadmap/master-roadmap.md` §23) and partly overlaps this plan; some entries are stale.

## Guiding rules for every phase
1. **Behaviour-preserving only.** A refactor commit never changes behaviour; bug fixes found on the way get their own commit with a test.
2. **Characterize before changing.** If an area lacks tests, add characterization tests first (assert current behaviour), then refactor.
3. **One slice = one commit, pushed immediately** (AGENTS.md delivery rule). Read the full diff, Pint, focused ParaTest (`php artisan test --parallel <files>`), `npm run lint/test/build` when JS changed. Never commit `.env*`/`storage/`, never `--force`.
4. **Phase gate:** full ParaTest suite + Vitest + build + Postgres lane green before the next phase starts. (Note: this intentionally differs from the saved "defer all tests to end" preference because you asked for validation after each phase; per-slice runs stay *focused*, full runs happen at phase gates only.)
5. Cross-cutting touches (routes, middleware, `bootstrap/app.php`, shared config) force a full run before commit.
6. Ratchets over big bangs: new tooling starts with a **baseline** (phpstan baseline file, ESLint warnings-as-ratchet) so existing debt is frozen and only new violations fail CI.

## Phases

### Phase 0: Safety net and standards (no production code changes)
- Write **`docs/coding-standards.md`** (single source of truth; content outline below) and restructure context files (see "Standards document" section).
- Add `pint.json` (Laravel preset + `declare_strict_types` off for now, `ordered_imports`, `no_unused_imports`); add composer scripts `lint`, `analyse`, `test:parallel`.
- Install **larastan** at level 5 with a generated baseline; add to CI as a non-blocking job first, then blocking once the baseline is committed.
- ESLint: turn on `react-hooks/exhaustive-deps` as **warn**, `reportUnusedDisableDirectives`, add restricted-syntax warnings for raw `<select>`, `window.confirm`; record current warning count as the ratchet. Move `axios` to `dependencies`. Add Prettier (`--check` in CI on changed files only).
- Replace alphabetical CI sharding with paratest over the whole suite, or shard by `--testsuite` splits generated from the file list.
- Add `Model::preventLazyLoading(! app()->isProduction())` behind an env flag, initially log-only, to surface N+1 without breaking tests.
- Add **Playwright smoke e2e** (login, create workspace/project/task, move card, open HRMS employee list, subscription page) against the docker dev stack; runs in CI nightly + on demand, not per-commit.
- Capture a **baseline report** (test counts, bundle size via `vite build`, lint/analyse counts, route list snapshot via `route:list --json`, OpenAPI contract) in `.agents/roadmap/refactor-baseline.md`. The route-list and OpenAPI snapshots become regression oracles for later phases.
- Acceptance: CI green with new jobs; baseline committed; no `app/` diff.

### Phase 1: Quick wins and security hardening (low risk)
- `UnifiedAuditController::leg`, `ReportsController::distribution`, `JqlCompiler::string`: switch to whitelist `match`/bound bindings or `Expression` with grammar-wrapped identifiers (`$grammar->wrap()`); pin with `toSql()` shape tests (project rule for raw SQL).
- Audit public routes for throttles (`api/csp-report`, client-log, blog/public site); add `throttle` where missing; `RouteAuthAuditTest` already guards auth.
- Silent `return null` catches (TenantController:153, SsoController:141, ExpireTrials:88): log with context, keep behaviour.
- Delete tech-debt-register "safe to delete now" items after grep verification (unused imports, dead scopes/relations); remove stray `.gitignore` entry `figmapint`; fix `api/api/exports` prefix (**behaviour change: separate commit + test**).
- Frontend: delete unused imports (7 files), fix 3 `<img>` without `alt`, 2 clickable `div/span` to buttons.
- Acceptance: full suite green; larastan baseline unchanged or smaller.

### Phase 2: Backend structure (incremental, highest value)
Order by blast radius and test coverage, smallest first.
1. **Strict types:** add `declare(strict_types=1)` directory by directory (Support/, Enums, Models, then Services, then Controllers) using Rector/`pint` rule in dry-run per directory; fix fallout (int/string coercions) in the same commit per directory; focused tests per directory. Add the Pint rule once all dirs are done to enforce it.
2. **Return types:** add the ~400 missing ones (Rector `AddReturnTypeDeclaration`), per directory.
3. **Validation to FormRequests:** convert the 200 inline `validate()` calls **only for controllers over a size/touch threshold first** (auth, tenant, billing, system), then the rest by domain; request classes live beside the domain (`Http/Requests/<Domain>`), keep error keys/messages identical (translation parity test guards messages).
4. **Thin controllers:** move query/logic out of the worst offenders into services/query objects: `AuditLogsController`, `ReportsController`, `AnalyticsController`, `SystemAnalyticsController`, `CareerController`, `SkillExpectationController`, `UserController`, `TenantController`, `SprintController`. Pattern: controller = validate -> authorize -> one service call -> present (already the stated HRMS convention).
5. **Dedupe task visibility:** make `ScopesVisibleTasks` delegate to `TaskScope` (single definition); guarded by `TaskScopeAccessTest`, `MemberAccessMatrixTest`, `MemberIsolationTest`.
6. **Shared helpers** from the register (P1.8): `seesAll` x4, `clean` x9, `snapshot` x19, `per_page` rule x10, escape-aware `LIKE` x7 into traits/FormRequest rules/`App\Support\Like`.
7. **Presenters/API Resources:** replace the 82 private controller `present*` helpers with presenters in the owning `Services/<Context>/` folder (house style) as controllers are touched; response JSON must stay byte-identical (snapshot assertions in tests).
8. **Domain exceptions:** introduce a small set (`DomainRuleViolation` -> 422 `form`, `NotFound`, `Forbidden`) rendered centrally in `bootstrap/app.php`; migrate `abort()` calls in services only (leave controller-level aborts). Same status codes and `__()` messages.
9. **Split >300-line classes** (26): StripeGateway, PaymentService, CustomFieldService, TenantDatabaseManager, PayrollService, EngagementService, ExpenseService, BackupTenants, JqlCompiler, ApprovalService, HrmsDefaultsProvisioner, InboxService, ... Use the house recipe (orchestrator + collaborators in a context folder, public API unchanged; precedent: TaskService split, `TaskServiceSplitTest`). One class per commit; no signature change. Add a test that fails when a class exceeds the ceiling, with an allowlist that only shrinks.
10. **Routes:** consolidate `hrms_p5.php`/`security.php`/`platform_engines_v1.php` into `routes/web/*`, extract the repeated tenant middleware stack into a named group constant. Gate: `route:list --json` diff must be empty (names, URIs, middleware, order-sensitive priorities), plus `PlatformAccessTest`, `RouteAuthAuditTest`, `OpenApiContractTest`.
11. **Performance:** paginate/limit unbounded `get()` on list endpoints only where the response shape allows (otherwise cap with explicit constant and document); fix loop queries (`NotificationService` `User::find`, `ReportsController` full-task loads, per-row payroll/performance updates) with eager loading / `whereIn` / bulk update; wrap multi-write controller actions in `DB::transaction`. Each fix gets a query-count assertion test. Enable `preventLazyLoading` as exception in tests after the backlog clears.
- Acceptance per slice: focused ParaTest + Pint + larastan (no new baseline entries); per phase: full suite, route snapshot and OpenAPI diff empty, response-shape snapshots unchanged.

### Phase 3: Frontend structure
1. **Route-level code splitting:** `React.lazy` + `Suspense` + the existing app error boundary for the 104 pages; HRMS as a separate chunk group; `manualChunks` for recharts/tiptap/dnd-kit/gsap/datepicker. Acceptance: `vite build` main chunk size reduced vs baseline; Vitest + Playwright smoke green; `ProtectedRoute` regression test intact.
2. **Data fetching:** migrate hand-rolled `useState(loading)+api.get` pages to `useResource/usePaginated` + `Resource/ErrorState` (adds abort handling) in batches by domain, smallest pages first; add a page-level test per migrated batch (loading, error+retry, empty, data).
3. **Primitives enforcement:** replace raw `<select>`/`<button>`/`window.confirm` with `Select`/`Button`/`useConfirm` where the primitive fits (inline-cell selects keep `fieldClass`); flip the ESLint rules from warn to error once counts hit zero.
4. **Hooks correctness:** fix `exhaustive-deps` warnings, remove the 68 `eslint-disable`s one by one (each is a potential stale-closure bug; handle with tests), then make the rule an error.
5. **Split giant components** following the U5 precedent (tab bodies to `components/<area>/*Tab.jsx`, extract `useProjectBoard`-style hooks): ProjectDetail, CmsPages, Leave, WorkspaceDetail, Subscription, App.jsx route table (to `routes/*.jsx` modules), Holidays, Attendance, TaskDetail, Tenants, Statutory.
6. **Dedupe utils:** inline formatters to `utils/format.js` (`formatBytes`, `formatDate`), permission checks through `utils/permissions.js`/`can()`; shared status-colour map; enum mirrors that exist server-side come from the API (project rule).
7. **Typing:** add JSDoc + `// @ts-check` on `services/`, `hooks/`, `utils/`, contexts (cheap, no build change); evaluate TypeScript migration as a separate, optional follow-up (not in this plan's scope).
8. **Tests:** page smoke tests for the top ~25 pages by traffic (render with mocked api, a11y via vitest-axe), context tests, `App.jsx` routing/permission tests. Target: every page has a render test; coverage reported in CI (no hard threshold initially, ratchet upward).
- Acceptance per slice: `npm run lint` (ratchet not exceeded), `npm test`, `npm run build`; per phase: Playwright smoke + manual keyboard/responsive check of touched screens.

### Phase 4: Test-suite health
- Speed: measure `IsolatesDatabase` setup; add a cached template copy per process (already done) and look for tests that re-provision unnecessarily; split the 1,000-line test files by concern; target full-suite wall-clock reduction with paratest at `-p` cores.
- Stability: move to `--testsuite` shards generated from the file list; quarantine and fix any flaky tests (rerun-3x job to detect).
- Add architecture tests (pest-arch style via PHPUnit reflection, as `HrmsEnumColorTest` does): controllers don't use `DB::` outside allowlist, no class > ceiling, FormRequests for new controllers, no `abort()` in services (allowlist shrinks).
- Keep the Postgres lane; add a PG run of the PG-only-risk areas (raw SQL, migrations).

### Phase 5: Documentation and final gate
- Slim AGENTS.md (see below), refresh `docs/runbook.md` and `docs/multi-tenancy-architecture.md` only where code moved; update test counts after the final full run (AGENTS.md rule).
- Final gate: full ParaTest, Vitest, build, Pint, larastan, ESLint (0 errors, warnings ratchet at floor), Postgres lane, Playwright smoke, route/OpenAPI snapshot diff, bundle-size comparison, `tenants:provision` repair on a PG dev tenant.
- Close/refresh the master-roadmap §23 tech-debt register (mark done, delete stale entries).

## Standards document (permanent, auto-loaded)
- **`docs/coding-standards.md`** (new, ~250-350 lines, rules not history): architecture layering (route -> FormRequest -> controller -> service -> presenter; policies for rows, middleware for coarse gates); SOLID applied to this repo (300-line class / 40-line method ceilings, bounded-context folders); naming; `strict_types` + full type declarations; errors (domain exceptions, `__()` + `lang/es.json`, `form` vs field keys); security checklist (signed tenant-scoped downloads, no interpolated SQL, bound params, throttle new public routes, `RouteAuthAuditTest`/persona map entries, GrantCeiling, audit rows, masking); multi-tenant rules (never cross-connection assumptions, `TenantDatabaseManager::using`, PG-vs-sqlite pitfalls); performance (eager load, paginate/cap, no queries in loops, transactions, query-count tests); frontend (useResource, ui primitives, i18n keys, a11y, lazy routes, no raw table/select/confirm); testing pyramid and what to run when (focused ParaTest, never serial full suite); commit/PR rules and definition of done; refactoring rules (characterize first, behaviour-preserving commits).
- **Load it by default:** create `CLAUDE.md` containing `@AGENTS.md` and `@docs/coding-standards.md` imports (CLAUDE.md does not exist today); put a short "Standards" pointer at the top of AGENTS.md; point `.agents/README.md` read order at the new doc and delete the duplicated conventions in `.agents/02` and `03` (replace by links) to remove drift.
- **Slim AGENTS.md** by moving per-phase feature history (HRMS P-by-P notes, Stripe v2 internals, F-phase notes) to `docs/architecture/*.md` and leaving AGENTS.md as: commands, layout map, tenancy core, pitfalls, links. Move-only commits (no wording changes) so nothing is lost; verify with a diff of concatenated content.
- **Enforcement beyond prose:** architecture tests (Phase 4), ESLint/larastan/Pint ratchets (Phase 0), a PR checklist template in `.github/pull_request_template.md`, and a `.claude`-independent pre-commit script (`scripts/precommit.sh`: pint --test on staged PHP, eslint on staged JS) installed via `composer run setup`.

## Verification (how we know nothing broke)
- Per slice: focused `php artisan test --parallel <files>`, `./vendor/bin/pint --test`, `npm run lint && npm test && npm run build` if JS touched.
- Per phase gate: full `php artisan test --parallel --processes=N` (current baseline 2226 tests / 13410 assertions; the only known failure is `HrmsShellTest::test_every_commit_is_pushed`, which passes once pushed), Vitest (86 tests today), Postgres CI lane, Playwright smoke, snapshots (`route:list --json`, `docs/openapi-v1.json`), bundle size.
- Final: all of the above plus a manual walkthrough of demo logins (`superadmin@`, `admin@flowsync.test`, `owner@globex.test`) across TMS and HRMS shells.
- Rollback: each slice is an isolated pushed commit; revert the commit, no migrations in this plan except none planned (any schema change is out of scope).

## Out of scope / decisions needing the owner
- TypeScript migration, switching test framework to Pest, and the `goal_task_links`/`hrms_task_links` table merge (schema change) are deferred; each needs its own plan.
- Feature changes are excluded; the `api/api/exports` prefix fix is the only deliberate behaviour change and ships separately.

## Suggested execution order
Phase 0 -> 1 -> (2 and 3 can run as parallel tracks on separate branches once Phase 0 gates exist) -> 4 -> 5. Estimated slices: ~25 backend structure, ~30 frontend, each small.
