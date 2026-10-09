<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskChecklistItemRequest;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TaskChecklistController
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
                'is_done' => $item->is_done,
                'position' => $item->position,
                'completed_at' => null,
            ],
        ], 201);
    }

    public function update(TaskChecklistItemRequest $request, Project $project, Task $task, TaskChecklistItem $item): JsonResponse
    {
        if ($item->task_id !== $task->id) {
            abort(404);
        }

        $this->authorize('edit', $task);

        $data = $request->validated();

        $wasDone = $item->is_done;
        $isDone = array_get($data, 'is_done', $item->is_done);

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
            $updateData['position'] = $data['position'];
            $this->renormalizePositions($task);
        }

        $item->update($updateData);

        $now = now();

        if ($isDone) {
            $this->logger->log(
                subjectType: Task::class,
                subjectId: $task->id,
                action: 'task.checklist_item_checked',
                data: ['item_id' => $item->id, 'title' => $item->title],
            );
        } elseif ($wasDone && ! $isDone) {
            $this->logger->log(
                subjectType: Task::class,
                subjectId: $task->id,
                action: 'task.checklist_item_unchecked',
                data: ['item_id' => $item->id, 'title' => $item->title],
            );
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
        if ($item->task_id !== $task->id) {
            abort(404);
        }

        $title = $item->title;

        $this->authorize('edit', $task);

        $this->verifyTaskBelongsToProject($task, $project);

        $item->delete();

        $this->logger->log(
            subjectType: Task::class,
            subjectId: $task->id,
            action: 'task.checklist_item_removed',
            data: ['item_id' => null, 'title' => $title],
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

    private function renormalizePositions(Task $task): void
    {
        $ids = $task->checklistItems()
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->values();

        $this->writeChecklistPositions($ids);
    }

    private function writeChecklistPositions(Collection $ids): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        $cases = [];
        $bindings = [];

        foreach ($ids as $offset => $id) {
            $cases[] = 'when ? then ?';
            $bindings[] = $id;
            $bindings[] = $offset + 1;
        }

        $placeholders = implode(',', array_fill(0, $ids->count(), '?'));
        $allBindings = array_merge($bindings, $ids->all());

        DB::update(
            "update task_checklist_items set position = case id ".implode(' ', $cases).' end where id in ('.$placeholders.')',
            $allBindings,
        );
    }
}