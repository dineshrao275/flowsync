# FlowSync — Multi-Tenant Admin Panel

Laravel 12 + React 19 SPA. Session-based auth **without** Breeze/Fortify/Sanctum. **Phase 13: one database per tenant** (PostgreSQL in prod; sqlite files for local/dev/tests) + a central `system` DB. **Phase 14 (subscriptions) shipped code:** plans/subscriptions/events (central), onboarding trials, and plan-limit enforcement — gates pending a PHP-capable env. Vite 7 + Tailwind v4 + axios. See `docs/multi-tenancy-architecture.md`.

**Keep this file current** — update the relevant section whenever changes touch architecture, migrations, middleware, routes, key components, npm/Composer deps, or test counts.

## Commands
- Docker (full stack: postgres + app :8000 + reverb :8080 + queue): `docker-compose build && docker-compose up -d`
  — first boot auto-runs `migrate --database=system --path=database/migrations/system` +
  `tenants:provision` + seeds demo data (superadmin + acme + globex). Reset from scratch:
  `docker-compose down -v` then `up -d` (app entrypoint re-initializes; `RUN_INIT=true` only for `app`.
  No PHP/composer needed on the host — the image is `flowsync:latest`, envs in `.env.docker`).
- `php artisan test` — run test suite (Phase 13: **isolated, per-tenant file DBs** via `Tests\IsolatesDatabase`; current gate: **297 tests / 2138 assertions passing**)
- `npm run build` / `npm run dev` — frontend build / Vite dev server
- `./vendor/bin/pint` — PHP code style (run over whole repo; `--dirty` only works in git)
- `php artisan migrate:fresh --seed` — reset the **system** DB (migrations now live under `database/migrations/system`; run it as `migrate:fresh --database=system --path=database/migrations/system --seed` — plain `migrate` runs nothing, see Pitfalls) + seed via `Database\Seeders\TenantSeeder` (provisions acme + globex tenant DBs)
- `php artisan tenants:provision` — idempotently provision/repair tenant DBs (`provisionIsolated` pipeline) + backfill permissions/roles/priorities/project-roles (`--tenant=ID` for one)
- `php artisan tenants:seed-scale` — large realistic scale seed (defaults 100 tenants / 10 users each / 5 workspaces / 5 projects / 100 tasks per project; `--tenants --users --workspaces --projects --tasks --no-related`); provisions real tenants through the onboarding pipeline (see P10)
- `composer run dev` — concurrently runs serve + queue + pail(logs) + Vite **+ Reverb websockets**
- Entry: `resources/js/main.jsx` (imports `./bootstrap`, React StrictMode). `resources/js/app.js` is unused stock; ignore it.

## Demo logins (password `password`)
- `superadmin@flowsync.test` — Super Admin (no tenant)
- `admin@flowsync.test` (admin), `editor@flowsync.test` (editor), `viewer@flowsync.test` (viewer) — Acme tenant
- `owner@globex.test` (admin) — Globex tenant

## Tenant isolation (core design)
**One database per tenant** (Phase 13 shipped): tenant DBs hold the full domain schema **without `tenant_id`
columns** (uniqueness is global-within-DB), and the central **system** DB holds platform data (`tenants`,
`tenant_users`, `provisioning_runs`, `impersonation_logs`, infra sessions/jobs/cache, platform RBAC,
`audit_logs`) + the super admin (`users` table with `is_super_admin`; model `SystemUser`).
- `app/Support/TenantDatabaseManager.php` — singleton managing the two connection names: `system`
  (central, alias `iso_system` in tests) and `tenant` (the current tenant's DB). `connect()/connectSystem()`
  switch the default in-app connection; `using($tenant, fn)` scopes a closure to a tenant DB; central models
  use the `CentralConnection` trait (`getConnectionName()` → `centralConnectionName()`).
- `app/Support/TenantContext.php` — guard-intent singleton (`tenantId`, `impersonating`) set by
  `SetTenantContext` from session keys; no row-scoping branch anymore.
- Middleware order on routes: `switch_tenant` → `auth` → `tenant` → `permission`/`super_admin`; domain routes
  (P1+) use `switch_tenant` → `auth` → `tenant` → `tenant_context` → `permission`.
  - `SwitchTenant` resolves the per-tenant connection from session `login.tenant_id` / `impersonate.tenant_id`
    (**central** ids) and calls `TenantDatabaseManager::using()`.
  - `SetTenantContext` (`tenant`) sets the singleton from session keys (isolated branch only).
  - `EnsureSuperAdmin` (`super_admin`) requires `is_super_admin`.
  - `EnsureTenantContext` (`tenant_context`) aborts 403 unless a tenant context exists — blocks non-impersonating super admins from domain routes.
  - `EnsurePermission` (`permission:slug`) + `Gate::define('permission')` bypass for super admin — **unless impersonating** (then scoped to target tenant).
- Login routes to a tenant via central `tenant_users` routing (`AuthController::loginIsolated`, optional
  `tenant` slug disambiguator); super admins fall back to the system DB. Impersonation resolves the target
  user's tenant-LOCAL id through routing; `stop` returns to the system connection.

## Task-management domain (Phase 0 foundation + P1+)
Hierarchy: **Tenant → Workspace → Project → Task** (subtask `tasks.parent_id` self-FK). Tenant DBs hold the full domain schema **without `tenant_id`** — tenant isolation is the physical DB (no `TenantScoped`).
- New tables (migrations `2026_09_23_000005` → `000010`): `workspaces`, `workspace_members` (role owner/admin/member), `project_roles` (tenant catalog, `permissions` JSON), `priorities` (tenant catalog), `projects` (+ `last_task_sequence`, unique `key`), `project_members` (+ `project_role_id`), `task_statuses` (per-project, `category` enum drives board columns, `is_done`), `tasks` (SoftDeletes, `position`, `status_id`, `priority_id`, unique `(project_id, key|sequence)`), `labels` + `task_label`, `comments` (SoftDeletes), `attachments`, `work_logs`, `task_dependencies` (blocks/related_to), `activities` (polymorphic audit), `notifications` (custom; NOT Laravel's `Notifiable` method table).
- Index optimization (migration `2026_09_23_000011_add_task_search_indexes`): B-tree indexes on `tasks.workspace_id`, `tasks.status_id`, `tasks.assignee_id`, `tasks.due_date`, and `(tasks.project_id, tasks.updated_at)` — until Phase 7 the `tasks` table only carried its two unique composites and no plain FK indexes.
- FK-composite hardening (migration `2026_09_23_000012_add_hardening_indexes`): indexes on `projects.workspace_id`, `tasks.parent_id`, `task_label.label_id`, `comments(task_id,parent_id)`+`user_id`, `attachments.task_id|user_id`, `work_logs(task_id,started_at)`+`user_id`, `task_dependencies.depends_on_task_id`, `activities(subject_type,subject_id)`, `role_user.user_id`, `permission_role.role_id`.
- N+1 guard: `Workspace::memberRole()` / `Project::memberRole()` short-circuit to the **loaded** `members` relation (no per-row pivot query); the list services (`WorkspaceService::listFor`, `ProjectService::listFor|listAll`) eager-load `members` constrained to the current user so role resolution never re-queries. New global/read queries should follow the same pattern (see `ScopesVisibleTasks`).
- Soft deletes: `tasks` + `comments` use `SoftDeletes`; trashed rows 404 via implicit route binding and are excluded from board/list/search/dashboard/reports/subtasks by the global scope (no `withTrashed`-based restore surfaces; deletions broadcast `TaskSynced`) — `tests/Feature/HardeningTest.php` locks all of this in.
- Enums in `app/Enums`: `WorkspaceMemberRole`, `TaskStatusCategory`, `TaskDependencyType`.
- Default catalogs: `config/permissions.php` (tenant perms incl. `workspaces.view|create|manage`), `config/project_roles.php` (project-role perms, seeded lead=`*`/developer/viewer), `config/priorities.php` (highest→lowest, default medium), `config/task_statuses.php` (per-project default statuses, seeded on project create).
- `TenantProvisioner::provisionIsolated()` provisions/clones perms+roles+priorities+project_roles **idempotently** (pins `TenantContext` to null internally — safe to call anytime). `php artisan tenants:provision` provisions/repairs existing tenants.
- PostgreSQL provisioning (exercised by the docker compose stack): `createPostgresDatabase()` creates the
  tenant role FIRST then `CREATE DATABASE … OWNER role` (or `ALTER DATABASE … OWNER` when repairing an
  existing DB) — PG15+ revokes CREATE on the `public` schema for non-owners, so without ownership the
  tenant role gets 42501 on tenant migrations; `Tenant::$hidden` includes `db_password` (never expose the
  per-tenant DB credential in API JSON), and `provisionIsolated` only lifecycle-transitions to
  `provisioning` when `TenantLifecycle::canTransition()` permits (a serviceable-but-half-provisioned
  tenant repairs in place instead of throwing active→provisioning).
- Task keys generated by `app/Services/KeyGenerator::nextTaskKey()` — returns `[key, sequence]` tuple (atomic `last_task_sequence` bump in a transaction → `KEY-N`).
- Policies in `app/Policies/` gate membership + project-role (P1+ fills these in; policy scaffolding per model).

## Workspaces (Phase 1)
- Access: routes in `routes/web.php` sit in the domain group `auth → tenant → tenant_context → permission:workspaces.view`; only `POST /workspaces` also needs `permission:workspaces.create`. Per-object auth is delegated to **policies**, not route-level permission — so an editor can manage a workspace they own/admin even without tenant-level `workspaces.manage`.
- `app/Support`-adjacent `app/Services/WorkspaceService.php`: `listFor()` (tenant-admin `workspaces.manage` → all tenant workspaces; otherwise only where a member), `create` (creator auto-owner; slug auto-suffixed on collision), `update`, `archive`/`restore`, `delete` (**blocked 422 if projects exist** — suggest archive first), `addMember`/`changeMemberRole`/`removeMember`.
- Membership invariants (enforced in the service): members must be **same tenant** (422 `user_id`); no duplicate members; **cannot demote or remove the last owner** (422 `form`).
- Policies: `WorkspacePolicy` — `view` (tenant admin OR member), `update`/`manageMembers` (tenant admin OR owner/admin), `archive`/`restore`/`delete` (tenant admin OR owner). `LabelPolicy` — `view` (tenant admin OR member), `create`/`update`/`delete` (tenant admin OR owner/admin).
- Labels: `labels` scoped to workspace; `name` unique per workspace (validated + DB `unique(workspace_id, name)`); `LabelController` `update`/`delete` at `/api/labels/{label}` (route-model bound, tenant-scoped).
- Response shapes: lists return `{workspaces}`, `{members}`, `{labels}`; workspace objects include `my_role` (owner/admin/member) and `*_count`; member write endpoints return only `{message}` (client refetches).
- Frontend: `pages/Workspaces.jsx` (list + create card), `pages/WorkspaceDetail.jsx` (tabs: projects/members/labels/settings), Sidebar nav item gated `workspaces.view`, routes `/workspaces` + `/workspaces/:workspaceId`. UI decides manageability via `can('workspaces.manage') || my_role ∈ {owner,admin}`.

## Projects (Phase 2)
- Access: routes in `routes/web.php` sit in the domain group `auth → tenant → tenant_context → permission:workspaces.view`. Project roles (`GET /api/project-roles`) ride the same group; role **mutations** (`POST/PUT/DELETE /api/project-roles`) also require `permission:roles.manage` at route level. Per-project auth (view/edit/delete/members/workflow) is delegated to **ProjectPolicy** using project-role permissions. Global project list at `GET /api/projects` (tenant admin → all tenant projects; otherwise only where a project member).
- `app/Services/ProjectService.php`: `listFor()` (tenant-admin `workspaces.manage` → all workspace projects; otherwise only where a project member), `listAll()` (global, same rule across all workspaces), `create` (key auto-derived from initials — "Website Redesign" → `WR` — unique per tenant; seeds 5 default statuses from `config/task_statuses.php`; creator auto-attached as **lead** member + `lead_user_id`), `update`, `archive`/`restore`, `delete` (**blocked 422 `form` if tasks exist**), `addMember`/`changeMemberRole`/`removeMember`, `addStatus`/`updateStatus`/`deleteStatus` (+ `reposition`/`normalizePositions`).
- Project membership invariants (enforced in the service): members must be **same tenant** (422 `user_id`) and already **workspace members** (422 `user_id`); no duplicate members; removal keeps ≥1 member.
- Status invariants: can't delete a status **in use by tasks** or the **last remaining** status (422 `form`); positions are 1-based and re-normalized after add/delete/reorder; `category` (todo/in_progress/done) drives `is_done`.
- Policies: `ProjectPolicy` — `view` (tenant admin OR member), `edit`/`settings`/`delete`/`manageMembers`/`manageWorkflow` via project-role (`projects.edit|settings|delete`, `members.manage`, `projects.settings`), `archive`/`restore` = `edit`. `WorkspacePolicy::createProject` (tenant admin OR workspace owner/admin).
- Project roles: `config/project_roles.php` catalogs permissions; system roles lead=`*`/developer/viewer **cannot be modified or deleted** (422 `form`); custom roles can be deleted only while unused (422 `form` if assigned); slug auto-suffixed on collision.
- Response shapes: project objects include `my_role` (slug), `*_count`; members return `{role: {id, slug, name}}`; member/status write endpoints return `{message}` only (client refetches).
- Frontend: `pages/ProjectDetail.jsx` hub at `/projects/:projectId` (tabs: overview/tasks/members/workflow/settings — settings only for managers); WorkspaceDetail's Projects tab lists + creates projects. UI decides manageability via `can('workspaces.manage') || my_role === 'lead'`; workflow/member controls are hidden for non-managers (backend `403`s regardless).

## Tasks (Phase 3)
- Access: routes in `routes/web.php` sit in the domain group `auth → tenant → tenant_context → permission:workspaces.view`. Endpoints (all project-scoped): `GET|POST projects/{project}/tasks` (board/list index), `GET|PUT|DELETE projects/{project}/tasks/{task}`, `POST projects/{project}/tasks/{task}/move`. **Create** gated by `ProjectPolicy::createTask` (`tasks.create`); **view/edit/delete/assign/move** gated by `TaskPolicy` (project-role `tasks.*` perms, tenant admin via `workspaces.manage` bypass). `update` requires `assign` too when `assignee_id` is present.
- `app/Services/TaskService.php`: `create` (atomic key via `KeyGenerator::nextTaskKey()` tuple → `key`/`sequence`; default status/priority; position at end of column; `completed_at` when status `is_done`; labels attached), `update` (labels `sync()` when `labels` present), `delete` (soft), `move` (hard-blocks `is_done` when `hasOpenBlockers()` → 422 `form`; renumbers **both** destination and source columns 1..N), `board` (top-level tasks `whereNull(parent_id)` grouped by status; `totals.open/done` respect active filters), `list` (paginated + `sort_by`/`sort_dir`/`per_page`), `show` (loads subtasks/labels/counts), `filteredQuery` (status_id/priority_id/assignee_id/label_id/q/due_from/due_to).
- Resolvers throw 422: `status_id` must belong to the project; `priority_id` must belong to the tenant; `assignee_id` must be a same-tenant **project member**; `parent_id` must belong to the project; `labels` must belong to the workspace.
- Response shapes: board → `{board: {statuses: [...presentStatus + tasks_count/tasks], totals}, filters: {statuses, priorities, assignees, labels}, my_role}`; list → `{tasks, filters, my_role, pagination}`; write → `{message, task}`.
- Realtime: `app/Events/TaskSynced` (action `created|updated|deleted|moved`) broadcasts on `PrivateChannel('project.'.$project_id)` with `broadcastAs('task.synced')` — fires on store/update/destroy/move.
- Frontend: Tasks tab in `pages/ProjectDetail.jsx` (board/list toggle, filter bar, new-task modal, task drawer) built from `resources/js/components/tasks/`: `KanbanBoard` (dnd-kit `DndContext` + per-column `SortableContext`, columns are droppable `col-{id}`), `TaskCard` (sortable), `TaskTable` (list), `FiltersBar`, `CreateTaskModal`, `TaskDetail` (slide-over edit drawer). UI permission mirror: `can('workspaces.manage') || my_role ∈ {lead,developer}` for create/edit/assign/move; delete only for tenant admin or lead. Subscribes `window.Echo.private('project.{id}')` `.task.synced` → refetch board/list.

## Collaboration (Phase 4)
- Access: all endpoints in `routes/web.php` sit in the domain group `auth → tenant → tenant_context → permission:workspaces.view`. Controllers MUST declare `Project $project` alongside `Task $task` in every method signature — Laravel binds `{project}`/`{task}` from the **controller signature** (ImplicitRouteBinding); omitting `Project` leaves it a raw string spliced positionally → TypeError.
- Comments: `GET|POST projects/{project}/tasks/{task}/comments`, `PUT|DELETE …/comments/{comment}`. `index` returns a nested tree `{comments:[{…, replies:[…]}]}` (top-level `parent_id IS NULL`, one level deep). `store` validates `parent_id` is a same-task **top-level** comment (else 422 `parent_id`). Mutations gated by `CommentPolicy` (auto-discovered): create via `authorize('create', [Comment::class, $task])` (note the array form so the policy resolves to CommentPolicy); update/delete by owner OR project-role `comments.edit|delete` OR tenant admin; route-model-bound `{comment}` verified `task_id === $task->id` else 404; soft-deleted/trashed comments 404. `present()` emits `id/comment/edited_at/deleted_at/created_at/user{id,name,email}` (no `parent_id` — tree nesting carries it). Broadcasts `App\Events\CommentSynced` on `private('project.'.$comment->task->project_id)` with `broadcastAs('comment.synced')` (action created/updated/deleted, queued).
- Dependencies: `GET|POST projects/{project}/tasks/{task}/dependencies`, `DELETE …/dependencies/{dependency}`. `index` returns `{blocked_by:[…], blocks:[…]}` — **blocked_by** = tasks this task depends on (`task_id === $task->id`, exposing `dependsOn`), **blocks** = tasks that depend on it (`depends_on_task_id === $task->id`, exposing `task`); entries carry `{id, type, task_id, depends_on_task_id, task:{id,key,title,status_id,completed_at}}`. `store` (gated `authorize('edit', $task)` = `tasks.edit`) rejects self-dep (422 `depends_on_task_id`), cross-project blocker (422 `depends_on_task_id` — `$task->project->tasks()->find()`), duplicates (422 `form`), and BFS-detected cycles (422 `form`). Hard-block integration: `open_blockers_count` (`withCount('openBlockers')`) on task show payload; `TaskMoveController::move` rejects Done when `hasOpenBlockers()`. `TaskDependency` has **no tenant_id** (only `task_id`/`depends_on_task_id`/`type` enum).
- Attachments: `GET|POST projects/{project}/tasks/{task}/attachments`, `DELETE …/attachments/{attachment}`, plus **signed download** `GET api/tasks/{task}/attachments/{attachment}/download` → `attachments.download`, middleware `signed` (alias registered in `bootstrap/app.php`), intentionally **outside** the auth/tenant groups so a fresh-browser-tab GET works — the signature is the bearer token. Validation: `File::types([jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,md,csv,zip,json])->max(10 * 1024)`; stored on `local` disk (root `storage/app/private`) at `tasks/{project_id}/{task_id}/{uuid}.{ext}`. `AttachmentPolicy`: view=`tasks.view`, create (`[Attachment::class, $task]`) = `attachments.create`, delete = owner OR `attachments.delete` OR tenant admin. `present()` includes `download_url` = `url()->temporarySignedRoute('attachments.download', now()->addHours(1), ['task' => $attachment->task_id, 'attachment' => $attachment->id])`. Delete also removes the file via `Storage::disk($attachment->disk)->delete($path)`. Model `url()` uses `Storage::temporaryUrl` (local-disk has no temp URLs — prefer the signed route).
- Activity timeline: `GET projects/{project}/tasks/{task}/activities` (Task subject) and `GET projects/{project}/activities` (Project subject + its task ids). Both `orderByDesc('id')` (second-precision `created_at` ties are non-deterministic — never order by `created_at` alone), limit 100/200, `with('actor')`; `present()` = `{id, action, data, created_at, actor:{id,name}, subject_type}`. `ActivityLogger::log(subjectType, subjectId, action, data?, actor?, ipAddress?)` writes a polymorphic `activities` row (tenant from `TenantContext`). Wired on: task create/update/delete (`task.created|updated|deleted`, data `{key,title}`/`{key,fields}`), move (`task.moved`, data `{from_status:{id,name}, to_status:{id,name}}`), comment (`task.commented`, `{comment_id, snippet}`), dependency create/delete (`task.dependency_created|deleted`, `{depends_on_task_id,type}`), attachment create/delete (`task.attachment_created|deleted`, `{attachment_id,name,size}`).
- Frontend: sub-tabs inside the `TaskDetail` drawer (`details` = edit form kept as-is; plus `comments`/`attachments`/`dependencies`/`time`/`activity`). `CommentThread` subscribes `window.Echo.private('project.{id}')` `.comment.synced` → refetch (only while mounted). `AttachmentList` uses `multipart/form-data` `{headers:{'Content-Type':'multipart/form-data'}}` + `fieldErrors`, download links open `download_url` in a new tab. `DependencyPanel` picker uses `topLevelTasks` (board tasks) for `depends_on_task_id`. Activities rendered via `describe(action, data)` label map (moved shows `from/to` status names, commented shows snippet).
- **Binding gotcha:** because `ImplicitRouteBinding` substitutes only the controller signature's model params, every collab method takes `(Request $request, Project $project, Task $task, …)` even when `$project` is unused — the extra bound param is what keeps the raw `{project}` string out of positional argument splicing. (See also the `ResolvesRouteDependencies` value-matching behavior.)

## Notifications (Phase 5)
- Access: `GET|POST api/notifications`-family routes live in the plain `auth → tenant` group in `routes/web.php` (not `tenant_context`) — personal notifications are self-scoped by `user_id`, so no tenant-only gate is needed. Routes: `GET notifications` (paginated, with `actor`), `GET notifications/unread`, `POST notifications/{notification}/read`, `POST notifications/mark-all-read`.
- `NotificationController` verifies `$notification->user_id === auth()->id()` in `markRead` (else 404), so users can't mark others' notifications read. `index` returns `{notifications, unread_count, pagination}`; each item = `{id, type, data, read_at, created_at, actor:{id,name}}`.
- Types + payloads (data always includes `task_id/key/title/project_id/project_name/workspace_id`): `task.assigned` (assignee only, skips self-assign), `task.status_changed` (+`from_status`/`to_status` names), `task.commented` (+`comment_id`/`snippet`; recipients = assignee + reporter + `@`-mentioned users, excluding the author), `task.unblocked` (+`blocked_by {id,key,title}`, only when the last open blocker is removed), `task.work_logged` (+`work_log_id`/`duration_minutes`, fires on work-log **create** only — assignee, skips the logger).
- Mention resolution (`NotificationService::mentionUsers`): regex `@([A-Za-z0-9._-]+)` matched **case-insensitively** against a same-tenant user's full email (`LOWER(email) = ?`), email local part (`LOWER(email) LIKE 'token@%'`), or name (`LOWER(name) = ?`). Dedupes users matched more than once.
- Wiring: notifications are a controller-side effect (like ActivityLogger) — `TaskController@store`/`@update` (assignee change → assigned; status change → status_changed), `TaskMoveController@move` (status change), `CommentController@store` (comment/mentions), `DependencyController@destroy` (was blocked → now unblocked), `WorkLogController@store` (work logged → `task.work_logged` to assignee).
- `NotificationSent::broadcastWith` includes `actor {id,name}` so clients render toast/messages without another fetch.
- Frontend: `NotificationContext` provider (nested in `ToastProvider`) — 30s poll of `/notifications/unread` + Echo `user.{id}` `.notification.sent` listener (increments badge count + shows a toast via `describeNotification`); `NotificationBell` dropdown in the Topbar (fetches latest 8, mark-read on click → navigates via `notificationHref`, mark-all-read); `/notifications` page (paginated, mark-read/mark-all-read, breadcrumb). Helpers in `resources/js/utils/notifications.js`.

## Time Tracking (Phase 6)
- Access: work-log + time-summary routes in `routes/web.php` sit in the domain group `auth → tenant → tenant_context → permission:workspaces.view`. `GET|POST projects/{project}/tasks/{task}/work-logs`, `PUT|DELETE …/work-logs/{workLog}`, `GET projects/{project}/time-summary`, `GET workspaces/{workspace}/time-summary`. Controllers MUST declare `(Request $request, Project $project, Task $task, WorkLog $workLog, …)` per the `{project}`/`{task}` binding gotcha; `{workLog}` verified `task_id === $task->id` else 404.
- `app/Services/WorkLogService.php`: `taskLogs` (paginated, `orderByDesc('started_at')`), `create`/`update` (duration recomputed as `max(1, round(diffMinutes))` **negated diff gotcha**: Carbon 2 `diffInSeconds` is signed — bound with `abs()`; **overlap rejection** on the same task, excluding self, boundary-touch units are allowed, open-ended logs conflict; 422 `form`), `delete` (hard), `effectiveMinutes` (running logs count elapsed-to-now), `taskAggregate` (`total_minutes`/`estimate_minutes`/`remaining_minutes`/`logs_count`; remaining null when no estimate), `projectSummary`/`workspaceSummary` (group by `user`|`status`|`date`; `from`/`to` date filters on `started_at`; `user_id` filter).
- `WorkLogPolicy`: `create` (`WorkLog::class, $task` array form = `work_logs.create` or tenant admin), `update`/`delete` (own log OR `work_logs.edit`/`work_logs.delete`/`work_logs.manage` project role OR tenant admin). Tenant admin = `workspaces.manage` bypass via `WorkspacePolicy`-style `hasPermission`.
- Validation: `started_at` required date; `ended_at` nullable date `after:started_at`; `description` ≤1000. Summaries validate `group_by ∈ {user,status,date}`, `from`/`to` dates, `user_id` integer.
- Response shapes: `{work_logs:[{id,started_at,ended_at,duration_minutes,effective_minutes,description,user{id,name,email}}], pagination, totals, my_role}`. Logs store a `duration_minutes` snapshot; `effective_minutes` recomputes running entries. Summaries → `{summary:{total_minutes, logs_count, group_by, groups:[{key,label,minutes,logs_count}]}}`.
- Side effects: `ActivityLogger` rows `task.work_logged|work_log_updated|work_log_deleted` (data `{work_log_id, duration_minutes, started_at}`); `NotificationService::workLogAdded` on create → `task.work_logged` to assignee (skip self). No dedicated broadcast event (work-log changes surface via the task's activity feed / list refetch).
- Frontend: `WorkLogPanel` (new **Time** sub-tab in `TaskDetail`; form = datetime-local started/ended + description, list rows with duration pill + edit/delete gated by `canLog` && own OR `canManage`); `TimeSummary` (`components/time/TimeSummary.jsx`; group_by + from/to filters; total + progress bars) in a **Time** tab on `ProjectDetail` (`/projects/{id}/time-summary`) and `WorkspaceDetail` (`/workspaces/{id}/time-summary`). `utils/time.js` `formatMinutes()` renders `Xh Ym`. UI permission mirror: `canLog = can('workspaces.manage') || my_role ∈ {lead,developer}`; `canManageLogs = can('workspaces.manage') || my_role === 'lead'`. Estimates also render on `TaskCard` (`est …` chip). Project roles catalog already includes `work_logs.create/edit/delete/manage`.

## Search & Reporting (Phase 7)
- Access: three read-only endpoints in `routes/web.php` in the `auth → tenant → tenant_context` group but **outside** the `workspaces.view` group — each has its own route-level permission: `GET search/tasks` → `permission:workspaces.view`, `GET dashboard` → `permission:dashboard.view`, `GET reports/overview` → `permission:reports.view` (a custom role could hold one without the other, so never blanket-gate with `workspaces.view`). No new permissions were needed — `dashboard.view`/`reports.view` already exist in `config/permissions.php`.
- `app/Http/Controllers/Concerns/ScopesVisibleTasks.php` — shared trait for all three: `visibleTaskQuery(User)` = `Task::query()` tenant-scoped via the model + `whereHas('project.members' → user_id)` **unless** the user holds tenant-wide `workspaces.manage`; eager `project, workspace, status, priority, assignee, labels`. `presentTask(Task)` = `TaskService::present()` + `updated_at` (ISO) + nested `project {id,name,key}` / `workspace {id,name}`. **This is the canonical "visible tasks" scoping for global/unscoped queries** — reuse it for any new global read.
- `SearchController::tasks`: validated filters `q` (LIKE title/key/description), `status_id`, `priority_id`, `assignee_id`, `project_id`, `workspace_id`, `label_id` (`whereHas('labels')`), `due_from`/`due_to`, `assignee=me`, `per_page` (1–100). `withCount` subtasks/comments/attachments/openBlockers; `orderByDesc('tasks.updated_at')` then `id`; pagination `{current_page,last_page,per_page,total}`. Response also carries `filters` (like the board's payload, tenant-wide): distinct statuses/priorities/assignees/projects/workspaces/labels **computed from the same scoped base** (`distinct()->pluck` FKs; label ids via `task_label` pivot subquery) — **note statuses are per-project rows, so cross-project page shows one status option per project**.
- `DashboardController` (`__invoke`): `counts {my_open, my_overdue, my_due_soon, open, done, in_progress}` (my-sets scoped `assignee_id = me`, non-archived; overdue `due_date < today`; due_soon next 7 days; `in_progress` via status `category = in_progress`; `open`/`done` by `completed_at` null/not) + lists `my_overdue`/`my_due_soon`/`in_progress`/`my_open` (≤6, due-date ordered) and `recent` (≤8 by `updated_at`).
- `ReportsController::overview`: `totals {total, open, done, overdue}` + `by_status`/`by_priority`/`by_assignee`/`by_project` distributions — each `[{key, label, count, open, done, color}]` (assignee label "Unassigned" for null), sorted by count desc.
- `AnalyticsController` (`GET analytics/overview`, `permission:dashboard.view`) — `counts {workspaces, projects, open, done, overdue, due_this_week, created_30d}` scoped like dashboard (managers → all, else membership; tasks via `visibleTaskQuery`), `projects_progress` (≤12 projects: open/done/total/percent), `work_logs {total_minutes, today_minutes, week_minutes, daily[14d]}` for **visible task ids** only, `tasks_created.daily[14d]` (per-day created counts), `top_contributors` (top 5 by 30d logged minutes). Route sits in the domain `auth → tenant → tenant_context → dashboard.view` group.
- Frontend: `Dashboard.jsx` (6 stat widgets + task lists with `{key} · title`, priority dot, links to `/projects/{id}?tab=tasks`; analytics charts via **Recharts** — project-progress stacked bars, 14-day hours-logged bars, 14-day task-creation line + top-contributors list; analytics fetch is best-effort, hidden on failure), `Reports.jsx` (totals + four distribution bars + a Time-logged scope picker driving `TimeSummary` from `/workspaces/{id}/time-summary` or `/projects/{id}/time-summary`), and new `pages/Search.jsx` at `/search` (workspaces.view-grouped route; filter bar for q/workspace/project/status/priority/assignee/label/due-from/to; results table; pagination like Notifications). "Search" nav item added in `Sidebar.jsx`. `ProjectDetail` now initializes its tab from `?tab=…` for deep links.

## Global Search & UI/UX (Phase 9)
- Endpoint: `GET api/search/global` (`GlobalSearchController`, `SearchController`-adjacent) lives in **`routes/web.php`** in the plain `auth → tenant` group with `permission:workspaces.view` — deliberately **outside** `tenant_context` so a non-impersonating super admin can cross tenants. The controller pins `TenantContext` to null internally when the caller is a super admin (like `TenantProvisioner`), so its queries temporarily ignore the tenant scope. `q` must be ≥2 chars (422). Returns `{query, results:{workspaces,projects,tasks,users}, total}`:
  - `workspaces`: same-tenant (visible scope), carries `tenant` name, `projects_count`.
  - `projects`: visible scope (tenant-manager → all; else project member), carries `workspace` name, `tasks_count`.
  - `tasks`: via `visibleTaskQuery()` (see Phase 7) **plus** the new `ScopesVisibleTasks::userManagesAllTasks()` bypass that treats a **non-impersonating super admin** as a tenant-wide manager; capped to 8, `orderByDesc('tasks.updated_at')`.
  - `users`: gated `users.view` (or super admin), scoped to the current tenant DB, matches name/email local part, sorted by name; **no navigation action** client-side.
- Frontend: `resources/js/components/search/CommandPalette.jsx` — Jira-style quick search. Triggered via global `⌘K`/`Ctrl+K` (Topbar keydown effect) and a Topbar "Search… ⌘K" button/icon; state owned by `AdminLayout`, gated on `can('workspaces.view')`. Debounced 250ms `GET /search/global` (AbortController), grouped results (Tasks/Projects/Workspaces/People) with entity icons, ↑/↓/↵/esc keyboard nav + mouse, min 2 chars. Navigates: task → `/projects/{id}?tab=tasks&task=KEY`; project → `/projects/{id}`; workspace → `/workspaces/{id}`.
- Task deep-link: `ProjectDetail` reads `?task=KEY` — forces the Tasks tab via tab-init (`?tab` wins) and an effect auto-opens the task drawer once the board/list pool contains it (ref-guarded so board refetches don't re-open). The query string is **kept in the URL** (no `replaceState` strip) so the link survives refresh/direct-tab — see Phase 10 for the section-aware form.
- UI primitives now live in `resources/js/components/ui/` (`Select`, `Modal`, `Drawer`, `EmptyState`, `Avatar`, `Spinner`, `fieldStyles.js`; enriched `Button`, `Input`, `Card`). Sidebar is collapsible (`w-64 ↔ w-16`, persisted via localStorage key `flowsync.sidebar.collapsed`, sectioned nav + active pill + accent bar). Toasts were modernized — compact tinted card, no progress bar, `warning` type added. Kanban board scrolls horizontally in one row (`board-scroll`) instead of a wrapping grid (fixes Done column dropping below Backlog). `TaskDetail` uses the shared `Drawer` (footer actions, meta-rail layout). Remaining grid/flex selects use `fieldClass`/`fieldClassCompact` directly (`Select` is label-wrapping and unfit for inline cells).

## Scale Seed Data & Deep Links (Phase 10)
- `database/seeders/ScaleDataSeeder.php` — `run(tenants=100, usersPerTenant=10, workspacesPerTenant=5, projectsPerWorkspace=5, tasksPerProject=100, related=true)` builds realistic same-tenant-isolated scale data: 1 super admin, tenants `tenant-{NNN}` (provisioned: default permissions/roles/priorities/project-roles + `owner@{slug}.test` admin), `usersPerTenant-1` extra users (roles cycle admin/editor/viewer), workspaces `w1..wN` with owner/admin/member pivots, projects keyed `{last-3-of-slug}-{w}-{p}` + 5 default statuses + lead/developer/viewer members, and **exactly `tasksPerProject` tasks** (bulk `DB::table` inserts — Carbon must be string-cast; ids captured via `DB::getPdo()->lastInsertId() - (tasksPerProject - 1)`) distributed `STATUS_WEIGHTS=[15,20,30,15,20]` (sums exactly to N), cycling priorities/assignees (`i%8===0 → null`), `due_date` on open tasks, `estimate_minutes` null on `i%3===0`, `completed_at` for done statuses, per-status column positions. Related data: comments `offset%7===0`, work logs `(offset+1)%5===0`, `task.assigned` notifications per worker at `offset=(u*4)%tasksPerProject` (data carries `task_id/key/title/project_id/project_name/workspace_id`). Enabled by `php artisan tenants:seed-scale [--tenants --users --workspaces --projects --tasks --no-related]`.
- Seeder test gotcha: `$this->seed(DatabaseSeeder::class)` runs via `db:seed --class` and accepts **no params** — tests invoke the seeder directly: `app(ScaleDataSeeder::class)->run(...)`.
- Deep links (SPA): workspace `/workspaces/{id}?tab=…`; project `/projects/{id}?tab=…`; task `/projects/{id}?tab=tasks&task={key}[&section=comments|attachments|dependencies|time|activity]`. **All** `?tab=`/`&task=`/`&section=` URL building lives in `resources/js/utils/deepLinks.js` (`taskUrl(projectId,key,section)`/`projectUrl(id,tab)`/`workspaceUrl(id,tab)`) — reused by `CommandPalette` `hrefFor`, `utils/notifications.js` `notificationHref(data,type)` (**type-aware** — `task.commented` → `section=comments`, `task.work_logged` → `section=time`; falls back to `/workspaces/{id}` when only `workspace_id` is present), Dashboard `TaskRow`, and Search results. `ProjectDetail` keeps the query **in the URL** (refresh/direct-tab safe), initializes/keeps `tab` in lockstep with `useSearchParams` (sync effect → back/forward safe), and `changeTab` writes `?tab=` back while pruning `task`/`section` when leaving Tasks; forces board view for deep links, auto-opens the drawer via `openedDeepTaskRef` (reads `searchParams`), and `openTask(task, section)` passes the section down; `TaskDetail` accepts an `initialSection` prop and forces that drawer sub-tab on `[task]` change (validated against details/comments/attachments/dependencies/time/activity). `WorkspaceDetail` tabs are fully URL-driven (derived `activeTab` validated against the computed tab set; `changeTab` pushes `?tab=` and clears it for projects). `ProtectedRoute` preserves `pathname + search` through the login redirect so an unauthenticated deep link resumes after login.
- Access denied: `ProjectController::show` 403s non-members (tenant admins bypass; cross-tenant 404 via tenant scope). Frontend `ProjectDetail`/`WorkspaceDetail` catch `err.response?.status === 403` → `navigate('/403', {replace:true})` (existing Forbidden page); other load failures keep the generic error state.

## Realtime (Reverb + Echo, Phase 0 base)
- Composer `laravel/reverb`; npm `laravel-echo` + `pusher-js` (+ `@dnd-kit/*` for the board, `recharts` for analytics). `BROADCAST_CONNECTION=reverb`.
- `app/Events/NotificationSent` broadcasts on `PrivateChannel('user.{id}')` (queued, `broadcastWith` includes `actor {id,name}` for client rendering). `app/Events/TaskSynced` broadcasts on `PrivateChannel('project.{id}')` (`broadcastAs('task.synced')`, queued). `app/Events/CommentSynced` broadcasts on `PrivateChannel('project.{id}')` (`broadcastAs('comment.synced')`, queued). Broadcast channel auth in `routes/channels.php`: `user.{id}` (self only), `workspace.{id}` + `project.{id}` (membership). Same checks used for realtime sync events.
- Client: `resources/js/echo.js` bootstraps `window.Echo` from `VITE_REVERB_*` env (`.env` only; `.env.example` has blank reverb keys so tests are unaffected).
- **Channel/event name rule:** events MUST broadcast on the channel clients subscribe to (`private-` prefix is applied by `PrivateChannel`/Echo automatically — never hardcode `private-` twice).

## RBAC & provisioning
- `config/permissions.php` — canonical 12-permission catalog + role definitions (`admin` = `*`, editor/viewer get subsets incl. workspace perms).
- `config/project_roles.php` — project-permission catalog (view/create/edit/delete/assign/move/comments/attachments/work_logs/members/settings) + system roles lead/developer/viewer.
- `app/Support/TenantProvisioner.php` — clones catalog + roles + priorities + project roles + owner admin per tenant; **idempotent** (`firstOrCreate`, pins tenant context null internally), safe to call repeatedly.
- `app/Models/Tenant.php`, `Role`, `Permission`, `User`; pivots `role_user`, `permission_role`.
- `TenantSeeder` = super admin + Acme (demo users) + Globex; called from `DatabaseSeeder`.

## Task-management services (Phase 0 shells, grown in P1+)
- `app/Services/ActivityLogger.php` — append-only polymorphic audit (`log()` + `forSubject()`).
- `app/Services/NotificationService.php` — `notify()` creates `notifications` row + broadcasts `NotificationSent` on the recipient's private channel; `forUser()` paginated list (with `actor`), `unreadCount()` / `markAllRead()`; scenario helpers `taskAssigned` / `taskCommented` / `taskStatusChanged` / `taskUnblocked` (each skips self-notification) and `mentionUsers()` (parses `@token` → tenant users matched case-insensitively by email, email local part, or name).
- `app/Services/KeyGenerator.php` — atomic per-project task key allocation.
- `app/Services/WorkspaceService.php` — workspace CRUD + members (see Workspaces section).
- `app/Services/ProjectService.php` — project CRUD + members + statuses/workflow (see Projects section).
- `app/Services/TaskService.php` — task CRUD + board/list/move + filters (see Tasks section); board/list/`show` payloads include `open_blockers_count` (`withCount('openBlockers')`), and `show` lazy-loads comments/attachments/subtasks counts.
- `app/Services/WorkLogService.php` — work-log CRUD + overlap/duration rules + task/project/workspace time summaries (see Time Tracking section).

## Impersonation (super admin → tenant user)
- Session key `impersonate` = `['log_id', 'tenant_id', 'original_user_id', 'original_user_name']`; then `Auth::login($target)` + `session()->regenerate()`.
- Controllers: `ImpersonationController@start` (super_admin | `POST api/impersonate`) and `@stop` (`POST api/impersonate/stop`, only `auth`, works while impersonating).
- **Tenant-local id collision gotcha:** `user_id` is a per-tenant DB id (every tenant's owner is local id
  1), so the routing lookup must be disambiguated by `tenant_id` — `start` accepts an optional
  `tenant_id` and scopes `TenantUserRouting` `where('tenant_id', …)`; the super-admin UI always sends it.
- Audit rows in `impersonation_logs` (`super_admin_id`, `tenant_id`, `impersonated_user_id`, `ip_address`, `started_at`, `ended_at`).
- Stop must resolve the original super admin from the system DB (`connectSystem()` before the lookup).
- Cannot impersonate super admins; `AuthController::me`/`logout` include impersonation state.

## Theming
- Per-user theme stored in `user_settings.settings['theme']`; defaults in `config/theme.php`.
- ThemeController `GET/PUT api/theme` (PUT requires `settings.theme` permission).
- CSS custom props are **hyphenated** (`--sidebar-bg`); `resources/js/theme.js` `applyTheme()` maps underscores→hyphens. Keep that mapping in sync.

## Frontend conventions
- Routing in `resources/js/App.jsx`: `GuestRoute` (login/forgot/reset only — **no register**), `ProtectedRoute` (optional `permission` prop), super-admin-only `/tenants` route.
- `context/AuthContext.jsx`: `user`, `theme`, `loading`, `login`, `logout`, `stopImpersonation`, `can()`, `refresh`. `can()` returns true for super admin unless `user.impersonating`; tenant users rely on `user.permissions`.
- `context/ThemeContext.jsx` (live draft + save/reset), `context/ToastContext.jsx` (success/info/error).
- `services/api.js`: axios base `/api`, `withCredentials`, `withXSRFToken`; on 401 redirects to `/login`; `fieldErrors()` helper.
- In `AdminLayout`, **only the page-content div is keyed by `location.pathname`** — never wrap the whole `<Routes>` (causes remount blink).
- Breadcrumbs: `context/BreadcrumbContext.jsx` (`useSetCrumbs`) + `components/Breadcrumbs.jsx` (renders account crumb = tenant name → `/workspaces`, super admin → `/tenants`). AdminLayout wraps content in `BreadcrumbProvider`. Pages set crumbs via `useSetCrumbs([{label, to?}])` in an effect; ProjectDetail uses `project.workspace` + `project.name`.
- `ImpersonationBanner` renders when `user.impersonating`; sidebar gets `top:2.5rem; height:calc(100%-2.5rem)` and the sticky topbar gets `top-10` to sit below it.
- Workspaces UI: manageability of a workspace is decided client-side with `can('workspaces.manage') || ['owner','admin'].includes(my_role)`; member/label write endpoints return `{message}` only, so pages refetch the list/`/users` after a mutation; the add-member user picker (GET `/api/users`) is gated by `users.view` and skipped when the user lacks that permission.
- Projects UI: manageability is `can('workspaces.manage') || my_role === 'lead'`; project members are role-selected via GET `/api/project-roles`; category selects drive workflow edits; member/status write endpoints return `{message}` only, so the page refetches project+members+statuses after a mutation. Sidebar has a global **Projects** nav item (gated `workspaces.view`) → `pages/Projects.jsx` at `/projects` (grouped by workspace, `GET /api/projects`). The add-member picker fetches `/api/users` on mount (gated `users.view`, skipped when lacking) so the select is populated without a prior mutation.
- Tasks UI: ProjectDetail's **Tasks** tab owns board/list state (`view`, `filters`, `board`, `listTasks`, `taskOptions`, `selectedTask`); tasks load lazily (`tab === 'tasks'`) and refetch on `view`/`filters` change and on `task.synced` broadcasts. Board/list modal+drawer (`CreateTaskModal`, `TaskDetail`) take `options` from the response's `filters` payload; opens fetch full detail via `GET tasks/{task}` (`openTask`), saves refetch the board (`updateTask`/`deleteTask`/`createTask` throw on error so forms can surface `fieldErrors`). Drag-drop posts `{status_id, index}` then refetches.
- Notifications UI: `NotificationBell` in the Topbar (badge = unread count, dropdown = latest 8 with avatar/message/relative-time, click marks read + navigates to the task via `notificationHref`) and `/notifications` page (paginated list, per-item mark-read on click, "Mark all as read", breadcrumb). Live updates come from `NotificationContext` (Echo `user.{id}` `.notification.sent` → toast via `describeNotification`; 30s unread poll fallback when Reverb is off).

## Multi-tenant SaaS architecture (Phase 11 → 13 shipped; P14+ in progress)
- **Read `docs/multi-tenancy-architecture.md` before any architectural work.** Direction: PostgreSQL
  **one database per tenant** + central system DB. **Phase 13 shipped: the app is isolated-only** —
  `TENANCY_DRIVER=shared` no longer exists (default `isolated`); tenant DBs are PostgreSQL in prod and
  sqlite **files** (fast-path) for local/dev/tests, plus a central `system` DB.
- **Phase 11 shipped (foundations):** `docker-compose.yml` (postgres:17, system DB `flowsync_system`);
  `config/database.php` gains `system` + `tenant` pg connections; `config/tenancy.php`
  (driver/connection/db-prefix). `app/Support/TenantDatabaseManager.php` (singleton, bound in
  `AppServiceProvider`) — `dsn()`, `connect()`, `connectSystem()`, `using()`, `centralConnectionName()`,
  `tenantDriver()`, `tenantDatabasePath()`, `createDatabase()`, `migrateTenant()`. Tenant model
  expanded by migration `000013` (status/provisioning_status/provisioning_error/provisioned_at,
  `db_name|host|port|user|password` encrypted via `encrypted` casts, `subscription_id` index only —
  FK lands with the subscriptions table in P14, billing/contact/trial, `limits_override|features_override
  |onboarding_meta|settings` JSON, soft deletes) with model defaults `status=active` +
  `provisioning_status=provisioned`. Migration `000014`: `platform_roles`/`platform_permissions` +
  pivots (`platform_role_permission`, `platform_user_role`) and `audit_logs` (polymorphic).
  `app/Services/TenantLifecycle.php` — status state machine (pending/provisioning/trial/active/
  suspended/expired/deactivated/provisioning_failed), `canTransition()/assertTransition()/transition()`
  writes `audit_logs` rows (`tenant.status_changed`). Models: `PlatformRole`, `PlatformPermission`,
  `AuditLog`. `is_super_admin` remains the master platform gate (platform RBAC is schema-only for now).
- **Phase 12 shipped (provisioning & routing):** migration `000015` adds central `tenant_users`
  (`TenantUserRouting`: normalized `email`, `tenant_id` FK, `user_id`-in-tenant-DB, `name`;
  unique `(tenant_id, email)`) + `provisioning_runs` (per-attempt run/status/step/error/timestamps).
  `app/Models/Concerns/CentralConnection.php` trait (`getConnectionName()` →
  `TenantDatabaseManager::centralConnectionName()`: shared → `database.default`, isolated →
  `tenancy.system.connection`) is applied to all central models (`Tenant`, `ImpersonationLog`,
  `AuditLog`, `PlatformRole`, `PlatformPermission`, + new `TenantUserRouting`, `ProvisioningRun`). (The old `TenantScoped` row-scoping was removed entirely in
  Phase 13 — tenant `tenant_id` columns no longer exist; the DB is the boundary.)
  `config/tenancy.php` adds `tenant.driver` (env `TENANT_DB_DRIVER`, default `pgsql`, `sqlite` =
  first-class local/dev/test fast-path via `tenant.db_path` env `TENANT_DB_PATH` files
  `{slug}_{id}.sqlite`) — PG paths (`createDatabase`/`createPostgresDatabase`/`postgresAdminConnection`)
  are behind the same code but only exercised when a PG is reachable. `TenantDatabaseManager` has a
  safe `switchDefault()` that **only purges the target connection when it differs from the current
  default** (purging an in-memory sqlite `:memory:` connection wipes the test DB — never
  `connectSystem()` while default is `:memory:` sqlite; isolated tests use a file-backed custom
  `iso_system` connection). `TenantProvisioner::provisionIsolated()` is the idempotent pipeline
  (connect → `createDatabase` → `migrateTenant` → seed → owner local `createAdmin` → `syncRouting`
  tenant_users → trial/active; skipped re-transition when already serviceable+provisioned).
  `app/Jobs/ProvisionTenantJob.php` (`tries=1`, no auto-retry; repair via
  `tenants:provision`) records `provisioning_runs`, transitions to provisioning, and on failure marks
  tenant `provisioning_failed` + `provisioning_error`. `app/Http/Middleware/SwitchTenant.php`
  (`switch_tenant` alias in `bootstrap/app.php`) — both authenticated route groups now start
  `['switch_tenant', 'auth', 'tenant'…]`; it resolves the per-tenant connection using the session keys
  `login.tenant_id` / `impersonate.tenant_id` (carrying **central** ids) and calls `using()`.
  `SetTenantContext` gained an isolated branch (context from session keys, not `user->tenant_id`).
  `AuthController::loginIsolated()` routes emails via `TenantUserRouting` (optional `tenant` slug
  disambiguator — >1 route without slug ⇒ 422 `tenant`), super admins fall back to the system DB;
  **gotcha: strip `tenant` from the attempt credentials** (`Arr::except($credentials, ['tenant'])`)
  or `Auth::attempt` builds `where tenant = ?` against `users` and fails. `ImpersonationController`
  gained `startIsolated()` (routing-based resolve) + connects system on stop. `TenantController` store
  → isolated: pending + dispatch job (202); `users()`/`counts()`/`index()` read via routing.
  Super-admin tenant management: `index` now validates `q`/`status`/`plan_id`/`trashed`/`sort`(`name|slug|
  status|created_at|updated_at|users_count`)/`dir`/`per_page` and returns `{tenants, pagination}` —
  `users_count` via `Tenant::routingUsers()` withCount (when sorting by it) or a per-page routing pluck;
  `subscription.plan` eager-loaded and inlined as `plan_slug/plan_name/subscription_status`. Added
  `destroy` (soft) + `POST …/restore` (**route declared `->withTrashed()`** so a trashed tenant still binds,
  while `suspend`/`activate` on a trashed tenant 404s) + `suspend`/`activate` via `TenantLifecycle`
  transitions; each writes an `audit_logs` row (`tenant.deleted|restored|status_changed`). `GET
  /tenants/{tenant}/stats` counts `users/workspaces/projects/tasks` on the tenant DB through
  `TenantDatabaseManager::using()` and `Cache::remember(…, 60)`. Frontend `Tenants.jsx` is a filterable
  table (filters, sort, "Include deleted", per-tenant ⋯ menu: View/Edit/View-as-user/Enable-Disable/
  Delete-Restore, edit modal, pagination footer; row subscription fields inline — no per-row lazy fetch);
  `TenantDetail` shows a lazy Usage card from `/stats`.
  `ProvisionTenants` command repairs isolated tenants. Tenant DBs run the **full current migration set**
  (central-table clutter accepted pre-cutover).
- **P13 cutover shipped** (see the Tenant-isolation section above; full test suite now runs through
  `Tests\IsolatesDatabase` on per-tenant sqlite files).
- **Phase 14 (subscriptions) shipped code** — see the Subscriptions section below.
- **Not yet wired (later phases):** `switch_role`/guard refinements, usage + module gates (P15),
  Super Admin platform (P16).
- Phase plan + Jira-feature expansion map + security/testing/migration strategy: see the doc (§14, §10, §12, §13, §11).

## Subscriptions (Phase 14)
- **Central/system** (migration `2026_09_24_000016_create_subscription_tables.php`):
  `subscription_plans` (limits JSON), `subscriptions` (**unique `tenant_id`** — one re-stamped row per
  tenant; statuses trialing|active|past_due|canceled|expired|ended), `subscription_events`
  (type subscribed|plan_changed|renewed|trial_started|trial_expired|canceled|reactivated|paused|
  payment_failed|seats_changed, from/to_plan_id, data, actor_id → system users). `tenants.subscription_id`
  FK lands **only on PostgreSQL** — the sqlite fast-path can't ALTER ADD CONSTRAINT, so it gets a plain
  index (app-level FK via the model).
- `config/subscriptions.php` = machine-readable catalog (modules: time_tracking/reports/global_search/
  api/branding/audit_export; numeric limits: users/seats/workspaces/projects/tasks/storage_bytes/
  attachments_per_task; default plans starter/pro/enterprise). Seeded idempotently by
  `SubscriptionPlanSeeder` (`updateOrCreate` by slug, `is_default` guarded), which runs inside
  `TenantSeeder` and `TenantController::store`.
- Models `SubscriptionPlan` (`limit($key)`/`hasModule($module)`/`periodEnd(?Carbon)`),
  `Subscription` (`isActive()`/`onTrial()`; `Subscription::EVENT_*` constants), `SubscriptionEvent` —
  all `CentralConnection`; `Tenant` gained `subscription()`/`subscriptions()`/`subscriptionEvents()`.
- `app/Services/SubscriptionService.php` — `assign` (first subscribe **or** re-stamp + `plan_changed`),
  `startTrial` (updates `tenant.trial_ends_at`, lifecycle → TRIAL), `switch`, `cancel`, `renew`
  (reactivated vs renewed), `suspend` (past_due + `paused`), `record()` event writer. Lifecycle-synced:
  active↔trial via `TenantLifecycle`.
- `app/Services/TenantLimits.php` — `effective(Tenant)` = plan.limits ⊕ `tenants.limits_override`
  (override wins); **no subscription ⇒ unlimited** (keeps seeded acme/globex and legacy tenants running);
  `assertQuota(resource, context)` counts on the tenant (default) connection and throws
  `ValidationException` → 422 `form`; **no-op when `TenantContext::currentId()` is null**
  (provisioning/seeders/direct-service tests). Consulted by `UserController::store` + every create in
  `WorkspaceService`/`ProjectService`/`TaskService`. `hasModule()` helpers ready for P15 gates.
- Onboarding: `TenantController::store` validates plan_id (exists:subscription_plans,id), trial_days,
  billing_email/contact_*; sets `trial_ends_at` **before** dispatching so `provisionIsolated` lands the
  lifecycle on `trial`. `ProvisionTenantJob` constructor gained optional `?int $planId` / `?int $trialDays`
  and, post-provisioning, creates/re-stamps the subscription + `trial_started|subscribed` event
  (falls back to default plan; no-op when no plans exist).
- Routes (super_admin group, system scope): `GET|POST /api/plans`, `PUT|DELETE /api/plans/{plan}`;
  `GET|POST /api/tenants/{tenant}/subscription`, `POST …/subscription/trial|cancel|renew|suspend`,
  `GET …/subscription/events`. Controllers: `PlanController`, `TenantSubscriptionController`.
- Frontend: `pages/Plans.jsx` at `/plans` (sideber super-admin entry; CRUD modal incl. module toggles);
  `Tenants.jsx` create form gained a plan picker + trial days and tenant cards show a subscription pill
  (lazy `GET /tenants/{id}/subscription`).
- **Tenant-facing self-service** (Item 5): `GET api/my-subscription` (current subscription + plan +
  recent events + tenant), `GET api/my-usage` (TenantLimits counts per users/seats/workspaces/projects/
  tasks + effective limits + modules), `GET api/plans` (**role-aware** — one route now: super admin sees
  the full catalog, any tenant user the active plans only), `POST api/my-subscription/switch|cancel|renew`
  (all admin-gated via `hasRole('admin')` 403; a non-impersonating super admin gets 404 — no tenant
  context). These live in the plain `auth → tenant` group (OUTSIDE the onboarding gate so the wizard's
  subscription step can read plans). `MySubscriptionController` self-scopes via `TenantContext`.
  Frontend: `pages/Subscription.jsx` at `/subscription` (current plan card, usage meters, included/
  excluded modules, upgrade grid, cancel/renew only for admins) + a Billing sidebar section (items with
  no `permission` are always shown — sidebar filter now keeps `!item.permission`).
  **`actor_id` gotcha:** tenant admins are NOT central `users` rows, so passing `auth()->id()` as
  `SubscriptionEvent.actor_id` violates the FK → tenant-side actions omit the actor (the actor travels
  in `data.actor` for switches; null for cancel/renew).
  **`exists:` rule gotcha:** the `exists:subscription_plans,id` validation rule resolves against the
  DEFAULT connection — on a tenant request that's the tenant DB (no `subscription_plans`), so
  `MySubscriptionController` uses a `SubscriptionPlan::find()` + 422 instead of the rule (the SA
  controller keeps the rule safely because SA requests run on the system connection).
- Latent bugs fixed: `ProvisionTenantJob::handle()` **lacked the `TenantProvisioner $provisioner`
  parameter** (undefined-variable crash); `IsolatedProvisioningTest` asserted a local `tenants` row +
  `users.tenant_id` + a void return from `provisionIsolated` (rewritten for Phase 13 reality).
- Phase plan + Jira-feature expansion map + security/testing/migration strategy: see the doc (§14, §10, §12, §13, §11).

## Tenant onboarding (Phase 14)
- Optional self-service onboarding for new tenants, behind `config/onboarding.php` `enabled` (env
  `ONBOARDING_ENABLED`, **default off** → public registration is 403). Toggling + `docker-compose up -d app`
  re-reads it (read at boot). Steps catalog: business → admin → subscription → configuration (optional) →
  verification (optional) → completion (required) — all persisted to `tenants.onboarding_meta` JSON.
- `app/Services/TenantOnboarding.php` (singleton-service): `status()` (per-step complete + overall
  pending/in_progress/complete), `start()` (self-registration entry), `markStep()` (**rejects the terminal
  `completion` step** — only `business/admin/subscription/configuration/verification` via `completableSteps()`
  with full-catalog `validate`; overview + `complete()` mark everything), `complete()`, `reset()` (SA repair),
  `isComplete()`. **Gating rule:** completed_at set → complete; **never started the wizard → complete**
  (admin/seed/SA-provisioned tenants bypass automatically — self-registration is the only entry point);
  otherwise all required steps done.
- `app/Http/Middleware/EnsureOnboardingComplete.php` (**alias `onboarding_complete`**, registered in
  `bootstrap/app.php` priority BEFORE `SubstituteBindings`) 403s the whole domain route group
  (`switch_tenant → auth → tenant → tenant_context → onboarding_complete` at `routes/web.php`) until the
  tenant completes. Non-impersonating SA bypasses (no tenant context). The wizard's own endpoints live in the
  plain `auth → tenant` group (next to `tenant/profile`): `GET onboarding`, `PUT onboarding/step`,
  `POST onboarding/complete`; SA: `GET|PUT tenants/{tenant}/onboarding` (`step` = step key | `complete` |
  `reset`).
- **Public registration:** `POST api/register` (`RegisterController::store`, `throttle:10,1`) creates the
  central tenant (pending, `trial_ends_at` set for the selected/default plan's trial) — **flag `onboarding_meta`
  started BEFORE provisioning** (same Phase-14 pattern as `trial_ends_at`) — then `Bus::dispatchSync(
  ProvisionTenantJob(...))` (sync so the registrant can log in immediately), re-`syncRouting` after claiming,
  and auto-logs in via `AuthController::establishTenantSession()` (**runs the payload build inside
  `TenantDatabaseManager::using($tenant)`** so `$user->load('roles')` hits THAT tenant's DB, not the restored default).
  The provisioned `owner@{slug}.test` account is claimed for the registrant (name/email/password swap) and its
  stale `tenant_users` routing row is deleted afterward. Emails are unique per register (checked against
  `tenant_users` routing + central `SystemUser`). Colliding slugs get a `-2/-3…` suffix.
- `AuthController::payload` now carries `onboarding_complete` (true for no-tenant SA + never-started tenants).
- Frontend: `pages/auth/Register.jsx` at `/register` (GuestRoute; link on Login footer) → on success navigates
  `/onboarding`; `pages/Onboarding.jsx` wizard at `/onboarding` (progress bar, per-step actions, business
  mini-profile form → PUT `/tenant/profile` then marks the step, `Finish` → `POST /onboarding/complete` →
  `refresh()` + `/dashboard`). `AdminLayout` redirects any tenant user with `onboarding_complete === false`
  (except on `/onboarding` itself) to the wizard. `AuthContext.register()` mirrors `login()`.

## Pitfalls / gotchas
- **Laravel `SortedMiddleware` reorders route middleware by the Kernel `$middlewarePriority` list.**
  Any custom middleware that must run BEFORE route-model binding (Swizzlen i.e. `SwitchTenant`,
  `SetTenantContext`, `EnsureTenantContext`, `EnsureSuperAdmin`, `EnsurePermission`) MUST be registered
  in `$middleware->priority([...])` in `bootstrap/app.php` (positioned before `SubstituteBindings`) —
  otherwise binding runs on the connection the previous request left behind (the central DB in tests →
  "no such table: <table>" 500s on iso_system). If you add a new DB-switching middleware to a route
  group, update that priority list.
- **`TenantDatabaseManager::connect()` purges the `tenant` connection whenever the tenant id changes**
  (the connection manager caches it by name across all tenants, so without the purge a `using($tenant)`
  for a second tenant silently re-hits the first tenant's DB) and `restore()` rebuilds the tenant
  connection from the previously-active tenant — `using()` is therefore safe to nest across different
  tenants. Never add a purge-skip for the *tenant* name; the purge-protection lives in
  `switchDefault()` and only guards the central/system connection.
- No `/register` route, page, or endpoint — tenant admins create users via `POST /api/users`; create roles via `POST /api/roles`.
- React requires `@vitejs/plugin-react@5` (v6 needs Vite 8); `app.blade.php` needs `@viteReactRefresh` with `react()` plugin in `vite.config.js`.
- CSS: global scrollbar hiding + `html/body overflow-x:hidden` in `resources/css/app.css` (keeps theme drawer off-screen).
- DB is SQLite (tenant fast-path): schema changes requiring drops need explicit `dropUnique`/`addUnique` rebuilds.
- **Laravel 12 `Migrator` uses non-recursive `glob($path.'/*_*.php')`** — migration paths must always be
  passed explicitly: system DB `--path=database/migrations/system`, tenant DBs
  `--path=database/migrations/tenant`. Plain `php artisan migrate`/`migrate:fresh --seed` runs **nothing**
  (reset via `migrate:fresh --database=system --path=database/migrations/system --seed`). Tests do this
  automatically inside `Tests\IsolatesDatabase` and `TenantProvisioner::provisionIsolated`.
- Broadcast events fire through the queue: `QUEUE_CONNECTION` must be `sync` in tests, and `Event::fake()` (NOT `Broadcast::fake()`) intercepts queued broadcasts. Channel auth should be tested by invoking `Broadcast::driver()->getChannels()` callbacks directly (NullBroadcaster returns 200/empty in tests).
- Inline test routes that pass through `tenant`/`tenant_context` need the `web` middleware group (or you get "Session store not set on request").
- Test isolation is handled by the **`Tests\IsolatesDatabase` trait** (hooks via `setUpTraits()`) — it
  creates a file-backed `iso_system` sqlite + per-tenant sqlite files under
  `tenancy.tenant.db_path`, migrates/seeds them, and leaves the default connection on `acme`.
  Wild `Config::set('tenancy.driver', 'isolated')` is NOT enough (the `TenantDatabaseManager` singleton
  is cached); never `connectSystem()` while the default connection is `:memory:` sqlite — purging that
  connection wipes the test DB.