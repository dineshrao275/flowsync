# 06 — TMS (Task Management)

Hierarchy: **Tenant → Workspace → Project → Task** (subtasks via `tasks.parent_id`
self-FK, cascade). Task keys atomic via `KeyGenerator::nextTaskKey()` → `[key, sequence]`
(`KEY-N`, `last_task_sequence` bump in transaction).

## Workspaces (`WorkspaceService`, `WorkspacePolicy`, `LabelPolicy`)

List: tenant-admin (`workspaces.manage`) sees all, else member-only. Create needs
`workspaces.create`; per-object auth via policies (editor can manage a workspace
they own/admin). Creator auto-owner; slug auto-suffixed. Delete blocked 422 if
projects exist. Membership invariants: same-tenant users only, no duplicates, never
demote/remove the last owner. Labels scoped to workspace, unique per workspace.
Payloads carry `my_role` + `*_count`; member/label writes return `{message}` (refetch).

## Projects (`ProjectService`, `ProjectPolicy`)

Key auto-derived from initials ("Website Redesign" → `WR`), unique per tenant; 5
default statuses seeded from `config/task_statuses.php`; creator auto-**lead** +
`lead_user_id`. Global list `GET /api/projects`. Members must be same-tenant AND
workspace members; ≥1 member kept. Status invariants: can't delete in-use or last
status; positions 1-based, renormalized. Project roles: system lead/dev/viewer
immutable; custom deletable only while unused. Payloads carry `my_role` (slug).

## Tasks (`TaskService`, `TaskPolicy`)

Endpoints all project-scoped: index (board/list), show, store, update, destroy,
`move`. Create: `ProjectPolicy::createTask`; rest: `TaskPolicy` (`tasks.*` project
perms; tenant-admin bypass). `update` with `assignee_id` also needs `assign`.
Resolvers 422: status ∈ project, priority ∈ tenant, assignee = same-tenant project
member, parent ∈ project, labels ∈ workspace. Move to Done hard-blocked 422 when
`hasOpenBlockers()`. Move renumbers both columns 1..N in a transaction. Board =
top-level `whereNull(parent_id)` grouped by status; `open_blockers_count`
(`withCount('openBlockers')`) on board/list/show. Broadcasts `TaskSynced`
(`created|updated|deleted|moved`) on `private project.{id}` as `task.synced`. Soft
deletes: trashed rows 404, excluded everywhere (locked by `HardeningTest`).

## Collaboration

- **Comments:** nested tree (one level), `parent_id` must be same-task top-level.
  `CommentPolicy` auto-discovered; create via array form. Broadcasts `CommentSynced`
  as `comment.synced`.
- **Dependencies:** `{blocked_by, blocks}`; rejects self-dep, cross-project, dupes,
  BFS-detected cycles. `TaskDependency` has no `tenant_id`.
- **Attachments:** `File::types([jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,
  txt,md,csv,zip,json])->max(10MB)` on `local` disk
  (`storage/app/private/tasks/{project}/{task}/{uuid}.{ext}`). Signed download
  `attachments.download` outside auth (see `02-backend-conventions.md`). Delete
  removes the file too. **SVG in allow-list = stored-XSS risk** (see `10-security.md`).
- **Activity:** `ActivityLogger::log()` polymorphic rows; wired on task
  create/update/delete/move, comments, deps, attachments. Order by `id` desc.

## Notifications (in-app only — email NOT wired)

`GET notifications` (paginated + `actor`), `GET notifications/unread`,
`POST …/read`, `POST …/mark-all-read` — plain `auth → tenant` group (self-scoped,
no `tenant_context`); platform SA short-circuits (empty/`count: 0`/404).
Types: `task.assigned` (skip self), `task.status_changed`, `task.commented`
(assignee + reporter + **@mentioned**, minus author), `task.unblocked` (last blocker
removed), `task.work_logged` (create only, to assignee, skip self).
`mentionUsers()`: regex `@([A-Za-z0-9._-]+)`, case-insensitive vs full email /
local part / name. Broadcasts `NotificationSent` on `private user.{id}` with
`actor{id,name}`; SPA 30s poll + Echo toast via `describeNotification`.
**Email gap:** zero `Mail::` in `app/`; `MAIL_MAILER=log`; roadmap Phase 4 adds
Mailable + queue + prefs for assignee + board members.

## Time tracking (`WorkLogService`, `WorkLogPolicy`)

CRUD on task work-logs; duration `max(1, round(abs(diff)))`; overlap rejected 422
(same task, excl. self, boundary-touch allowed); open-ended logs conflict.
Summaries by `user|status|date` at task/project/workspace scope with from/to/user
filters. Logs `task.work_logged|work_log_updated|work_log_deleted`; create notifies
assignee. `formatMinutes()` → `Xh Ym`. Project-role perms
`work_logs.create/edit/delete/manage`; gated routes behind `ensure_module:time_tracking`.

## Search & reporting (read-only)

`GET search/tasks`, `GET dashboard`, `GET reports/overview`, `GET analytics/overview` —
each with its OWN route permission (`workspaces.view` / `dashboard.view` /
`reports.view`), never blanket-gated. Shared scope:
`ScopesVisibleTasks::visibleTaskQuery()` (+ `userManagesAllTasks` SA bypass) with
`project,workspace,status,priority,assignee,labels` eager loads — the canonical
"visible tasks" scope for any new global read. Global `GET search/global` is
deliberately outside `tenant_context` (cross-tenant for SA, nulls `TenantContext`
internally). Reports distributions `{key,label,count,open,done,color}`; analytics =
counts + project progress + 14-day work-log/creation series + top-5 contributors
(Recharts). Exports: NONE for TMS (roadmap Phase 5).
