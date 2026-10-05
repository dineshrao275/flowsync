# Living State (auto-updated on every feature task)

> Rule: append/change with date + commit ref on EVERY task that moves counts,
> schema, routes, modules, or security posture. Never silently rewrite history.

## Current

- Date: 2026-10-05 · Branch: `new/hrms-development` · Gate: **1363 tests / 6796 assertions** (per `AGENTS.md`).
- `.agents/` pack created (16 files). No app code changed in that commit.

## Shipped vs missing (against the 8-phase roadmap)

| Area | State |
|---|---|
| TMS (workspaces/projects/tasks/collab/time/search) | Shipped, complete |
| HRMS contexts (employee→surveys, inbox, analytics, task-links) | Shipped, complete |
| Subscriptions (plans/limits/gates/onboarding/self-service) | Code-complete, no billing |
| Notifications | In-app only; email NOT wired (`MAIL_MAILER=log`, zero `Mail::` in `app/`) |
| Exports | 2 HRMS CSVs only; no TMS/general export |
| Payments | NONE (no Stripe/Razorpay code) |
| Factories | Stock `UserFactory` only |
| Security criticals (`10-security.md` C1–C3) | OPEN — roadmap Phase 1 |

## Pending owner decisions

1. New application name (factories/rename phase) + infra-rename scope.
2. Stripe-first? locale→provider rule, prices/currencies, test-vs-live staging.
3. Export Data: 4th plan vs add-on module (recommended: add-on `export.full`).
4. SMTP provider + from-address; immediate vs digest board mail.
5. HRMS single-tab + sub-tabs IA approval.
6. Redis optional (env-driven) vs DB cache/queue only.

## Log

- 2026-10-05 — `.agents/` AI context pack created (README, 00–12, hrms-contributor skill, this file). Verified: routes/web.php group lines, bootstrap/app.php aliases+priority, 12/35 migrations, 137 Feature files, 43 HRMS pages, plans/modules, zero `Mail::`, demo logins, env mail/queue/cache/session keys.
- 2026-10-05 — `.agents/roadmap/phase-plan.md` written (8 phases, sequential gates, per-phase affected files/DB/API-UI/risks/tests/exit gates). No app code changed.
