<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskChecklistItemRequest;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TaskChecklistController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $logger,
    ) {}

    public function index(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $this->verifyTaskBelongsToProject($task, $project);

        $items = $task->checklistItems()->ordered()
            ->get()->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'is_done' => $item->is_done,
                'position' => $item->position,
                'completed_at' => $item->completed_at?->toISOString(),
            ]);

        $doneCount = $items->filter(fn ($i) => $i['is_done'])->count();
        $totalCount = $items->count();

        return response()->json([
            'items' => $items->values(),
            'summary' => [
                'done' => $doneCount,
                'total' => $totalCount,
            ],
        ]);
    }

    public function store(TaskChecklistItemRequest $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('edit', $task);

        $this->verifyTaskBelongsToProject($task, $project);

        $itemCount = $task->checklistItems()->count();

        if ($itemCount >= 100) {
            throw ValidationException::withMessages([
                'form' => 'A task cannot have more than 100 checklist items.',
            ]);
        }

        $lastItem = $task->checklistItems()->max('position');
        $position = $lastItem !== null ? $lastItem + 1 : 0;

        $item = TaskChecklistItem::create([
            'task_id' => $task->id,
            'title' => $request->validated('title'),
            'position' => $position,
        ]);

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.checklist_item_added',
            data: ['item_id' => $item->id, 'title' => $item->title],
        );

        return response()->json([
            'message' => 'Checklist item added.',
            'item' => [
                'id' => $item->id,
                'title' => $item->title,
                'is_done' => (bool) $item->is_done,
                'position' => $item->position,
                'completed_at' => null,
            ],
        ], 201);
    }

    public function update(TaskChecklistItemRequest $request, Project $project, Task $task, TaskChecklistItem $item): JsonResponse
    {
        $this->authorize('edit', $task);

        $this->verifyTaskBelongsToProject($task, $project);

        if ($item->task_id !== $task->id) {
            abort(404);
        }

        $data = $request->validated();
        $wasDone = $item->is_done;
        $isDone = $data['is_done'] ?? $item->is_done;

        $updateData = [];

        if (array_key_exists('is_done', $data)) {
            $updateData['is_done'] = $isDone;
            if ($isDone) {
                $updateData['completed_at'] = now();
                $updateData['completed_by'] = auth()->id();
            } else {
                $updateData['completed_at'] = null;
                $updateData['completed_by'] = null;
            }
        }

        if (array_key_exists('title', $data)) {
            $updateData['title'] = $data['title'];
        }

        if (array_key_exists('position', $data)) {
            $this->reposition($task, $item, (int) $data['position']);
        }

        if (! empty($updateData)) {
            $item->update($updateData);
        }

        $item->refresh();

        if (array_key_exists('is_done', $data) && $wasDone !== $isDone) {
            if ($isDone) {
                $this->logger->log(
                    subjectType: Task::class,
                    subjectId: $task->id,
                    action: 'task.checklist_item_checked',
                    data: ['item_id' => $item->id, 'title' => $item->title],
                );
            } else {
                $this->logger->log(
                    subjectType: Task::class,
                    subjectId: $task->id,
                    action: 'task.checklist_item_unchecked',
                    data: ['item_id' => $item->id, 'title' => $item->title],
                );
            }
        }

        return response()->json([
            'message' => 'Checklist item updated.',
            'item' => [
                'id' => $item->id,
                'title' => $item->title,
                'is_done' => $item->is_done,
                'position' => $item->position,
                'completed_at' => $item->completed_at?->toISOString(),
                'completed_by' => $item->completed_by,
            ],
        ]);
    }

    public function destroy(Request $request, Project $project, Task $task, TaskChecklistItem $item): JsonResponse
    {
        $this->authorize('edit', $task);

        $this->verifyTaskBelongsToProject($task, $project);

        if ($item->task_id !== $task->id) {
            abort(404);
        }

        $itemId = $item->id;
        $title = $item->title;

        $item->delete();

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.checklist_item_removed',
            data: ['item_id' => $itemId, 'title' => $title],
        );

        $this->renormalizePositions($task);

        return response()->json([
            'message' => 'Checklist item removed.',
        ]);
    }

    private function verifyTaskBelongsToProject(Task $task, Project $project): void
    {
        if ($task->project_id !== $project->id) {
            abort(404, 'Task not found in this project.');
        }
    }

    private function reposition(Task $task, TaskChecklistItem $item, int $newPosition): void
    {
        $ids = $task->checklistItems()
            ->whereKeyNot($item->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->values();

        $index = max(0, min($newPosition, $ids->count()));

        $orderedIds = $ids->slice(0, $index)
            ->concat([$item->id])
            ->concat($ids->slice($index))
            ->values();

        $orderedIds->each(function (int $id, int $i) {
            TaskChecklistItem::whereKey($id)->update(['position' => $i]);
        });
    }

    private function renormalizePositions(Task $task): void
    {
        $position = 0;
        $task->checklistItems()->orderBy('position')->orderBy('id')->get()
            ->each(function (TaskChecklistItem $item) use (&$position) {
                $item->update(['position' => $position++]);
            });
    }
}
