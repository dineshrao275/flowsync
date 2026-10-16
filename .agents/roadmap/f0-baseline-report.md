# F0 baseline report (running log)

**Branch:** `feat/f0-baseline` · **Started:** 2026-10-16

## Test-run reality on this host
- Suite size: **2046 tests** (`php artisan test --parallel`).
- A 24-test focused run took ~12 min and a 5-test run ~5 min, almost all of it per-test tenant DB provisioning (`IsolatesDatabase`), not assertions. A full run is therefore several hours even with 8 processes; with Docker and other jobs running it effectively never finished (this is why no full run completed for so long).
- Rules adopted: write tests with each task; run focused tests (`--filter=`) at a phase gate; run the whole suite in chunks at the very end; stop Docker (`./scripts/dev.sh docker down`) and avoid concurrent runs while testing.
- Never run PHP tests from a git worktree that symlinks `vendor/` — Composer's autoloader resolves `App\` to the main checkout, so the worktree's code is not what runs.

## F0 task status
| Task | Status | Evidence |
|---|---|---|
| F0.1 full ParaTest run | **done on a snapshot at `0300dda8`**: 2077 tests / 12030 assertions; 28 failures + 1 error, all triaged below; final re-run still required at the end | `scratchpad/full-run2.txt` |
| F0.2 verify §0.1 | done | `missing-functionality.md` §0.1 updated with findings |
| F0.3 impersonator on audit rows | written, awaiting run | `AuditTrace`, migrations `…_000052`, `…_000062`, `AuditTraceTest` |
| F0.4 request id on activities + jobs | written, awaiting run | same files + `SwitchesTenantConnectionForQueuedJobs` |
| F0.5 CSP report collector | written, awaiting run | `CspReportController`, `SecurityHeadersTest` |
| F0.6 audit:verify scheduled + alert | committed `7d8198ac` | `AuditChainTest` (24-test run: pass) |
| F0.7 hrms:leave-consistency | committed `146d4697` | `HrmsLeaveConsistencyTest` 5/5 pass; also fixed `--tenant` in `RunsPerTenant` |
| F0.8 doc conflicts | in progress | — |

## Defects found on the way
- `RunsPerTenant::tenantsOrFail()` turned `--tenant=ID` into an array of attribute values (`collect($model)`), so every fleet command run with `--tenant` failed. Fixed (F0.7 commit).
- `Command::alert()` name clash when adding an alert helper to a console command (renamed `raiseAlert`).

## F0.1 result (snapshot of commit 0300dda8, 2077 tests, 12030 assertions)
- **24 of the 29 red tests were an artefact of the copy** (the snapshot excluded `public/build`, so every page that renders the Vite manifest — public site, 404 pages, SPA shell — returned 500). The same 119 tests (CmsTest, BlogTest, PublicSiteLocaleTest, CollaborationTest, ExampleTest, HrmsEmployeeApiTest, InvoiceTest, SiteSettingsTest, TenantExportTest, BrandSweepTest, HrmsDocumentServiceTest) all pass in the real checkout.
- **5 real failures, all fixed:** `RouteAuthorizationAuditTest` (two public routes, `api/csp-report` and `api/locales`, needed explicit entries), `ScopePermissionTest` (permission count 105 → 106 for `custom_fields.manage`), `BroadcastingChannelAuthTest::test_workspace_channel_allows_workspace_member` (the test still asserted the pre-fix behaviour that a tenant admin is refused a workspace channel; admins are allowed since `9958af8c`).
- Lesson: to snapshot for a background full run, copy `public/build` too (`rsync` without `--exclude public/build`).
