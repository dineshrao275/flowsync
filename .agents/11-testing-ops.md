# 11 — Testing & Operations

## Test strategy

- Isolation via `Tests\IsolatesDatabase` (`setUpTraits` hook): file-backed
  `iso_system` sqlite + per-tenant sqlite files under `tenancy.tenant.db_path`,
  migrated + seeded; default connection left on acme. Never `connectSystem()` while
  default is `:memory:` sqlite (purge wipes the test DB). Wild
  `Config::set('tenancy.driver','isolated')` is NOT enough (singleton cached).
- **Feature-level per task, full suite at the end.** Per-commit full runs cost
  ~14 min and bury regressions. Re-run `php artisan test` before a commit only when
  the task touched something cross-cutting (`routes/web.php`, `bootstrap/app.php`,
  `TenantLimits`, shared `config/*`, middleware) — and say so in the commit body.
- Final gate per phase: full suite in Hrms/non-Hrms chunks + `Isolated/`, zero
  omitted files. Gate quoted in `AGENTS.md` `## Commands` (currently **1363 tests /
  6796 assertions** — update that line on every count-moving change; counts verified
  by the final run).
- Shell guards: `HrmsShellTest::test_every_commit_is_pushed` (commit left local-only
  fails the suite), `test_the_hrms_tree_is_free_of_debug_leftovers` (no
  `dd()/dump()/console.log/TODO` in HRMS tree). JS-string pins (notification
  branches, module routes) asserted server-side — no JS runner exists.
- Inline test routes through `tenant`/`tenant_context` need the `web` group
  ("Session store not set on request" otherwise). `./vendor/bin/pint --test`
  before every commit; `npm run build` when JS changed.

## Commands (see `AGENTS.md` `## Commands` — authoritative)

`php artisan test` · `npm run build` / `npm run dev` · `./vendor/bin/pint` ·
`php artisan migrate:fresh --database=system --path=database/migrations/system --seed` ·
`php artisan tenants:provision [--tenant=ID]` · `php artisan tenants:seed-scale
[--tenants --users --workspaces --projects --tasks --no-related --seed --dry-run]` ·
`composer run dev` (serve + queue + pail + Vite + Reverb).

## Performance budgets

Every request targets **<3 s**; async/background jobs for mail, exports, payroll
runs, analytics rollups. Known hot paths: `SystemAnalyticsController` cold fan-out
(100 tenants × 4 counts, 300s cache — first SA load after expiry is seconds);
payroll generation loop (per-employee queries, needs chunking); uncached
dashboard/reports/search/board + `TenantLimits::effective` per create + `me()`
payload; DB-backed cache+queue+sessions on one PG (Redis is the cheapest win,
env-driven with database fallback); board-move write amplification; 30s
notification/inbox polls.

## Deployment requirements

Native host: Apache `mod_php` vhost (`public/.htaccess` front controller must exist;
`storage` + `bootstrap/cache` writable by `www-data`); PHP 8.3 CLI; PG 16 with a
`CREATEDB` role (`TENANT_DB_PG_ROLE` shared-role fallback documented in
`01-architecture.md`); systemd user units `flowsync-reverb`/`flowsync-queue`
(logs `storage/logs/{reverb,queue-worker}.log`, `loginctl enable-linger`).
Env: `APP_DEBUG=false`, `SESSION_ENCRYPT=true`, `SESSION_CONNECTION=system`, real
`APP_KEY`, explicit CORS, real SMTP (currently log-driver placeholder), Reverb +
`VITE_REVERB_*` for realtime, `ONBOARDING_ENABLED` for public registration.
Docker legacy: `docker-compose build && up -d`, envs in `.env.docker`.

## Delivery

Commit + push every task on `new/hrms-development` — never wait to be asked. Before
committing: read the full `git diff` (new files included), run focused feature
tests + `pint --test` (+ `npm run build` when JS changed), `git add` intended paths
only, `git commit`, `git push` immediately. Never commit `.env*`, credentials, or
`storage/`; never use `--force`.
