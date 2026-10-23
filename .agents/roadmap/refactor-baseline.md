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

### Remaining backlog (each needs the full-suite gate or a browser)
- Backend: strict_types for Models/Services/Controllers/Support; ~400 missing return types; 204 inline validate() calls -> FormRequests; thin controllers (Reports, Analytics, SystemAnalytics, Career, SkillExpectation, User, Tenant, Sprint); ScopesVisibleTasks -> TaskScope; 82 controller presenters; domain exceptions replacing service abort(); split the 25 files over 300 lines; route file consolidation; unbounded get()/loop queries; larastan baseline triage (1,735).
- Frontend: useResource migration (~110 pages), raw select/button/confirm replacement, 141 lint warnings (exhaustive-deps), split 18 files over 400 lines, page smoke tests, Playwright, Prettier.
- Docs: slim remaining AGENTS.md sections (Stripe v2, F-phase, TMS phases) the same move-only way.
