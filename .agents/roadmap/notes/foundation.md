# Track notes: foundation (branch `track/foundation`)

Tasks: P1.6, P1.7, P1.8, P1.13, P1.15 (this file), P2.2, P2.3. Nothing here was run through
PHPUnit (user policy: tests deferred to the end); verified with `php -l`, `pint --test`, container
boot of the changed classes and an identical `route:list` (524 routes, same order/URI/name/action/
middleware; only the closure `path` line numbers differ). New tests are written but unexecuted.

## What shipped

### P1.6 NotificationService split (no behaviour change)
- `App\Services\NotificationService` is now a ~200-line facade with the **same public methods**; every
  caller is untouched.
- `App\Contracts\Notifications\NotificationSender` (`notify()`; container-bound to `NotificationDispatcher`
  in `AppServiceProvider`) and `NotificationFamily` (`family(): string`).
- `app/Services/Notifications/`: `NotificationDispatcher` (persist + broadcast + queued mail, `forUser`,
  `unreadCount`, `markAllRead`), `RecipientResolver` (`usersById/usersWith/usersWithRole/mentionUsers`),
  `BaseNotificationFamily`, and `Families/{Task,TimeOff,Lifecycle,Expense,Performance,Asset}Notifications`
  (method bodies moved verbatim).
- New: `NotificationService::mentionsTruncated($text)` (used by P2.3).
- Test: `tests/Unit/NotificationFamiliesTest.php`.

### P1.7 TaskService + routes split
- `app/Services/Tasks/`: `TaskInputResolver` (status/priority/assignee/parent/labels/components/version/
  issue-type resolvers), `TaskPresenter`, `TaskReader` (board/list/show/filteredQuery), `TaskWatchers`,
  `TaskColumnOrder` (reorder + position write). `TaskService` 698 -> ~280 lines, public API unchanged
  (delegates). There is no bulk-operation code yet (P5.5), so none was moved.
- `routes/web.php` 1145 -> ~320 lines. New files `routes/web/{platform,tms,hrms_people,hrms_time,
  hrms_payroll,hrms_talent}.php` are `require`d **inside the same middleware group at the same
  position** (route order is behaviour: `reorder`/`expiring` before `{id}`). Signed download routes,
  public site and broadcasting auth stay in `web.php`.
- Tests: `tests/Unit/TaskServiceSplitTest.php`.

### P1.8 Shared helpers
- `Http/Controllers/Concerns/`: `NormalizesFilters` (`clean`, `cleanBlank`; 10 HRMS controllers),
  `ChecksPerformanceScope` (4), `ResolvesDateRange` (Reports, Analytics), `ResolvesCurrentTenant`
  (Onboarding, MySubscription, Billing).
- `App\Support\UniqueSlug::make()` (RegisterController, RegisterCardController, ProjectRoleController,
  WorkspaceService); `App\Support\Hrms\Auditable::snapshot()` (Asset, LeaveRequest, CompOff, Expense,
  LeaveBalance, Regularization services).
- Not converted (model-specific, would need per-service review): the other ~13 `snapshot()` copies,
  `HolidayCalendarService::uniqueSlug`, `OrgNaming::uniqueSlug`, the `per_page` rule x10 and the
  escape-aware `LIKE` x7 (the latter already uses `App\Support\Like`).
- Tests: `tests/Unit/SharedHelpersTest.php`.

### P1.13 CI
- `.github/workflows/ci.yml` (Pint, JS lint + Vitest + build, sqlite PHPUnit) and
  `.github/workflows/postgres-migrations.yml` (postgres:16 service; system migrations, seed which
  provisions the demo tenants on PG, `tenants:provision` idempotency, table presence check; runs on
  database/tenancy changes and nightly). Workflows have not been executed on GitHub.

### P2.2 Audit contract
- `App\Contracts\AuditWriter::recordChange()` implemented by `PlatformAudit` and `HrmsAuditLogger`.
- `App\Support\RequestId` (`current()`, `columnFor($model)`): the `AssignRequestId` id is stamped as
  `request_id` on `audit_logs`, `hrms_audit_logs`, `hrms_data_access_logs`; written only when the
  column exists so a half-migrated fleet keeps auditing.
- Migrations: `database/migrations/system/2026_10_29_000031_add_request_id_to_audit_logs.php`,
  `database/migrations/tenant_hrms/2026_10_29_000042_add_request_id_to_hrms_audit_tables.php`.
  **Run** `php artisan migrate --database=system --path=database/migrations/system` and
  `php artisan tenants:provision` on deploy.
- `GET system/audit-logs` rows now carry `request_id` (null for impersonation rows).
- Test: `tests/Feature/AuditContractTest.php`.
- Not done: `request_id` on `impersonation_logs`, `activities`, queued-job payloads; JSON log channel.

### P2.3 NotificationService callers -> domain-event consumer
- `App\Services\Events\Consumers\NotificationConsumer` registered as `notifications` in
  `config/domain_events.php` for `task.created|updated|moved|commented|work_logged|dependency_deleted`.
- TaskController, TaskMoveController, CommentController, WorkLogController, DependencyController no
  longer inject/call `NotificationService`; they put the needed facts on the activity/event payload
  (`assignee_id` on created; `assignee_changed`, `status_changed`, `from_status`, `to_status` on
  updated; `unblocked` on dependency_deleted). The consumer is opt-in per fact and skips
  `automation_rule_id`-tagged events, so automation-made changes still do not notify.
- Left direct on purpose: `ActionRunner` (`taskAssigned`, `automation.notice`), all HRMS callers
  (leave/expense/payroll/asset/performance/lifecycle), `SupportDesk`, `HrmsSurveysOpenClose`.
- Test: `tests/Feature/NotificationConsumerTest.php`.

## Behaviour notes / risks
- Notifications now run inside `ProcessDomainEvent` (queued; `sync` in tests). With a real queue they
  arrive after the response instead of during it, and the emailed deep link is built from `APP_URL`
  (no request in a worker) rather than the request host.
- Webhook/automation consumers now see a few extra keys on `task.updated`/`task.created`/
  `task.dependency_deleted` event data (`assignee_changed`, `status_changed`, `from_status`,
  `to_status`, `assignee_id`, `unblocked`). Additive.
- A consumer failure is logged and swallowed (existing bus rule) instead of 500-ing the request.
- Run the suite before merge: highest-risk tests are `NotificationTest`, `MentionEmailTest`,
  `WorkLogTest`, `CollaborationTest`, `WebhookTest`, `AutomationTest`, `AuthorizationMatrixTest`
  (route table), `HrmsShellTest`, `HrmsAuditLoggerTest`, `PlatformAuditTest`, `AuditFeedTest`.

## AGENTS.md lines to change (coordinator)
1. "Tasks (Phase 3)" / "Notifications (Phase 5)": replace "notifications are a controller-side effect ...
   `TaskController@store`/`@update` ... `WorkLogController@store`" with "task notifications are produced by
   `NotificationConsumer` from the domain events (P2.3); controllers only log the activity".
2. "Task-management services": `NotificationService.php` is now a facade over
   `app/Services/Notifications/` (dispatcher, resolver, six families, `NotificationSender` contract);
   `TaskService.php` delegates to `app/Services/Tasks/` (resolver, presenter, reader, watchers,
   column order).
3. Every "routes in `routes/web.php`" statement: add "(split into `routes/web/*.php` required inside the
   same groups; platform, tms, hrms_people/time/payroll/talent)". HRMS sections: HRMS routes live in
   `routes/web/hrms_*.php`.
4. "Request correlation (P2.2)": last sentence ("Not yet in audit rows ...") -> audit rows now carry
   `request_id` (`audit_logs`, `hrms_audit_logs`, `hrms_data_access_logs`); `AuditWriter` contract;
   remaining: `impersonation_logs`, `activities`, queued payloads.
5. "Commands": add the CI workflows (`.github/workflows/ci.yml`, `postgres-migrations.yml`); migration
   range: system now reaches `2026_10_29_000031`, tenant_hrms `2026_10_29_000042`.
6. Test count line: +3 unit files (NotificationFamilies, TaskServiceSplit, SharedHelpers) and +2 feature
   files (AuditContract, NotificationConsumer); counts to be re-verified at the full run.
7. Frontend/other claims unaffected.

## master-roadmap.md lines to change (coordinator)
- Progress line (~484): mark **P1.6 done, P1.7 done (no bulk code existed), P1.8 done (partial: see
  not-converted list), P1.13 done (unexecuted on GitHub), P1.15 done**, **P2.2 done (request_id on audit
  ledgers; JSON log channel and impersonation/activities rows open)**, **P2.3 done for TMS callers (HRMS
  callers still direct)**; update "Open:" list accordingly.
- Table rows P1.6/P1.7/P1.8/P1.13/P1.15/P2.2/P2.3: add ✅ and the commit ids from this branch.
- Section 23 Technical Debt: "19 files > 300 lines; routes/web.php 1,053 lines" -> routes/web.php split;
  NotificationService and TaskService split; `clean()`, `range()`, `seesAll()`, `currentTenant()` and
  six of the `snapshot()` copies and four `uniqueSlug` copies removed.
- Section 33 risk "sqlite fast-path hides PG-only failures" -> mitigated by the PG CI lane (P1.13).
