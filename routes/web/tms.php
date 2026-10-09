<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AutomationRuleController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DependencyController;
use App\Http\Controllers\IssueTypeController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\ProjectComponentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectHierarchyController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\ProjectRoleController;
use App\Http\Controllers\ProjectVersionController;
use App\Http\Controllers\SprintController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\TaskChecklistController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskMoveController;
use App\Http\Controllers\TaskWatcherController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\WorkLogController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceMemberController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Task-management domain: workspaces, projects, tasks, sprints, automation, workflow, collaboration.
// Required from routes/web.php inside the same middleware group the block lived in,
// at the same position, so route order (and `route:list`) is unchanged.

// Phase 1+: workspace management. Object-level authorization is
// enforced by WorkspacePolicy (membership roles owner/admin/member);
// 'tenant_context' rejects non-impersonating super admins.
Route::middleware(['permission:workspaces.view', 'ensure_product:tms', 'ensure_module:automation'])->group(function () {
    Route::get('automation/catalog', [AutomationRuleController::class, 'catalog']);
    Route::get('projects/{project}/automations', [AutomationRuleController::class, 'index']);
    Route::post('projects/{project}/automations', [AutomationRuleController::class, 'store'])->middleware('throttle:30,1');
    Route::put('projects/{project}/automations/{rule}', [AutomationRuleController::class, 'update']);
    Route::delete('projects/{project}/automations/{rule}', [AutomationRuleController::class, 'destroy']);
    Route::get('projects/{project}/automations/{rule}/runs', [AutomationRuleController::class, 'runs']);
});

Route::middleware(['permission:workspaces.view', 'ensure_product:tms', 'ensure_module:sprints'])->group(function () {
    Route::get('projects/{project}/sprints', [SprintController::class, 'index']);
    Route::post('projects/{project}/sprints', [SprintController::class, 'store']);
    Route::get('projects/{project}/sprints/{sprint}', [SprintController::class, 'show']);
    Route::put('projects/{project}/sprints/{sprint}', [SprintController::class, 'update']);
    Route::delete('projects/{project}/sprints/{sprint}', [SprintController::class, 'destroy']);
    Route::post('projects/{project}/sprints/{sprint}/start', [SprintController::class, 'start']);
    Route::post('projects/{project}/sprints/{sprint}/complete', [SprintController::class, 'complete']);
    Route::post('projects/{project}/sprints/{sprint}/tasks', [SprintController::class, 'addTasks']);
    Route::delete('projects/{project}/sprints/{sprint}/tasks/{task}', [SprintController::class, 'removeTask']);
    Route::get('projects/{project}/agile-reports', [SprintController::class, 'reports']);
});

Route::middleware(['permission:workspaces.view', 'ensure_product:tms'])->group(function () {
    Route::get('projects/{project}/workflow', [WorkflowController::class, 'show']);
    Route::put('projects/{project}/workflow', [WorkflowController::class, 'update']);
    Route::get('projects/{project}/tasks/{task}/transitions', [WorkflowController::class, 'transitions']);
    Route::get('workspaces', [WorkspaceController::class, 'index']);
    Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show']);
    Route::put('workspaces/{workspace}', [WorkspaceController::class, 'update']);
    Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy']);
    Route::post('workspaces/{workspace}/archive', [WorkspaceController::class, 'archive']);
    Route::post('workspaces/{workspace}/restore', [WorkspaceController::class, 'restore']);

    Route::get('workspaces/{workspace}/members', [WorkspaceMemberController::class, 'index']);
    Route::post('workspaces/{workspace}/members', [WorkspaceMemberController::class, 'store']);
    Route::put('workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'update']);
    Route::delete('workspaces/{workspace}/members/{user}', [WorkspaceMemberController::class, 'destroy']);

    Route::get('workspaces/{workspace}/labels', [LabelController::class, 'index']);
    Route::post('workspaces/{workspace}/labels', [LabelController::class, 'store']);
    Route::put('labels/{label}', [LabelController::class, 'update']);
    Route::delete('labels/{label}', [LabelController::class, 'destroy']);

    // Phase 2: projects. Index/store are scoped to a workspace
    // (object auth via WorkspacePolicy); the rest are project-scoped
    // and gated by ProjectPolicy (project-role permissions).
    Route::get('workspaces/{workspace}/projects', [ProjectController::class, 'index']);
    Route::post('workspaces/{workspace}/projects', [ProjectController::class, 'store']);

    Route::get('projects', [ProjectController::class, 'indexAll']);

    Route::get('projects/{project}', [ProjectController::class, 'show']);
    Route::put('projects/{project}', [ProjectController::class, 'update']);
    Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
    Route::post('projects/{project}/archive', [ProjectController::class, 'archive']);
    Route::post('projects/{project}/restore', [ProjectController::class, 'restore']);

    Route::get('projects/{project}/members', [ProjectMemberController::class, 'index']);
    Route::get('projects/{project}/members/autocomplete', [ProjectMemberController::class, 'autocomplete']);
    Route::post('projects/{project}/members', [ProjectMemberController::class, 'store']);
    Route::put('projects/{project}/members/{user}', [ProjectMemberController::class, 'update']);
    Route::delete('projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy']);

    Route::get('projects/{project}/statuses', [StatusController::class, 'index']);
    Route::post('projects/{project}/statuses', [StatusController::class, 'store']);
    Route::put('projects/{project}/statuses/{status}', [StatusController::class, 'update']);
    Route::delete('projects/{project}/statuses/{status}', [StatusController::class, 'destroy']);

    // TMS expansion: project components & releases/versions
    Route::get('projects/{project}/components', [ProjectComponentController::class, 'index']);
    Route::post('projects/{project}/components', [ProjectComponentController::class, 'store']);
    Route::put('projects/{project}/components/{component}', [ProjectComponentController::class, 'update']);
    Route::delete('projects/{project}/components/{component}', [ProjectComponentController::class, 'destroy']);

    Route::get('projects/{project}/versions', [ProjectVersionController::class, 'index']);
    Route::post('projects/{project}/versions', [ProjectVersionController::class, 'store']);
    Route::put('projects/{project}/versions/{version}', [ProjectVersionController::class, 'update']);
    Route::delete('projects/{project}/versions/{version}', [ProjectVersionController::class, 'destroy']);

    // Phase 3: tasks. Board/list at the project index (view=board|list,
    // filters as query params); mutations gated by TaskPolicy (project-role
    // tasks.* permissions) + ProjectPolicy::createTask.
    Route::get('projects/{project}/hierarchy', [ProjectHierarchyController::class, 'show']);
    Route::get('projects/{project}/tasks', [TaskController::class, 'index']);
    Route::post('projects/{project}/tasks', [TaskController::class, 'store']);
    Route::get('projects/{project}/tasks/key/{key}', [TaskController::class, 'showByKey'])->where('key', '[A-Za-z0-9-]+');
    Route::get('projects/{project}/tasks/{task}', [TaskController::class, 'show']);
    Route::put('projects/{project}/tasks/{task}', [TaskController::class, 'update']);
    Route::delete('projects/{project}/tasks/{task}', [TaskController::class, 'destroy']);
    Route::post('projects/{project}/tasks/{task}/move', [TaskMoveController::class, 'move']);

    // Phase 4: collaboration. Comments (thread + replies, ownership/role
    // policy, CommentSynced broadcast), dependencies (cycle-checked), and
    // attachments (upload/list/delete). All gated by per-task policies.
    Route::get('projects/{project}/tasks/{task}/comments', [CommentController::class, 'index']);
    Route::post('projects/{project}/tasks/{task}/comments', [CommentController::class, 'store']);
    Route::put('projects/{project}/tasks/{task}/comments/{comment}', [CommentController::class, 'update']);
    Route::delete('projects/{project}/tasks/{task}/comments/{comment}', [CommentController::class, 'destroy']);

    Route::get('projects/{project}/tasks/{task}/dependencies', [DependencyController::class, 'index']);
    Route::post('projects/{project}/tasks/{task}/dependencies', [DependencyController::class, 'store']);
    Route::delete('projects/{project}/tasks/{task}/dependencies/{dependency}', [DependencyController::class, 'destroy']);

    Route::get('projects/{project}/tasks/{task}/checklist', [TaskChecklistController::class, 'index']);
    Route::post('projects/{project}/tasks/{task}/checklist', [TaskChecklistController::class, 'store']);
    Route::put('projects/{project}/tasks/{task}/checklist/{item}', [TaskChecklistController::class, 'update']);
    Route::delete('projects/{project}/tasks/{task}/checklist/{item}', [TaskChecklistController::class, 'destroy']);

    Route::get('projects/{project}/tasks/{task}/attachments', [AttachmentController::class, 'index']);
    Route::post('projects/{project}/tasks/{task}/attachments', [AttachmentController::class, 'store']);
    Route::delete('projects/{project}/tasks/{task}/attachments/{attachment}', [AttachmentController::class, 'destroy']);

    // Task watchers
    Route::get('projects/{project}/tasks/{task}/watchers', [TaskWatcherController::class, 'index']);
    Route::post('projects/{project}/tasks/{task}/watchers', [TaskWatcherController::class, 'store']);
    Route::delete('projects/{project}/tasks/{task}/watchers/{user?}', [TaskWatcherController::class, 'destroy']);

    // Activity timeline (task + project feeds).
    Route::get('projects/{project}/tasks/{task}/activities', [ActivityController::class, 'task']);
    Route::get('projects/{project}/activities', [ActivityController::class, 'project']);

    // Phase 6: time tracking. Work logs per task (work_logs.* project-role
    // perms + own-log rules), plus project/workspace time summaries. The
    // whole block is gated on the `time_tracking` subscription module.
    Route::group(['middleware' => ['ensure_module:time_tracking']], function () {
        Route::get('projects/{project}/tasks/{task}/work-logs', [WorkLogController::class, 'index']);
        Route::post('projects/{project}/tasks/{task}/work-logs', [WorkLogController::class, 'store']);
        Route::put('projects/{project}/tasks/{task}/work-logs/{workLog}', [WorkLogController::class, 'update']);
        Route::delete('projects/{project}/tasks/{task}/work-logs/{workLog}', [WorkLogController::class, 'destroy']);

        Route::get('projects/{project}/time-summary', [WorkLogController::class, 'projectTime']);
        Route::get('workspaces/{workspace}/time-summary', [WorkLogController::class, 'workspaceTime']);
    });

    // Project-role catalog (tenant-level). Reading is open within the
    // domain so project member managers can populate a role picker.
    Route::get('project-roles', [ProjectRoleController::class, 'index']);

    // Issue types catalog (tenant-level).
    Route::get('issue-types', [IssueTypeController::class, 'index']);
});
