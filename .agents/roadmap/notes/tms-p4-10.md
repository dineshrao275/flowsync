# Track notes: TMS WIP limits & swimlanes (branch `Improve/improvements-new-work`)

Task: **P4.10** (swimlanes + WIP limits, deps P4.2 ✅). Committed directly on
`Improve/improvements-new-work` as `481b2c4` ("feat(tms): per-status WIP limits and assignee
swimlanes (P4.10)"), per the continuing dev-line policy (no orchestrator worktree; no pushes to
origin). Focused suite `tests/Feature/TaskBoardWipSwimlaneTest.php` passes in the sandbox
(9 tests / 53 assertions).

## What shipped

- Migration `database/migrations/tenant/2026_11_03_000051_add_wip_limit_to_task_statuses.php`:
  `task_statuses.wip_limit` nullable unsigned int, `after('entry_rules')`. A plain
  `Schema::table()` add (native `ALTER TABLE ADD COLUMN` on SQLite — **not** `constrained()`, so no
  table rebuild and no CHECK loss). The new suite guards the `category` CHECK survived it.
- WIP enforcement in `app/Services/TaskService.php::assertWip()`: counts a column's **open top-level**
  cards (`parent_id IS NULL`, `completed_at IS NULL`) — matching the board's `open_count` — and 422s
  `form` with `"<name>" is at its WIP limit (N). Move a card out or raise the limit.` Called on
  arrivals into a column: `create()` (no exclude), `update()` (only when the status moves), and
  `move()` (excluding the mover when it is already in the target column, so a reorder keeps its own
  occupancy).
- `task_statuses.wip_limit` surfaced everywhere a status is: `TaskStatus` fillable + integer cast,
  `TaskPresenter::presentStatus()`, `StatusController::present()`, `WorkflowController` payload,
  `StatusStoreRequest`/`StatusUpdateRequest` (`nullable|integer|min:1`), and `ProjectService`
  `addStatus()`/`updateStatus()` (explicit `null` clears via `array_key_exists`, not `??`).
- Board swimlanes in `app/Services/Tasks/TaskReader.php`: `board()` extracted a shared
  `buildColumn()` (now also emitting `open_count`, computed in the single pre-count query via
  `count(case when completed_at is null then 1 end)`); `?swimlane=assignee` delegates to
  `boardByAssignee()` which groups the row-scoped top-level tasks by assignee, returns
  `{swimlanes:[{assignee:{id,name}|null, statuses:[...], totals:{open,done}}], totals}` (lanes by
  lowercased assignee name, unassigned last). Flat boards are unchanged in shape.
- `TaskController::index` validates `swimlane => ['nullable','in:none,assignee']`.
- SPA: `KanbanBoard.jsx` renders lanes (each `BoardRow` owns its own `DndContext`; droppable ids
  stay `col-{id}`) and a `WipBadge` showing `tasks_count / wip_limit` (red when full) or the plain
  count when unlimited. `ProjectDetail.jsx` gains a swimlane `useState` + picker (board view only),
  threads `swimlane=assignee` through `loadTasks()`, is lane-aware in `handleDragEnd`, flattens the
  lane columns for the deep-link pool, and edits each status's WIP limit inline in the Workflow tab
  (plus an optional field on the New-status form).
- Test: `tests/Feature/TaskBoardWipSwimlaneTest.php` — WIP in status/board payloads, validation,
  move/create/update into a full column refused with the exact message, same-column reorder allowed,
  freeing a slot allows the move, the count ignoring subtasks + completed cards, swimlane grouping +
  per-lane/overall totals, flat-board parity, invalid `swimlane` 422, row-scope narrowing, and the
  `category` CHECK survival guard.

## Files changed (commit `481b2c4`)

`app/Models/TaskStatus.php`, `app/Services/TaskService.php`, `app/Services/ProjectService.php`,
`app/Services/Tasks/{TaskPresenter,TaskReader}.php`, `app/Http/Controllers/{TaskController,
StatusController,WorkflowController}.php`, `app/Http/Requests/{StatusStoreRequest,
StatusUpdateRequest}.php`, `resources/js/components/tasks/KanbanBoard.jsx`,
`resources/js/pages/ProjectDetail.jsx`, new
`database/migrations/tenant/2026_11_03_000051_add_wip_limit_to_task_statuses.php`, new
`tests/Feature/TaskBoardWipSwimlaneTest.php`.

## AGENTS.md / roadmap deltas applied

- AGENTS.md Tasks section: new P4.10 bullet (WIP semantics, swimlane shape), `board` read moved to
  `TaskReader`, response shapes gained `open_count`/swimlanes, KanbanBoard bullet updated.
- AGENTS.md Commands test-count line: 1711/8762 → 1720/8815 (+9 tests / +53 assertions), P4.10 added
  to the focused-run list.
- master-roadmap.md: P4.10 row annotated shipped (`TaskBoardWipSwimlaneTest` green); Phase 1 progress
  line moved P4.10 to the shipped list and dropped it from Open.
