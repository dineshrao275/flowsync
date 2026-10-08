<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectVersion;
use App\Services\ProjectVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectVersionController extends Controller
{
    public function __construct(
        private readonly ProjectVersionService $service,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'versions' => $this->service->list($project),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('edit', $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'release_date' => ['nullable', 'date'],
            'released' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $version = $this->service->create($project, $data);

        return response()->json([
            'message' => 'Version created.',
            'version' => $version,
        ], 201);
    }

    public function update(Request $request, Project $project, ProjectVersion $version): JsonResponse
    {
        $this->authorize('edit', $project);

        abort_unless($version->project_id === $project->id, 404);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'release_date' => ['nullable', 'date'],
            'released' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $updated = $this->service->update($version, $data);

        return response()->json([
            'message' => 'Version updated.',
            'version' => $updated,
        ]);
    }

    public function destroy(Request $request, Project $project, ProjectVersion $version): JsonResponse
    {
        $this->authorize('edit', $project);

        abort_unless($version->project_id === $project->id, 404);

        $this->service->delete($version);

        return response()->json(['message' => 'Version deleted.']);
    }
}
