<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectComponent;
use App\Services\ProjectComponentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectComponentController extends Controller
{
    public function __construct(
        private readonly ProjectComponentService $service,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'components' => $this->service->list($project),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('edit', $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'lead_user_id' => ['nullable', 'integer'],
        ]);

        $component = $this->service->create($project, $data);

        return response()->json([
            'message' => 'Component created.',
            'component' => $component,
        ], 201);
    }

    public function update(Request $request, Project $project, ProjectComponent $component): JsonResponse
    {
        $this->authorize('edit', $project);

        abort_unless($component->project_id === $project->id, 404);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'lead_user_id' => ['nullable', 'integer'],
        ]);

        $updated = $this->service->update($component, $data);

        return response()->json([
            'message' => 'Component updated.',
            'component' => $updated,
        ]);
    }

    public function destroy(Request $request, Project $project, ProjectComponent $component): JsonResponse
    {
        $this->authorize('edit', $project);

        abort_unless($component->project_id === $project->id, 404);

        $this->service->delete($component);

        return response()->json(['message' => 'Component deleted.']);
    }
}
