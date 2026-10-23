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
