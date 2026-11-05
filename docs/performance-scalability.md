# Performance and scalability audit

Branch `perf/scalability-100k`, based on `development` at `08503611`. Measurements were taken on a developer laptop
(8 cores, local Docker: PHP 8.3 `artisan serve` with 4 workers, `APP_DEBUG` on, PostgreSQL 17 on the same host), so
**absolute numbers are not production numbers**; the before/after ratios and the query counts are the evidence.
Nothing here shows that FlowSync supports 100,000 concurrent users — see "Readiness" at the end.

## How to measure (reproducible)
| Tool | Purpose |
|---|---|
| `scripts/perf/load.sh` | ApacheBench read mix for one logged-in user at several concurrency levels: rps, p50/p95/p99, failures |
| `scripts/perf/query-count.php` | Runs an API call in-process (run it in the app container) and prints queries per connection and repeated statements |
| `tests/load/*.js` (k6) | Existing browse / HR profiles for the 100-tenant seed (`tenants:seed-scale`) |
| `EXPLAIN (ANALYZE)` on a scratch database | `create database x template flowsync_tenant_N`, bulk-insert 300k tasks / 1.2M activities, compare plans with and without a candidate index |

## What the audit found (verified = measured here)
| # | Finding | Evidence | Status |
|---|---|---|---|
| 1 | The middleware chain read the same tenant row up to 9 times per request (and reloaded `subscriptions.plan` per instance) | query counts below | **Fixed** (`Tenant::resolve`) |
| 2 | Dashboard / search lists lazy-loaded issue type, version, components, watchers and assignee roles per task | dashboard 59 queries | **Fixed** (`presentableTaskQuery`) |
| 3 | Project activity feed built an IN list of every task id | 15k binds / 14 ms planning; hard failure past PDO's 65,535 binds | **Fixed** |
| 4 | Board column pages and "my open" lists lacked a matching index | 9.6 ms and 3.4 ms on 300k tasks | **Fixed** (2 partial indexes) |
| 5 | Every open tab polled two endpoints every 30-60 s, hidden or not; TMS-only tenants asked for an HR inbox they cannot have | code + test | **Fixed** |
| 6 | Entry JS chunk 924 kB (280 kB gzip) changed on every deploy | build output | **Mitigated** (vendor chunks, 135 kB gzip per deploy) |
| 7 | Production opcache stat()ed every file per request; event cache not warmed | measured | **Fixed** (env-driven ini, `event:cache`) |
| 8 | Live search boxes fired a request per keystroke (Leads, MediaLibrary, SystemErrors) | code | **Fixed** (debounce hook) |
| 9 | The board returns every top-level task of a project (205 kB / 160 tasks, linear in tasks); the SPA never passes `column_limit` | 160 tasks: 205 kB vs 123 kB with `column_limit=25` | **Open** (needs SPA "load more") |
| 10 | PHP boots the whole framework per request under `artisan serve` | warm in-process 7-17 ms vs 40-66 ms per cold request | **Open** (recommendation 1) |
| 11 | Each request opens two PostgreSQL connections (system + tenant) | connect+select 7.8 ms p50 each | **Open** (recommendation 2) |
| 12 | Sessions, cache and queue live in the central DB | moving sessions/cache off the DB gained ~5% latency locally | **Open**, matters for DB load at scale, not for latency |
| 13 | `(subject_type, subject_id, id)` activities index suggested by the static audit | a task has few activities; not needed | **Rejected** |
| 14 | `LIKE '%x%'` on `employees`, `users`, `tasks.description` without trigram indexes | static review only | **Not measured** — verify with the HR directory on a 5k-employee tenant before adding |

## Measured results
Queries per API call (`query-count.php`, same session, warm app):
| Call | Before | After |
|---|---|---|
| `GET /api/auth/me` | 21 | 15 |
| `GET /api/projects` | 15 | 12 |
| `GET /api/search/global?q=ab` | 29 | 17 |
| `GET /api/dashboard` | 59 | 30 |

Load (`load.sh`, 200 requests per cell, 4 PHP workers, dev stack):
| Endpoint | c | rps before → after | p95 ms before → after |
|---|---|---|---|
| auth/me | 1 | 17.2 → 19.4 | 73 → 56 |
| auth/me | 10 | 51 → 62 | 245 → 203 |
| dashboard | 1 | 11.6 → 14.9 | 102 → 76 |
| dashboard | 10 | 33 → 47 | 401 → 274 |
| dashboard | 25 | 32.5 → 47.2 | 924 → 584 |

The gain is small on `auth/me` because the dev server is CPU-bound by PHP boot, not by queries (finding 10).
Index plans (300k tasks, 15k per project): column page 9.6 → 0.3 ms, my overdue count 3.4 → 0.4 ms, my open list 0.5 → 0.09 ms.
Frontend: entry 280 → 135 kB gzip (plus 145 kB gzip of vendor chunks that stay cached between releases; first-visit bytes are unchanged).
`opcache.validate_timestamps=0` plus config/route/event caches: auth/me 51 → 40 ms at c=1, 64 → 79 rps at c=25.

## Tests
New: `TenantResolveMemoTest` (2), `DashboardQueryCountTest` (3; fails without the change), `TaskHotPathIndexesTest` (2, outcome + rollback),
`NotificationContext` polling cases (3; fail without the change), `useDebouncedValue` (1).
Focused PHP runs (ParaTest): 67 tenant/gate/security tests, 36 task/search tests, 23 activity tests, 39 ratchet/gate tests — all green.
Vitest: 439 passed (103 files). ESLint: 1 error in `UserTooltip.jsx` (`mousePos`) that is already on `development`, no new problems.
Known failures that are **not** from this branch (present on `development`): `TranslationParityTest` (four messages from
`TaskMoveController` / `SystemErrors*` lack `lang/es.json` entries). The full PHP suite was not run, per the project's end-of-work policy.

## Security and tenancy
`Tenant::resolve()` only memoizes within one request/job and is flushed on `RequestHandled`, `JobProcessing`, any `Tenant` or `Subscription`
write and between tests. It never crosses tenants (keyed by id) and mutating code still uses `find()`. No permission, module, product or
subscription check was removed or reordered; the gate tests and `SecurityRegressionTest` pass. The new indexes are per tenant database and
carry no tenant data. A rollback restores the previous state; the index migration is `CONCURRENTLY` on PostgreSQL.

## Recommendations, in order of expected effect
1. **Worker-mode PHP (FrankenPHP or Octane).** The largest single lever: a warm in-process request costs 7-17 ms against 40-66 ms cold.
   Prerequisite: audit every static/singleton for cross-request leakage (`TenantContext`, `TenantDatabaseManager`, `TenantLimits` memo,
   `Tenant::$resolved`, schema probes, `ReportsTo`) and add a "two tenants, one worker" leak test. Do not enable it before that test exists.
2. **PgBouncer (transaction mode) in front of PostgreSQL**, per-database pools, explicit `max_connections`. Removes ~16 ms of connection setup per
   request and prevents backend exhaustion (stock `max_connections` is 100; each in-flight request holds two).
3. **Redis** for sessions, cache and queue (phpredis is not in the image yet; `pecl install redis`). Offloads the central DB; expect a small latency gain.
4. **Board pagination.** Pass `column_limit` from the SPA with a per-column "load more"; the new index makes each page 0.3 ms.
5. **Separate hosts/pools:** web, queue workers (several, with separate queues) and Reverb (`REVERB_SCALING_ENABLED` + Redis, raised file-descriptor limits).
   Managed PostgreSQL with replicas only after the pooler, and only if measurements show read saturation.
6. **Per-tenant limits** at the edge (ALB/Caddy) and, with worker mode, a per-tenant concurrency cap so one tenant cannot hold every worker.
7. **Object storage + CDN** for uploads (`FILESYSTEM_DISK=s3`), which also unblocks running several app hosts.
8. Move `migrate` / `tenants:provision` out of the app entrypoint into a one-shot job before running more than one replica.

## Capacity model (an estimate, not a result)
Assumptions: 100,000 sessions open, a visible tab issues about one API call per 20 s on average (user actions plus the 60 s polling), peak 2x:
about 5,000 rps average, 10,000 rps peak, roughly 12 queries per request, so 60-120k queries per second across the system and tenant databases.
At today's cold cost (~25 rps per core) that is 200-400 PHP cores; with worker mode (~60-140 rps per core, from the warm measurement above) it is
40-170 cores. Databases need pooling and probably several hosts; 100k WebSocket connections need multiple Reverb nodes. These figures must be
replaced by results from the test plan below.

## Load-test plan to run before claiming a number
1. Seed: `tenants:seed-scale --tenants 100 --users 50` (or `tenants:seed-realistic`) on an environment sized like production.
2. Step the k6 profiles (`tests/load/browse.js`, `hr.js`) at 50, 200, 1k, 5k, 10k virtual users, each held 10 minutes; add login, task move and
   comment-write scenarios. Record rps, p50/p95/p99, error rate, CPU/memory per tier, PostgreSQL connections and lock waits, queue lag.
3. Run a noisy-neighbour case: one tenant at 10x the others; the others' p95 must stay within budget.
4. Run a WebSocket soak against Reverb separately (connections, fan-out latency).
5. Only extrapolate toward 100k after at least two points on the curve are linear; document the first saturated resource.

## Readiness
Not ready to claim 100,000 concurrent users. The measured work removes avoidable per-request queries, two index gaps and avoidable traffic, but the
architecture still serves PHP with a cold boot per request from one host and one stock PostgreSQL, and no load test beyond 25 concurrent clients on a
laptop was run. Items 1-3 and 5 above are required infrastructure changes, each needing its own test, before a large-scale test is meaningful.
