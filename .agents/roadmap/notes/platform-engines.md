# Track notes: personal API tokens (branch `track/platform-engines`)

Task: **P2.7** (self-built personal API tokens + `/api/v1` bearer surface + idempotency + per-token
throttle + integration log). Merged as `e36cd6a` ("Merge track/platform-engines (P2.7 personal API
tokens, idempotency, per-token throttle)") with additive conflict resolution in `bootstrap/app.php`
(middleware imports + alias list) and `tests/Feature/RouteAuthorizationAuditTest.php` (the legitimate
public-route table — the `api/v1/*` token routes were added to the allowlist, matching the 
`AuthenticateApiToken` door). The track's commit `d3292a6` is in main via the merge. Tests were
written but not executed at merge time; the first sandbox run of `tests/Feature/ApiTokenTest.php`
found **test-isolation bugs in the unrun tests** (queries against `iso_system` while the tables are
tenant-scoped), fixed in main commit `80826be` (connect the acme tenant before `ApiToken` lookups;
add `fingerprint` to `ApiIdempotencyKey::create`). Final sandbox `ApiTokenTest`: **17 passed**.

## What shipped

- **Token format:** plaintext `fst_<central tenant id>_<40 random chars>`; only the SHA-256 hash is
  stored (`ApiToken::hash()`; `fake`-able cast). `ApiTokenService::tenantIdOf()` reads the claimed
  tenant from the prefix; `resolve()` matches inside the already-connected tenant DB.
- **Model** `app/Models/ApiToken.php`: `user_id`, `name`, `token_hash`, `token_hint` (last 4),
  `abilities` (JSON), `can_write`, `rate_limit`, `expires_at`, `revoked_at`; scopes for alive/expired;
  `can()` / `token_can`-style ability check.
- **Middleware** `app/Http/Middleware/AuthenticateApiToken.php` (`api_token`): the bearer door for
  `/api/v1` — decodes the tenant from the prefix, connects that tenant's DB *before* the hash lookup,
  loads the owner as the request user, refuses non-safe verbs for read-only tokens, logs every call
  (incl. refusals) to `integration_logs`, and answers the **same 401** for unknown/revoked token and
  suspended tenant (probe-indistinguishable). `TokenCan` (`token_can:<ability>`) gates further.
- **Idempotency** `app/Http/Middleware/EnforceIdempotency.php` (`idempotent`): keys POSTs on the
  `Idempotency-Key` header + token + payload fingerprint → `api_idempotency_keys` (TTL
  `config('api.idempotency_ttl_hours')`, 24h); a replayed key returns the stored response.
- **Per-token throttle** wired via `RateLimiter::for('api-token')` in `AppServiceProvider`
  (`config('api.default_rate_limit')` 60/min default, `max_rate_limit` 600 cap).
- **Controllers:** `ApiTokenController` (session side: `GET|POST api/api-tokens`,
  `DELETE api/api-tokens/{apiToken}`, `GET api/api-tokens/{apiToken}/logs`; gated
  `ensure_module:api` + `permission:api.manage` in the tenant domain group); `ApiV1Controller` (`me`).
  The rest of `/api/v1` **reuses the session controllers** (`ProjectController::indexAll`,
  `TaskController::index|store`, `SearchController::tasks`, `EmployeeController::index`) so policies
  and row scopes judge the real owner — the token never outruns the user.
- **Routes:** `routes/web/platform_engines.php` (session token management, inside the domain group)
  and `routes/web/platform_engines_v1.php` (bearer surface, required at top level in `routes/web.php`,
  **outside** `switch_tenant`/`auth`/`tenant`; stack `api_token → tenant_context → onboarding_complete
  → ensure_module:api → throttle:api-token`). `/api/v1` endpoints: `me`, `projects`,
  `projects/{p}/tasks` (GET + idempotent POST), `search/tasks` (needs `global_search`),
  `hrms/employees` (needs `hrms.core` + `token_can:hrms.view`).
- **Config:** `config/api.php` (prefix, limits, idempotency/log TTLs, the `abilities` catalog —
  exactly the slugs a `/api/v1` route may gate on), `config/permissions.php` (`api.manage`),
  `config/subscriptions.php` (module `api` re-added to every default plan, with a migration
  `2026_11_02_000033` backfilling existing plans).
- **SPA:** `components/settings/ApiTokens.jsx` (create exposes the plaintext once; list + per-token
  logs + revoke) mounted on the Settings page.
- `ImpersonationGuard` blocks api-token management while impersonating.

## Files changed (merge diff)

32 files +1200/−11, incl. new `app/{Controllers/ApiTokenController,ApiV1Controller,
Middleware/AuthenticateApiToken,EnforceIdempotency,TokenCan,Models/{ApiIdempotencyKey,ApiToken,
IntegrationLog},Requests/ApiTokenStoreRequest,Services/Api/ApiTokenService}.php`,
`config/api.php`, migrations `2026_11_02_000050_create_api_token_tables` +
`2026_11_02_000033_add_api_module_to_plans`, routes `web/platform_engines{,_v1}.php`,
`resources/js/components/settings/ApiTokens.jsx`, `tests/Feature/ApiTokenTest.php`.
Merge conflict touches: `bootstrap/app.php`, `tests/Feature/RouteAuthorizationAuditTest.php`.
Post-merge test-isolation fix (patch to `80826be`): `ApiTokenTest` connect-the-tenant + `ApiIdempotencyKey::create` fingerprint.

## AGENTS.md / roadmap deltas applied by the integrator

- New AGENTS.md section `## API tokens (P2.7, track/platform-engines)` (after Request correlation).
- `master-roadmap.md`: progress line 484 adds `P2.7 ✅ (track/platform-engines merged ... tests green
  after fixing the un-run track's test-isolation gaps)`; P2.7 row marked `**shipped, non-Sanctum
  self-built; ApiTokenTest green**`; §8 gap list trims "API tokens" from the "shared engines" gap.