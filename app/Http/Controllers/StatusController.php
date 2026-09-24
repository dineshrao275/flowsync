<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatusCategory;
use App\Models\Project;
use App\Models\TaskStatus;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StatusController extends Controller
{
    public function __construct(private readonly ProjectService $service) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $statuses = $project->statuses()->orderBy('position')->get()
            ->map(fn (TaskStatus $status) => $this->present($status));

        return response()->json(['statuses' => $statuses]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manageWorkflow', $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(TaskStatusCategory::class)],
            'color' => ['nullable', 'string', 'max:16'],
            'position' => ['nullable', 'integer', 'min:1'],
        ]);

        $status = $this->service->addStatus($project, $data);

        return response()->json([
            'message' => 'Status created.',
            'status' => $this->present($status->fresh()),
        ], 201);
    }

    public function update(Request $request, Project $project, TaskStatus $status): JsonResponse
    {
        $this->authorize('manageWorkflow', $project);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', Rule::enum(TaskStatusCategory::class)],
            'color' => ['nullable', 'string', 'max:16'],
            'position' => ['nullable', 'integer', 'min:1'],
            'is_done' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'message' => 'Status updated.',
            'status' => $this->present($this->service->updateStatus($status, $data)),
        ]);
    }

    public function destroy(Request $request, Project $project, TaskStatus $status): JsonResponse
    {
        $this->authorize('manageWorkflow', $project);

        $this->service->deleteStatus($status);

        return response()->json(['message' => 'Status deleted.']);
    }

    private function present(TaskStatus $status): array
    {
        return [
            'id' => $status->id,
            'project_id' => $status->project_id,
            'name' => $status->name,
            'slug' => $status->slug,
            'category' => $status->category->value,
            'position' => $status->position,
            'color' => $status->color,
            'is_default' => $status->is_default,
            'is_done' => $status->is_done,
        ];
    }
}
