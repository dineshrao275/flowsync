# `.agents/` — AI Context Pack for FlowSync

> Read this first. This directory is the onboarding manual for any AI agent (or human)
> working on this repo. It is organized so each file can be updated independently.

## Read order for a cold agent

1. `00-product-overview.md` — what the product is, who uses it, environments.
2. `01-architecture.md` — tenancy model and request lifecycle. **Read before touching any backend code.**
3. `02-backend-conventions.md` + `03-frontend-conventions.md` — how code is shaped here.
4. `04-database.md` — the two-database inventory and migration rules.
5. `05-auth-rbac.md` — auth, roles, impersonation.
6. Domain files as needed: `06-tms.md`, `07-hrms.md`, `08-subscriptions.md`, `09-realtime-jobs-mail.md`.
7. `10-security.md` — mandatory before writing any auth/upload/download/billing code.
8. `11-testing-ops.md` — how to verify and ship.
9. `12-glossary-decisions.md` — ADRs and terms.

## File map

| File | Covers |
|---|---|
| `00-product-overview.md` | Product, users, demo logins, environments, scale facts |
| `01-architecture.md` | Isolated tenancy, middleware chain, connection management |
| `02-backend-conventions.md` | Layering, FormRequests, presenters, policies, PHP gotchas |
| `03-frontend-conventions.md` | SPA routes, providers, UI primitives, deep links |
| `04-database.md` | System vs tenant DBs, tables, indexes, migration rules |
| `05-auth-rbac.md` | Login routing, roles/permissions, impersonation |
| `06-tms.md` | Workspaces, projects, tasks, collaboration, time, search |
| `07-hrms.md` | All HRMS contexts, shared primitives, PII rules |
| `08-subscriptions.md` | Plans, limits, module gates, onboarding, lifecycle |
| `09-realtime-jobs-mail.md` | Reverb/Echo, queued jobs, mail status |
| `10-security.md` | Threat model + verified findings + rules for new code |
| `11-testing-ops.md` | Test strategy, commands, perf budgets, deployment |
| `12-glossary-decisions.md` | ADRs, glossary, stale-doc warnings |
| `skills/hrms-contributor/SKILL.md` | Claude-style skill: adding HRMS surfaces consistently |
| `memory/state.md` | Living state — auto-updated on every feature task |
| `roadmap/phase-plan.md` | The 8-phase implementation plan (sequential gates) |

## Update discipline (mandatory)

- Touch the relevant file(s) in this directory on **every** change to: architecture,
  migrations, middleware, routes, key components, npm/Composer deps, or test counts.
- `memory/state.md` is updated on **every feature task** (date + commit ref + what moved).
- Facts cite sources as `path:line` where load-bearing. If a fact cannot be confirmed
  against the tree, mark it `UNVERIFIED` — never assert it.
- Higher authority on conflicts: the code itself, then `AGENTS.md` at repo root,
  then `docs/multi-tenancy-architecture.md` and `docs/hrms-implementation-plan.md`
  (note: the HRMS plan doc is stale — see `12-glossary-decisions.md`).

## Verified snapshot (2026-10-05, branch `new/hrms-development`)

- Laravel `^12.0` + React `^19.3.0`, Vite `^7.0.7`, Reverb `^1.12`, PHP `^8.2` (host PHP 8.3).
- 12 system migrations, 35 tenant migrations; 137 Feature test files; 43 HRMS SPA pages.
- `factories/` holds only stock `UserFactory.php`. Zero `Mail::` usage in `app/`.
- Gate quoted in `AGENTS.md`: **1372 tests / 6829 assertions** (Phase 1 end-gate, 2026-10-06).
