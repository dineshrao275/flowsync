<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $service) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        $projects = $this->service->listFor($workspace, $request->user())
            ->map(fn (Project $project) => [
                ...$this->present($project, $request->user()),
                'my_role' => $project->memberRole($request->user())?->slug,
            ]);

        return response()->json(['projects' => $projects]);
    }

    public function indexAll(Request $request): JsonResponse
    {
        $projects = $this->service->listAll($request->user())
            ->map(fn (Project $project) => [
                ...$this->present($project, $request->user()),
                'my_role' => $project->memberRole($request->user())?->slug,
            ]);

        return response()->json(['projects' => $projects]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('createProject', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'key' => ['nullable', 'string', 'max:16', 'alpha_num'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:64'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $project = $this->service->create($workspace, $data, $request->user());

        return response()->json([
            'message' => 'Project created.',
            'project' => [...$this->present($project, $request->user()), 'my_role' => $request->user()->id === $project->lead_user_id ? 'lead' : null],
        ], 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'project' => [...$this->present($project, $request->user()), 'my_role' => $project->memberRole($request->user())?->slug],
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('edit', $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:64'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        return response()->json([
            'message' => 'Project updated.',
            'project' => $this->present($this->service->update($project, $data), $request->user()),
        ]);
    }

    public function archive(Request $request, Project $project): JsonResponse
    {
        $this->authorize('archive', $project);

        return response()->json([
            'message' => 'Project archived.',
            'project' => $this->present($this->service->archive($project), $request->user()),
        ]);
    }

    public function restore(Request $request, Project $project): JsonResponse
    {
        $this->authorize('restore', $project);

        return response()->json([
            'message' => 'Project restored.',
            'project' => $this->present($this->service->restore($project), $request->user()),
        ]);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $this->service->delete($project);

        return response()->json(['message' => 'Project deleted.']);
    }

    private function present(Project $project, ?User $user = null): array
    {
        return [
            'id' => $project->id,
            'workspace_id' => $project->workspace_id,
            'workspace' => $project->workspace?->only(['id', 'name', 'slug']),
            'name' => $project->name,
            'key' => $project->key,
            'description' => $project->description,
            'icon' => $project->icon,
            'lead_user_id' => $project->lead_user_id,
            'start_date' => $project->start_date?->toDateString(),
            'due_date' => $project->due_date?->toDateString(),
            'archived_at' => $project->archived_at?->toISOString(),
            'members_count' => $project->members_count ?? $project->members()->count(),
            'tasks_count' => $project->tasks_count ?? $project->tasks()->count(),
            'statuses_count' => $project->statuses_count ?? $project->statuses()->count(),
            'my_role' => $user ? $project->memberRole($user)?->slug : null,
        ];
    }
}
