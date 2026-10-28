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

## Progress (2026-10-23)

| Phase | Commit scope | State |
|---|---|---|
| 0 | standards doc, CLAUDE.md, pint.json, larastan baseline, route snapshot, ESLint warn rules, PR template | done (Playwright, Prettier, lazy-loading detector not added) |
| 1 | SQL identifier hardening, logged silent catches | done |
| 2 | AuditFeed extraction, enum strict_types, ClassSizeCeilingTest | subset |
| 3 | all routes lazy, axios to dependencies; main chunk 3,076 -> 866 kB | subset |
| 4 | ArchitectureRatchetTest (DB::, inline validate, abort in services) | subset |
| 5 | AGENTS.md 1,508 -> 968 lines (HRMS history moved verbatim to docs/architecture/hrms-history.md) | subset |

**Full ParaTest suite not run since the baseline above (deferred by the owner to the end).** Test counts in AGENTS.md are therefore unverified; the new unit tests add 5 (2 size ceiling + 3 architecture ratchets).

### Second pass (focused tests only)
Done: membership row scope single-sourced in `TaskScope`; 59 first-statement inline validations moved to FormRequests (inline count 204 -> 145, 482 focused tests green); route files `hrms_p5`/`security` moved under `routes/web/` (snapshot identical); 14 unused lint directives removed + exhaustive-deps fixed, `npm run lint` now fails above 123 warnings; AdminActivity and OrgChart on `useResource` with specs (Vitest 197); user lookups batched in the digest/survey loops; `PostgresDumper` extracted from `BackupTenants`; strict_types on all new classes; larastan baseline refreshed (still 1,735).

### Third pass and full-suite verification (2026-10-23)
**Full ParaTest run on HEAD: 2345 tests / 15011 assertions, 1 failure** (`HrmsShellTest::test_every_commit_is_pushed`, passes once the branch is pushed). Vitest 213 tests / 48 files, larastan clean (baseline 1,734), route snapshot unchanged (811), build green. Wall clock for the full suite: ~62 min on 8 processes.

Done in this pass: `declare(strict_types=1)` in every `app/` file, enforced by Pint (its first full run found and fixed real bugs: query-string ids passed to `?int` parameters in three HR controllers, a float return in `StatutoryResolver`); all 5,012 methods have return types (the "400 missing" estimate was wrong, 10 were); three failures that predated the work fixed (test isolation, a stale title, live Stripe keys leaking into tests via `.env`); extractions `StripeWebhookParser`, `ProviderTrials`, `FieldValueNormalizer`, `PostgresDumper`, `AuditFeed`; React splits of CmsPages (BlockEditor, PageFormModal), Tenants, Analytics, Org, Dashboard; four more pages on `useResource` with specs (AdminActivity, OrgChart, AuditTimeline, FeatureManagement). Tools: `scripts/route-snapshot.sh`, `scripts/refresh-size-ratchet.py`, `scripts/extract-components.py`, `rector.php` (strict return-type rules only; nothing left to add).

### Fourth pass (no test suite run, by instruction; static gate only)
Static gate at the end of this pass: Pint, `php -l` on app/ and routes/, larastan (baseline 1,734), route snapshot (811), ESLint (0 errors / 123 warnings) and the production build all pass. **The full ParaTest suite and Vitest have not been run since the verified 2345 / 15011 run, so run both before pushing.** Where to look first if something fails: the new collaborators (`InboxSources`, `ExpenseItemRules`, `SurveyResults`, `SessionPayload`, `OrgDefaults`/`ReferenceDefaults`/`PayrollDefaults`) and the four FormRequests (`FeatureFlagStoreRequest`, `SystemSupportUpdateRequest`, `TenantSubscriptionSplitRequest`, `UserAccessShowRequest`).

Done: AGENTS.md 968 -> 510 lines (five section groups moved verbatim into `docs/architecture/`); four more first-statement validations converted (inline count 141); splits of HrmsDefaultsProvisioner (407 -> 98), InboxService (397 -> 143), ExpenseService (439 -> 318), EngagementService (442 -> 358) and AuthController (379 -> 281). Tools added: `scripts/inline-validation-to-formrequest.py`, `scripts/extract-php-methods.py`.

### Fifth pass (focused tests and static verification)
Static gate at the end of this pass: Pint, route snapshot (`scripts/route-snapshot.sh --check`), `ClassSizeCeilingTest`, `ArchitectureRatchetTest`, ESLint (0 errors / 123 warnings), Vitest (217 tests / 48 files), and the production build all pass.

Done:
- **`PlatformBadgeCountsController` direct `DB::` call fixed**: extracted `App\Services\Platform\PlatformBadgeCounts` service, satisfying `ArchitectureRatchetTest`.
- **`TenantController` brought under the 300-line ceiling (410 -> 285 lines)**: extracted `App\Services\Tenancy\TenantUserDirectory` (user directory listing and default owner account lookup) and `App\Services\Tenancy\TenantStats` (live domain counts); converted inline validations to FormRequests `TenantUpdateRequest` and `TenantSelfProfileRequest`. `TenantController.php` successfully removed from `ClassSizeCeilingTest::FROZEN`, and its inline validation ratchet lowered from 3 to 1 in `ArchitectureRatchetTest`.
- **AGENTS.md further slimmed (511 -> 407 lines)**: moved 4 architecture sections verbatim into `docs/architecture/` (`api-tokens-and-security.md`, `realtime.md`, `rbac-and-permissions.md`, `impersonation-and-users.md`), replaced with concise reference links.
- **`WorkspaceDetail.jsx` component split (669 -> 425 lines)**: extracted tab bodies into `components/workspace/` (`WorkspaceProjectsTab`, `WorkspaceMembersTab`, `WorkspaceLabelsTab`, `WorkspaceSettingsTab`); added to `extractedModules.test.jsx` (Vitest 217 tests / 48 files green).

### Sixth pass (focused tests and static verification)
Static gate at the end of this pass: Pint, route snapshot (`scripts/route-snapshot.sh --check`), `ClassSizeCeilingTest`, ESLint (0 errors / 123 warnings), Vitest (221 tests / 48 files), and the production build all pass.

Done:
- **`EmployeeController` brought under ceiling (303 -> 294 lines)**: removed duplicate stacked docblock on `photo()`; removed from `ClassSizeCeilingTest::FROZEN`.
- **`LeaveRequestDecisions` split (303 -> 227 lines)**: extracted `App\Services\Hrms\Leave\LeaveDecisionLedger` (92 lines) for adjustments and date rebuilds; removed from `ClassSizeCeilingTest::FROZEN`. Guarded by `HrmsLeaveRequestTest` (16 tests) and `HrmsLeaveApprovalRoutingTest` (7 tests).
- **`OnboardingCases` split (305 -> 272 lines)**: delegated participant and open state verification to `App\Services\Hrms\Lifecycle\CaseProgress`; removed from `ClassSizeCeilingTest::FROZEN`. Guarded by `HrmsLifecycleServiceTest` (19 tests) and `HrmsLifecycleApiTest` (10 tests).
- **`ExportService` split (315 -> 228 lines)**: extracted `App\Services\Export\ExportCsvWriter` (47 lines) and `App\Services\Export\HrmsExportCategories` (91 lines); removed from `ClassSizeCeilingTest::FROZEN`. Guarded by `TenantExportTest` (8 tests).
- **`Leave.jsx` component split (698 -> 538 lines)**: extracted tab bodies into `components/hrms/leave/` (`LeaveRequestsTab`, `LeaveTypesTab`, `LeavePoliciesTab`, `LeaveExemptionsTab`); added to `extractedModules.test.jsx` (Vitest 221 tests / 48 files green).

