# Track notes: TMS issue hierarchy (branch `track/tms-p4`)

Task: **P4.1** (issue hierarchy — levels, epic link, tree view). Merged to
`Improve/improvements-new-work` as `7a6f9bd` ("Merge track/tms-p4 (P4.1 issue hierarchy, epic links,
tree view)"); the track's one commit `eb2a4d5` is in main via the merge. Tests were written but not
executed at merge time; the sandbox run of `tests/Feature/IssueHierarchyTest.php` passes (8 tests).

## What shipped

- `config/issue_types.php`: `hierarchy_level` on every type (0 initiative / 1 epic / 2 standard /
  3 sub-task), seeded with a new default `initiative` type.
- Migration `database/migrations/tenant/2026_11_01_000050_add_issue_hierarchy.php`:
  - `issue_types.hierarchy_level` default 2, backfilled from `is_subtask` (true → 3, `epic` → 1);
  - `IssueType` (slug `initiative`) inserted if missing;
  - `tasks.epic_id` via **raw `ALTER TABLE ... ADD COLUMN ... REFERENCES`** (not `constrained()`),
    `on delete set null`, plus a separate index — the P5.1 SQLite-CHECK-preservation lesson applied
    to a self-FK. Migration is repair-safe (`hasColumn` guards).
- `app/Services/Tasks/TaskHierarchy.php`: `levelOf()`, `epic()` (same-project + one-step-up check,
  422 `epic_id` otherwise), `treeFor(Project)` over `topLevel()` with `HierarchyTree` breadth-first
  nesting per level, `epicOptions()` (open epics).
- `app/Support/TaskScope.php`: `constrainQuery` now also constrains `epic_id` to the visible-task
  rule per project, so `_own`/`_assigned` views never leak a sibling epic's stories.
- `app/Models/Task.php` (+`epic_id`, `epic()` relation, `isEpic` helper semantics), `IssueType`
  (+`level()` / `hierarchy_level` cast), `TenantProvisioner` (still idempotent; seeds the new
  config issue type).
- `app/Http/Controllers/ProjectHierarchyController.php`: `GET projects/{project}/hierarchy` in the
  `permission:workspaces.view` domain group, project-member policy.
- `TaskService::show` includes `epic {id,key,title,status_id}` and `canSetEpic`; create/update
  accept a validated `epic_id` (same project, not self).
- SPA: `components/hierarchy/HierarchyPanel.jsx` (grouped Initiative→Epic→Story tree) in the project
  **Overview** tab; `CreateTaskModal`/`TaskDetail` gained an epic picker.
- Test: `tests/Feature/IssueHierarchyTest.php` + `tests/Feature/Concerns/BuildsTmsFixtures.php`
  (shared TMS fixture builder used by the issue-hierarchy suite).

## Files changed (merge diff)

`app/{Controllers/IssueTypeController,Controllers/ProjectHierarchyController,Controllers/TaskController,
Models/IssueType,Models/Task,Services/IssueTypeService,Services/TaskService,Support/TaskScope,
Support/TenantProvisioner}.php`, new `app/Services/Tasks/{HierarchyTree,TaskHierarchy}.php` and
`app/Services/Tasks/{TaskPresenter,TaskReader}.php` (small additions), `config/issue_types.php`,
`routes/web/tms.php` (`GET projects/{project}/hierarchy`), the `2026_11_01_000050` migration,
`resources/js/{components/hierarchy/HierarchyPanel.jsx, components/tasks/CreateTaskModal.jsx,
components/tasks/TaskDetail.jsx, pages/ProjectDetail.jsx}`, `tests/Feature/IssueHierarchyTest.php`,
`tests/Feature/Concerns/BuildsTmsFixtures.php`.

## AGENTS.md / roadmap deltas applied by the integrator

- New AGENTS.md section `## Issue hierarchy (P4.1)` (after Automation).
- `master-roadmap.md`: progress line 484 adds `P4.1 ✅ (track/tms-p4 merged)`; P4.1 row marked
  `**shipped; IssueHierarchyTest green**`; §8 gap list trims hierarchy from the "JIRA-class layer" gap.