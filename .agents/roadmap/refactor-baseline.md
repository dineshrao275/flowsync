# Refactor baseline (2026-10-23, branch chore/code-cleanup)

Regression oracles for `.agents/roadmap/refactor-plan.md`. Numbers only move down (or stay) unless a phase says otherwise.

| Metric | Baseline | Tool |
|---|---|---|
| Larastan level 5 errors (frozen in `phpstan-baseline.neon`) | 1735 | `composer analyse` |
| ESLint | 0 errors / 141 warnings | `npm run lint` |
| Routes | 811 (`.agents/roadmap/snapshots/routes.txt`) | `scripts/route-snapshot.sh --check` |
| Main JS chunk | 3,076 kB (835 kB gzip), no lazy routes | `npm run build` |
| PHP tests | 2226 tests / 13410 assertions (AGENTS.md gate) | `php artisan test --parallel` |
| Vitest | 86 tests / 20 files per AGENTS.md | `npm test` |
| Pint | passing | `composer lint` |
| API contract | `docs/openapi-v1.json` | `OpenApiContractTest` |

Routine for any structural change: `scripts/route-snapshot.sh --check` must print "unchanged" (use `--update` only for deliberate route changes, in their own commit).

## Progress (2026-10-23)

| Phase | Commit scope | State |
|---|---|---|
| 0 | standards doc, CLAUDE.md, pint.json, larastan baseline, route snapshot, ESLint warn rules, PR template | done (Playwright, Prettier, lazy-loading detector not added) |
| 1 | SQL identifier hardening, logged silent catches | done |
| 2 | AuditFeed extraction, enum strict_types, ClassSizeCeilingTest | subset |
| 3 | all routes lazy, axios to dependencies; main chunk 3,076 -> 866 kB | subset |
| 4 | ArchitectureRatchetTest (DB::, inline validate, abort in services) | subset |
| 5 | AGENTS.md 1,508 -> 968 lines (HRMS history moved verbatim to docs/architecture/hrms-history.md) | subset |

**Full ParaTest suite not run since the baseline above (deferred by the owner to the end).** Test counts in AGENTS.md are therefore unverified; the new unit tests add 5 (2 size ceiling + 3 architecture ratchets).

### Second pass (focused tests only)
Done: membership row scope single-sourced in `TaskScope`; 59 first-statement inline validations moved to FormRequests (inline count 204 -> 145, 482 focused tests green); route files `hrms_p5`/`security` moved under `routes/web/` (snapshot identical); 14 unused lint directives removed + exhaustive-deps fixed, `npm run lint` now fails above 123 warnings; AdminActivity and OrgChart on `useResource` with specs (Vitest 197); user lookups batched in the digest/survey loops; `PostgresDumper` extracted from `BackupTenants`; strict_types on all new classes; larastan baseline refreshed (still 1,735).

### Third pass and full-suite verification (2026-10-23)
**Full ParaTest run on HEAD: 2345 tests / 15011 assertions, 1 failure** (`HrmsShellTest::test_every_commit_is_pushed`, passes once the branch is pushed). Vitest 213 tests / 48 files, larastan clean (baseline 1,734), route snapshot unchanged (811), build green. Wall clock for the full suite: ~62 min on 8 processes.

Done in this pass: `declare(strict_types=1)` in every `app/` file, enforced by Pint (its first full run found and fixed real bugs: query-string ids passed to `?int` parameters in three HR controllers, a float return in `StatutoryResolver`); all 5,012 methods have return types (the "400 missing" estimate was wrong, 10 were); three failures that predated the work fixed (test isolation, a stale title, live Stripe keys leaking into tests via `.env`); extractions `StripeWebhookParser`, `ProviderTrials`, `FieldValueNormalizer`, `PostgresDumper`, `AuditFeed`; React splits of CmsPages (BlockEditor, PageFormModal), Tenants, Analytics, Org, Dashboard; four more pages on `useResource` with specs (AdminActivity, OrgChart, AuditTimeline, FeatureManagement). Tools: `scripts/route-snapshot.sh`, `scripts/refresh-size-ratchet.py`, `scripts/extract-components.py`, `rector.php` (strict return-type rules only; nothing left to add).

### Remaining backlog
- Backend: 145 inline `validate()` calls (not first-statement or dynamic rules; converting could reorder 403/422); 82 controller presenters; service `abort()` calls are download-serving 404/403 and a 422 whose JSON shape a `ValidationException` would change, so they stay (frozen by the ratchet); 25 more files over 300 lines; unbounded `get()` list endpoints; larastan baseline triage (1,734).
- Frontend: ~104 pages still hand-roll fetching (the rest are polling or reload-after-mutation pages that need care); 108 raw `<select>` and 15 raw `<table>` (lint ratchet 123); ProjectDetail (1,067), Leave, WorkspaceDetail, Subscription, Holidays, Attendance, TaskDetail, Statutory still over 400 lines; Playwright smoke and Prettier not added (cannot be run or verified in this environment).
- Docs: slim the other long AGENTS.md sections (Stripe v2, F-phase, TMS phases) the same move-only way.
