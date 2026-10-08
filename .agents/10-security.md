# 10 — Security (mandatory before auth/upload/download/billing code)

## Phase 1 resolutions (2026-10-06, commit pending full-suite gate)

- **C1 FIXED:** HRMS group now runs the full domain stack (`routes/web.php:363`).
  Probe: 500 → 200 on production-fresh connection; unauthenticated → 401;
  platform SA → 403 preserved. Pinned in `SecurityRegressionTest`.
- **C2 FIXED:** all four download paths require the named reader from the
  signature + `Gate::forUser($reader)->authorize('view', $record)` (exact JSON-show
  rule): `AttachmentController::download`, `DocumentDownload` (covers asset invoice
  docs), `EmployeePhotoService::stream` (covers photo route). URLs minted with
  `actor` at every call site. Anonymous hand-signed links → 403 (pinned).
  Behavior note: pre-existing ≤1h links without `actor` stop working on deploy.
- **C3 FIXED:** photo reads moved `public` → `local` disk (no files existed on
  either disk; no upload endpoint writes photos today — `photo_path` is a seeded
  string). Public-disk bypass closed; photo tests now fake `local`.
- **SVG removed** from both allow-lists; tenant `allowed_mimes` setting clamped to
  the server list (narrow-only). **Forgot-password uniform 200** (oracle closed);
  **missing `password.reset` route defined** (was 500 for every real address —
  redirect to SPA `/app/reset-password`). **Passwords `max:72`** (bcrypt CPU-DoS)
  in register/reset/admin-create. **Throttles added:** impersonate start (10,1) /
  stop (30,1), users store (30,1), my-subscription writes (10,1), tenants store
  (10,1). **Tenant JSON hides all `db_*`**. **CORS:** restrictive `config/cors.php`
  (same-origin + `FRONTEND_URL`, credentials on) over the already-global
  `HandleCors`. **`.env.example`:** production session-cookie guidance.
- **Deliberately deferred (documented residual):** tenant-aware reset broker
  (needs mail infra — Phase 4); stronger password policy (would break the
  `password` demo/test corpus — needs migration plan); login-enumeration keys
  (`tenant` 422 is a feature); write-throttles on high-frequency domain endpoints;
  shared-PG-role trade-off; `TenantLimits::hasModule` null-bypass review.

Threat model: multi-tenant SaaS, one DB per tenant. Cross-tenant leakage is the
top risk; the Wide-open HRMS group and under-authorized downloads below are the
current criticals (verified 2026-10-05; fix in roadmap Phase 1, criticals first).

## Critical

1. **HRMS route group lacks auth/tenant middleware** (`routes/web.php:363`:
   `['tenant_context','onboarding_complete']` vs domain group `:233` with full
   `['switch_tenant','auth','tenant',…]`). No `Authenticate` (`$request->user()`
   null), no `SwitchTenant` (binding runs on stale default connection → 500s with
   SQL details when `APP_DEBUG=true`, or wrong-DB reads; stale `TenantContext`
   leaks cross-tenant in workers/tests). Fix: match the domain group; verify
   unauthenticated `GET /api/hrms/employees` → 401/302, not 403/500.
2. **Signed downloads under-authorized.** `AttachmentController::download` checks
   only tenant-exists + row-exists + file-exists (no `tasks.view`/membership);
   non-confidential `EmployeeDocument` early-returns past the reader check;
   `EmployeePhotoService::stream()` has ZERO permission check (actor logged, not
   authorized). Apply the `PayslipDownload` pattern everywhere (anonymous denied,
   self-or-privileged); shorten TTL for sensitive types.
3. **Employee photos on web-accessible `public` disk** (`config/filesystems.php`
   `public` disk + `public/storage` symlink): direct `GET /storage/<path>` needs no
   signature when the path is known/guessable; no photo upload validation found.
   SVG accepted ⇒ stored XSS executes inline (signed-route
   attachment-disposition mitigation bypassed). Fix: private disk + signed serving +
   strict image validation (no SVG).

## High

4. **SVG (+ zip/html-adjacent svg/json/md) in upload allow-lists**
   (`AttachmentStoreRequest`, `DocumentUpload`): ext+MIME only, no sanitization/CSP/
   scan; tenant-overridable `hrms_settings.documents.allowed_mimes` must be validated
   against a server allow-list (never php/phar/phtml). No `storage_bytes` /
   `attachments_per_task` quota on the upload path (`assertQuota` covers
   users/workspaces/projects/tasks/employees only) → disk-fill DoS.
5. **Password-reset broker on wrong DB** (`Forgot/ResetPasswordController`, public
   routes outside `switch_tenant`): queries central `users` (SAs), so tenant users
   likely never receive mail; SA/tenant email collision leaks account location via
   response codes. Fix: tenant-aware broker via `TenantUserRouting` + uniform message.
6. **Weak login hardening:** `min:8` only (no `Password::min(12)->mixedCase()…`);
   no `max:72/255` (unbounded password into `bcrypt(12)` = CPU DoS);
   throttles only on login/forgot/reset/register/punch/export/respond — none on
   impersonate, users/store, subscription writes, task/comment/attachment writes.
7. **Session/transport defaults:** `SESSION_ENCRYPT=false`, `SESSION_SECURE_COOKIE`
   unset, `APP_URL=http://localhost`, no `config/cors.php` (implicit same-origin).
   Pin `SESSION_CONNECTION=system` (+ cache/queue) in prod; ship explicit CORS.
8. **Tenant DB credential surface:** `db_password` encrypted + hidden (good), but
   `db_user/host/port/name` fillable + visible — hide all `db_*` from serializers;
   shared-PG-role fallback reuses the system password (one-tenant compromise = all;
   documented CREATEROLE trade-off — prefer per-tenant roles, rotate via provision).
9. **Enumeration oracles:** distinct login keys (unknown email vs multi-tenant 422
   vs suspended), SA fan-out timing in `GlobalSearchController`; keep responses
   uniform and constant-time.

## Medium (harden soon)

`.env.example` ships `APP_DEBUG=true` + blank `APP_KEY`; `Tenant` over-broad
`$fillable`; `ImpersonationStartRequest.tenant_id` lacks `exists:tenants,id` + no
throttle on stop; channel auth has no suspended-tenant check; SA bypass in
`EnsurePermission`/`AppServiceProvider` relies on login-replaces-user — add
explicit `&& ! TenantContext::impersonating()`; `TenantLimits::hasModule` null-bypass
must stay fail-closed when plans exist.

## Rules for new code

Signed + authorized downloads only (private disk); server-observed IP
(`$request->ip()`, never client-claimed `meta.ip`); throttle all public +
sensitive writers; uniform auth error messages; `SESSION_ENCRYPT=true` guidance;
never commit `.env*`/credentials/`storage/`; never `--force`.
