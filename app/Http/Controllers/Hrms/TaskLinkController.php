<?php

namespace App\Http\Controllers\Hrms;

use App\Enums\Hrms\TaskLinkKind;
use App\Http\Controllers\Concerns\ScopesVisibleTasks;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\TaskLinkStoreRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\TaskLink\TaskLink;
use App\Models\Task;
use App\Services\Hrms\TaskLinkPresenter;
use App\Services\Hrms\TaskLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * TaskLink/HRMS — the bridge rows over HTTP.
 *
 * Thin: the task side answers to the project policy (view to read links,
 * edit to file or cut them — linking is task metadata, so the people who
 * may edit the task may annotate it), the employee side answers to the
 * employee policy, and visibility of the employee's tasks comes from the
 * canonical `visibleTaskQuery`, never a second scoping rule. Nested link
 * routes verify belonging: a foreign link id 404s rather than cutting
 * someone else's bridge.
 */
class TaskLinkController extends Controller
{
    use ScopesVisibleTasks;

    public function __construct(
        private readonly TaskLinkService $links,
        private readonly TaskLinkPresenter $presenter,
    ) {}

    public function index(Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $rows = $this->links->forTask($task);

        return response()->json([
            'task_links' => $rows->map(fn (TaskLink $link): array => $this->presenter->present($link))->all(),
        ]);
    }

    public function store(TaskLinkStoreRequest $request, Task $task): JsonResponse
    {
        $this->authorize('edit', $task);

        $data = $request->validated();
        $employee = Employee::findOrFail((int) $data['employee_id']);
        $this->authorize('view', $employee);

        $link = $this->links->link(
            $employee,
            $task,
            $request->enum('kind', TaskLinkKind::class),
            $data['note'] ?? null,
            $request->user(),
            $request->ip(),
        );

        return response()->json([
            'message' => $link->wasRecentlyCreated ? 'Task linked.' : 'Task already linked.',
            'task_link' => $this->presenter->present($link),
        ], $link->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function destroy(Request $request, Task $task, TaskLink $link): JsonResponse
    {
        abort_if((int) $link->task_id !== (int) $task->id, 404);

        $this->authorize('edit', $task);

        $this->links->unlink($link, $request->user(), $request->ip());

        return response()->json(['message' => 'Task link removed.']);
    }

    public function employeeTasks(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $filters = $request->validate([
            'kind' => ['sometimes', 'nullable', Rule::enum(TaskLinkKind::class)],
            'status_id' => ['sometimes', 'nullable', 'integer', 'exists:task_statuses,id'],
        ]);

        $tasks = $this->links->forEmployee($employee, $this->visibleTaskQuery($request->user()), $filters);

        return response()->json([
            'tasks' => $tasks->map(fn (Task $task): array => array_merge(
                $this->presentTask($task),
                ['link' => $this->presenter->presentLinkMeta($task)],
            ))->all(),
        ]);
    }
}
