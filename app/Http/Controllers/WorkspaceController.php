<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function __construct(private readonly WorkspaceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $workspaces = $this->service->listFor($request->user())
            ->map(fn (Workspace $workspace) => [
                ...$this->present($workspace),
                'my_role' => $workspace->memberRole($request->user())?->value,
            ]);

        return response()->json(['workspaces' => $workspaces]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Workspace::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:64'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $workspace = $this->service->create($data, $request->user());

        return response()->json([
            'message' => 'Workspace created.',
            'workspace' => [...$this->present($workspace), 'my_role' => 'owner'],
        ], 201);
    }

    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        return response()->json([
            'workspace' => [...$this->present($workspace), 'my_role' => $workspace->memberRole($request->user())?->value],
        ]);
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:64'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $workspace = $this->service->update($workspace, $data);

        return response()->json([
            'message' => 'Workspace updated.',
            'workspace' => $this->present($workspace),
        ]);
    }

    public function archive(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('archive', $workspace);

        return response()->json([
            'message' => 'Workspace archived.',
            'workspace' => $this->present($this->service->archive($workspace)),
        ]);
    }

    public function restore(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('restore', $workspace);

        return response()->json([
            'message' => 'Workspace restored.',
            'workspace' => $this->present($this->service->restore($workspace)),
        ]);
    }

    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('delete', $workspace);

        $this->service->delete($workspace);

        return response()->json(['message' => 'Workspace deleted.']);
    }

    private function present(Workspace $workspace): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'description' => $workspace->description,
            'icon' => $workspace->icon,
            'timezone' => $workspace->timezone,
            'archived_at' => $workspace->archived_at?->toISOString(),
            'members_count' => $workspace->members_count ?? $workspace->members()->count(),
            'projects_count' => $workspace->projects_count ?? $workspace->projects()->count(),
            'labels_count' => $workspace->labels_count ?? $workspace->labels()->count(),
        ];
    }
}
