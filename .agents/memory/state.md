# Living State (auto-updated on every feature task)

> Rule: append/change with date + commit ref on EVERY task that moves counts,
> schema, routes, modules, or security posture. Never silently rewrite history.

## Current

- Date: 2026-10-06 · Branch: `new/hrms-development` · Gate: **1372 tests / 6829 assertions** (Phase 1 end-gate: Unit 66/107, non-Hrms 394/2900, Hrms 912/3822, 1 pre-existing env skip).
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

## Phase 1 report (2026-10-06) — COMPLETE, gate green

- Implemented: C1 full domain stack on HRMS group; C2 named-reader + show-policy
  Gate on all 4 download paths; C3 photo reads to `local` disk; SVG removed +
  tenant-mime clamp; uniform forgot-password + new `password.reset` redirect route;
  `max:72` passwords; throttles on impersonate/users-store/subscription/tenants;
  `db_*` hidden; restrictive `config/cors.php`; `.env.example` session guidance.
- Found while fixing: forgot-password 500 for every real address (missing route —
  fixed); signed asset-doc route lived INSIDE the authed group despite its
  "outside" comment (moved out — would have 401'd all fresh-tab invoice downloads).
- DB migrations: none. Env vars: optional `FRONTEND_URL` (CORS); production
  `SESSION_ENCRYPT/SESSION_SECURE_COOKIE=true` guidance.
- Verification: full suite Unit 66/107 + non-Hrms 394/2900 + Hrms 912/3822 (1
  pre-existing env skip); pint clean on all touched files (3 unrelated
  pre-existing pint failures left alone); no JS changes → no build needed.
- Remaining risks: tenant-aware reset broker; stronger password policy;
  login-enumeration keys; domain write-throttles; shared-PG-role; asset-view vs
  document-view for invoice downloads (open product decision — route unminted).

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
