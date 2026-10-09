# Security track notes (branch `track/security`)

Base: `Improve/improvements-new-work` @ 7e94bf9 (the stale `master` the worktree started from was NOT used).
Nothing was run beyond `php -l`, `pint --test`, `npm run build`/`lint` (user test policy). Focused tests were written but never executed.
Note: `vendor` is a symlink into the main checkout, so its composer autoload maps `App\` to the MAIN tree; run the new tests from a checkout that has its own `composer dump-autoload`.

## Shipped (one commit each)

| Task | Commit subject | What |
|---|---|---|
| P8.5 | CSP + security headers middleware | `SecurityHeaders` (global append), `ContentSecurityPolicy` builder, `config/security.php`. CSP defaults to **report-only**; allows self, inline script/style (theme bootstrap), Stripe js/api/frames, Google Fonts, Reverb ws(s) origin from `config('reverb')`, Vite dev origin only while `public/hot` exists. nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy, HSTS on https only. |
| P8.7 | runtime feature flags | `feature_flags` + `flag_overrides` (system), `FeatureFlags` service (tenant override -> global switch -> stable crc32 rollout bucket, unknown = off, bound `scoped`), `ensure_flag:<key>` middleware, SA console + `FeatureFlags.jsx`, `user.flags` in `/auth/me`. |
| P8.1 | TOTP 2FA | `Totp` (RFC 6238, no package), `two_factor_credentials` in BOTH the system DB and every tenant DB (encrypted secret, hashed-at-rest recovery codes, replay step), `TwoFactorService/Login/Policy`, challenge flow for tenant users and platform admins, per-role enforcement, SPA login step + `/security` page. |
| P8.3 | platform personas | `config/platform_access.php` (catalog, 4 personas, route->permission map), `PlatformAccess` service, `EnsureSuperAdmin` now persona-aware, `platform:<slug>` middleware, `PlatformRoleController`, persona UI on Platform Users, sidebar mirror. |
| P8.4 | consent-based support access | `support_access_grants` (system), `SupportAccessGrants`, tenant admin grant/list/revoke, impersonation `grant_id` binding (mode + session ceilings), revoke ends running session, platform "consent required" switch. |
| P8.6 | audit hash-chain + retention | `audit_logs.hash/prev_hash`, `audit_log_tombstones`, `AuditChain` (seal on create, verify), `audit:verify`, `audit:prune` (nightly 03:50), export columns + `X-Audit-Chain-Head`, `GET system/audit-logs/verify`, "Verify chain" button. |

Skipped (deferred): **P8.2 SSO/SAML/OIDC**, **P8.8 SCIM** (large; per instructions).

## Migrations
System (`php artisan migrate --database=system --path=database/migrations/system`):
- `2026_10_28_000031_create_feature_flags`
- `2026_10_29_000032_create_two_factor_credentials`
- `2026_10_29_000033_seed_platform_personas` (calls `PlatformAccess::syncCatalog()`, additive/idempotent)
- `2026_10_30_000034_create_support_access_grants` (+ `impersonation_logs.support_access_grant_id`)
- `2026_10_31_000035_add_audit_hash_chain` (`audit_logs.prev_hash/hash`, `audit_log_tombstones`)

Tenant (`tenants:provision` runs them on existing tenants): `2026_10_29_000048_create_two_factor_credentials`.
A tenant DB that has not run it simply reports nobody enrolled (`TwoFactorService::isEnabled` swallows `QueryException`).

## Routes (all in the new `routes/security.php`, required by ONE line appended to `routes/web.php`)
Public: `POST api/auth/2fa/challenge` (throttle 10/min).
Any signed-in account: `GET auth/2fa`, `POST auth/2fa/setup|confirm|disable|recovery-codes`.
Tenant `tenant_context` + `permission:roles.manage`: `GET|PUT security/two-factor-policy`.
Tenant `tenant_context` + `permission:support.manage`: `GET|POST support-access`, `DELETE support-access/{grant}`.
Platform (`super_admin`): `GET|PUT system/two-factor-policy`, `GET system/support-access`, `PUT system/support-access/policy`, `GET system/platform-roles`, `PUT system/users/{user}/platform-roles`, `GET system/audit-logs/verify`, `GET|POST system/feature-flags`, `PUT|DELETE system/feature-flags/{flag}`, `PUT|DELETE system/feature-flags/{flag}/overrides/{tenant}`.
Modified existing: `POST api/impersonate` takes optional `grant_id`; `GET system/users` rows carry `platform_roles`; `GET system/audit-logs[/export]` rows/CSV carry `hash`, `prev_hash`; `/auth/me` gains `user.flags`, `user.two_factor {enabled, enrollment_required}`, `user.platform {roles, permissions|null}`.

## Middleware / bindings
- aliases: `ensure_flag` (`EnsureFeatureFlag`), `platform` (`EnsurePlatformPermission`); both added to the priority list.
- `EnsureSuperAdmin` (alias `super_admin`) unchanged name, now persona-aware (see below).
- `SecurityHeaders` appended globally; `EnsureTwoFactorEnrolled` appended to the `web` group (session-only check; allows `api/auth/me|logout|2fa*`).
- `AppServiceProvider::register`: `scoped(FeatureFlags)`, `scoped(PlatformAccess)`.
- `ImpersonationGuard`: blocks writes to `api/auth/2fa*`, `api/security/*`, `api/support-access*` in write mode; ends a session whose grant was revoked (`401 support_access_revoked`).
- Seam used for P8.3: the platform routes were NOT edited one by one (another agent is splitting web.php). The `super_admin` groups stay; `config/platform_access.php` `routes` maps URI template -> read/write permission and `EnsureSuperAdmin` enforces it. Restricted (persona) accounts are denied on any unmapped route (fail-closed); `PlatformAccessTest::test_every_super_admin_route_maps_to_a_catalogued_permission` fails the day a new `super_admin` route is not mapped. An account with **no persona = unrestricted break-glass** (all existing super admins unchanged). `AuthorizationMatrixTest` excludes `super_admin` routes and `RouteAuthorizationAuditTest` already counts `super_admin` as guarded, so only the ALLOWED list needed edits (6 self-scoped `TwoFactorController` actions).

## Config / env
- `config/security.php`: `SECURITY_HEADERS_ENABLED`, `SECURITY_HSTS_MAX_AGE`, `SECURITY_FRAME_OPTIONS`, `SECURITY_REFERRER_POLICY`, `SECURITY_PERMISSIONS_POLICY`, `SECURITY_CSP_MODE` (`off|report_only|enforce`, default `report_only`), `SECURITY_CSP_REPORT_URI`, `SECURITY_CSP_{SCRIPT,CONNECT,FRAME,IMG}_SRC` (comma lists).
- `config/platform_access.php` (permissions, personas, route map).
- `config/tenancy.php` `support_access`: `SUPPORT_ACCESS_MAX_HOURS` (72), `SUPPORT_ACCESS_MAX_SESSION_MINUTES` (120).
- `config/subscriptions.php`: new numeric limit `audit_retention_days` (starter 90, pro 365, business 730, enterprise/product plans absent = kept forever). Existing DB plans only change when `SubscriptionPlanSeeder` re-runs, so nothing is pruned until then; a per-tenant `limits_override` works immediately.
- Platform settings keys (system `platform_settings`): `require_2fa_super_admin`, `require_support_consent`. Tenant 2FA roles: central `tenants.settings.security.two_factor_roles`.
- Schedule: `audit:prune` daily 03:50.

## Behavioural notes / risks
- Enforce-per-role is applied at SIGN-IN (flag in session); already-open sessions are unaffected until they sign in again.
- Hash chain is tamper EVIDENCE: seals happen under a cache lock in id order; a writer that cannot get the lock in 5 s seals unlocked (could fork under extreme concurrency; `audit:verify` would say). Rows before the first seal are reported as `legacy`. Sealing never fails an audit write (QueryException is logged).
- `audit:prune` only touches rows tied to a tenant (`data.tenant_id` or tenant subject); platform-only rows are never pruned. Uses the same `->>` JSON expression as the audit feed (PG and SQLite >= 3.38).
- TOTP QR code is not rendered (no QR package in npm); the page shows the setup key and an `otpauth://` link.
- Recovery codes are hashed with a keyed sha256 (50-bit codes); TOTP secrets use the app key via `encrypted` cast, so rotating `APP_KEY` invalidates enrolments.
- `HrmsShellTest::test_every_commit_is_pushed` will fail until the branch is pushed (instruction: do not push).
- No new SPA test files (the repo has Vitest only for `ProtectedRoute`); UI verified by `npm run build` + `npm run lint`.

## Lines for AGENTS.md (do not edit here; the integrator applies them)
Add a new section after "Request correlation (P2.2)":

```
## Security hardening (Phase 8, track/security)
- **Headers/CSP (P8.5):** `SecurityHeaders` (global) + `ContentSecurityPolicy`; `config/security.php`, `SECURITY_CSP_MODE=off|report_only|enforce` (default report-only). Stripe, Reverb ws origin and the Vite dev origin (only while `public/hot`) are allowed.
- **Feature flags (P8.7):** system `feature_flags`/`flag_overrides`; `FeatureFlags::enabled($key, ?$tenantId)` = tenant override -> global switch -> crc32 rollout bucket, unknown = off; route gate `ensure_flag:<key>`; SA console `/admin/feature-flags`; `user.flags` in `/auth/me`.
- **2FA (P8.1):** self-implemented RFC 6238 `App\Support\Totp`; `two_factor_credentials` lives in each tenant DB and the system DB (table follows `users`). Sign-in: password -> `{two_factor_required:true}` + `2fa.pending` session record -> `POST api/auth/2fa/challenge` (5 attempts, replay-guarded steps, one-time recovery codes). Enforce-per-role: `tenants.settings.security.two_factor_roles` (tenant admin, `roles.manage`) and platform setting `require_2fa_super_admin`; a required-but-unenrolled session is limited to enrolment by `EnsureTwoFactorEnrolled`. SPA: Login step + `/security`.
- **Platform personas (P8.3):** `config/platform_access.php`; `platform_roles` seeded with Billing Admin/Support/Auditor/Operator; `EnsureSuperAdmin` enforces the route->permission map; no persona = unrestricted break-glass; `platform:<slug>` middleware for explicit gates; assignment via `PUT system/users/{user}/platform-roles`.
- **Support access (P8.4):** tenant admin (`support.manage`) grants time-boxed read-only/write windows (`support_access_grants`); impersonation accepts `grant_id` (caps mode + session length; revocation ends a running session); platform setting `require_support_consent` makes it mandatory.
- **Audit chain (P8.6):** `audit_logs.hash/prev_hash` sealed by `AuditChain` on create; `php artisan audit:verify`, `GET system/audit-logs/verify`; export carries hashes + `X-Audit-Chain-Head`; `audit:prune` applies plan limit `audit_retention_days` and leaves `audit_log_tombstones`.
- Security routes live in `routes/security.php` (required from `routes/web.php`).
```
Also: in "Commands" add focused test files `SecurityHeadersTest`, `FeatureFlagTest`, `TwoFactorTest` (+ `Unit/TotpTest`), `PlatformAccessTest`, `SupportAccessTest`, `AuditChainTest`; counts are not verified (not run).
Pitfalls: add "`EnsureSuperAdmin` is persona-aware: every new `super_admin` route needs an entry in `config/platform_access.php` `routes` or restricted accounts get 403 (`PlatformAccessTest` fails)."

## Lines for master-roadmap.md
- §8 item 2 / RB-8 and §31 Phase 8 line 598: mark **P8.1, P8.3, P8.4, P8.5, P8.6, P8.7 done (code, untested at gate)**, **P8.2 and P8.8 deferred**.
- §17 table: "Session / token theft" -> `2FA shipped (P8.1)`; "XSS/CSRF/SQLi" -> `CSP shipped report-only (P8.5)`; "Impersonation abuse" -> `consent-based support sessions shipped (P8.4)`; "Audit tampering" -> `hash chain + tombstoned retention shipped (P8.6)`.
- Gap register: G-66 (no runtime flags) and G-67 (no consent-based support access) -> closed by P8.7 / P8.4.
