# FlowSync Coding Standards

The single source of truth for how code is written, reviewed and refactored here. It holds **rules, not history** (history lives in `AGENTS.md` and `docs/`). `CLAUDE.md` imports this file, so AI-assisted work follows it by default. If code and this file disagree, fix one of them in the same change.

Ceilings marked **(ratchet)** are enforced by tooling against a committed baseline (`ClassSizeCeilingTest`, `ArchitectureRatchetTest`, `phpstan-baseline.neon`, the ESLint warning count): existing violations are frozen, new ones fail CI, and the baseline only shrinks.

## 1. Definition of done
- Behaviour is covered by a test that fails without the change (bug fixes: a regression test first).
- `./vendor/bin/pint --test`, the focused `php artisan test --parallel <files>`, and for JS `npm run lint && npm test && npm run build` are green.
- No new static-analysis baseline entries, no new ESLint warnings (ratchet).
- Every new backend message is wrapped in `__()` and added to `lang/es.json`; every SPA string uses a key in `resources/js/locales/{en,es}`.
- `AGENTS.md` / `docs/` updated only where architecture, routes, migrations or deps changed.
- One logical change per commit, pushed immediately. Never commit `.env*`, credentials or `storage/`; never `--force`.

## 2. Architecture and layering
Request path: **route -> middleware -> FormRequest -> controller -> service -> presenter**.
- **Controllers** only validate, authorize, make one service call and present. No query building, no business rules, no `DB::` (ratchet).
- **Services** (`app/Services/<Context>/`) own rules and transactions. A context is a folder: orchestrator + small collaborators; the public API of the orchestrator is stable.
- **Presenters** shape JSON (`Services/<Context>/*Presenter`). Controllers do not hand-build arrays.
- **Authorization:** coarse gates are middleware (`permission:`, `ensure_module:`, `ensure_product:`); per-record answers are policies; set-level scoping lives in services and reads the same permissions as the policy (they must not disagree). Row scoping goes through `TaskScope` / `HrmsScope`, never ad hoc.
- **Validation:** a FormRequest per write endpoint. Field rules key to their field; structural rules use `form`.
- **Errors:** domain rule violations throw a domain exception or `ValidationException` (422 `form`); services do not `abort()`. Never swallow an exception silently: log with context or rethrow.
- Dependencies are injected; no `app()`/facades inside domain logic where a constructor argument works.

## 3. SOLID in this repo
- **S:** a class has one reason to change. Ceiling **300 lines per class, 40 per method (ratchet)**. When a class hits it, extract a collaborator by responsibility (precedent: `TaskService` -> `Tasks/*`), never trim comments.
- **O:** extend by catalog/config/enums (`config/*.php`, `Enums/`), not by new `if` branches on strings.
- **L/I:** small interfaces for gateways (`RecurringGateway`, `CardCaptureGateway`) with a fake for tests.
- **D:** depend on abstractions at integration seams (billing, mail, storage, clock); fakes in tests.

## 4. PHP style and typing
- `declare(strict_types=1)` in every PHP file; full parameter and return types; typed properties; enums for state (hex `color()` if rendered).
- PSR-12 via Pint; imports ordered and unused imports removed (the Laravel preset does this).
- Names: classes `PascalCase` nouns, methods verbs, booleans `is/has/can`; no abbreviations except domain terms (TMS, HRMS, WIP). Migration/table/column names are `snake_case`; route names dotted.
- No `dd`/`dump`/`var_dump`/`TODO`/`FIXME` in committed code (a test guards the HRMS tree).
- Comments explain *why* (a constraint, a trap), never restate the code.

## 5. Security checklist (mandatory for new code)
- **SQL:** bind values; never interpolate request data. Identifiers come from a whitelist `match` and are wrapped with the grammar (`$grammar->wrap()`), not backticks. No backslash-quote in raw SQL text (use `App\Support\Like`). Pin new raw SQL with a `toSql()` shape test.
- **Auth:** every `api/*` route is behind `auth` unless listed in `RouteAuthAuditTest::PUBLIC` after review. A new `super_admin` route needs an entry in `config/platform_access.php`.
- **Public endpoints:** throttled, validated, no tenant data. Never trust client-claimed IP/tenant/user ids; take them from the session or signature.
- **Downloads:** signed, tenant-scoped routes outside `switch_tenant`, resolved inside `TenantDatabaseManager::using()`; never `Storage::url()`. Ship the `flushSession()` + `iso_system` regression test.
- **Mass assignment:** explicit `$fillable`; never `$guarded = []`.
- **Secrets/PII:** encrypted casts, `$hidden` for credentials, `SensitiveFieldRedactor` for HRMS PII, never submit masked values back, audit rows for sensitive reads and admin writes.
- **Roles:** granting is bounded by `GrantCeiling`; defaults/system roles are read-only through the API.
- **Uploads:** type allowlist + size cap + `UploadScanner`; stored on a private disk with generated names.

## 6. Multi-tenancy rules
- One DB per tenant; tenant models carry no `tenant_id`; central models use `CentralConnection`.
- Switch DBs only via `TenantDatabaseManager` (`using()`); never purge the system connection while it is default or in a transaction.
- Queued jobs carry the tenant (payload stamping already exists); mailables snapshot scalars, not models.
- Anything that spans tenants (fan-out search, analytics, exports) is explicit, capped and audited.
- Code must be correct on **PostgreSQL and sqlite**: boolean predicates use `true`, dates use `whereDate`, no sqlite-only or PG-only SQL without a guard; schema changes use the builder or grammar-wrapped raw SQL (see `AGENTS.md` pitfalls on `constrained()`).

## 7. Performance
- Eager-load relations used in loops; no queries inside loops; bulk write with `whereIn`/`upsert`.
- Lists are paginated or capped by a named constant; no unbounded `get()` on user-growing tables.
- Multi-write actions run in `DB::transaction`.
- Add a query-count assertion when fixing or guarding an N+1.
- Index every foreign key and every filtered/sorted column in the same migration.

## 8. Frontend (React SPA)
- Routes are lazy (`React.lazy`) and wrapped in the shared error boundary; heavy libs stay out of the main chunk.
- Fetch with `useResource`/`usePaginated` + `Resource`/`ErrorState` (abortable, retryable); no new hand-rolled `useState(loading)` + `api.get`.
- Use `components/ui` primitives (`Button`, `Select`, `Modal`, `DataTable`, `useConfirm`); no raw `<table>`, no `window.confirm`, no raw `<select>` outside inline cells.
- Pages and components stay under **400 lines (ratchet)**; extract tab bodies and hooks. Permissions via `can()`/`utils/permissions.js`; formatting via `utils/format.js`; enums come from the API, not JS copies.
- Hooks follow `exhaustive-deps`; an `eslint-disable` needs a one-line reason.
- Accessibility: labelled controls (`FormField`), focus management for overlays, 12px minimum text, semantic colour tokens (`text-fg`, `bg-surface`, ...) for new code.
- i18n: no literal user-facing strings; keys exist in `en` and `es` (parity tests).

## 9. Testing
- Pyramid: unit (pure logic) > feature (HTTP + policy + service, `IsolatesDatabase`) > Vitest (hooks, primitives, page smoke) > Playwright smoke (critical paths, nightly/on demand).
- Each endpoint: happy path, 403/404 by role/scope, 422 per rule, tenant isolation. Each migration: outcome asserted, not exit status.
- Run PHP tests **only with ParaTest** (`php artisan test --parallel`); focused files per task, full suite at phase gates or for cross-cutting changes (routes, middleware, `bootstrap/app.php`, shared config).
- Test the contract, not the implementation; no sleeps; fakes over mocks for gateways.

## 10. Refactoring rules
- Behaviour-preserving commits only; a bug found mid-refactor gets its own commit and test.
- Characterize first: if a path is untested, add tests that pin current behaviour, then change structure.
- Keep public signatures, route names/URIs, JSON shapes and messages identical; verify with the `route:list --json` and OpenAPI snapshots.
- Small slices, each verified and pushed before the next; revert beats patching a bad slice.
- Move-only commits (file moves, doc splits) change no content.

## 11. Git and review
- Conventional-style subject (`feat|fix|refactor|test|docs|chore(scope): ...`), body states why and what was verified (and says so when a full run was needed).
- Review checks: layering, types, security checklist, tenant/PG safety, tests, i18n, docs.
