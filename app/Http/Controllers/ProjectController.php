<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesCustomFields;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    use HandlesCustomFields;

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
            'description' => ['nullable', 'string', 'max:20000'],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:16'],
            'default_assignee_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'board_type' => ['nullable', 'in:'.implode(',', array_keys(config('board_types.types')))],
        ]);

        $custom = $this->validatedCustomFields($request, 'project', partial: false);

        $project = $this->service->create($workspace, $data, $request->user());
        $this->storeCustomFields('project', $project->id, $custom);

        return response()->json([
            'message' => __('Project created.'),
            'project' => $this->withCustomFields([[...$this->present($project, $request->user()), 'my_role' => $request->user()->id === $project->lead_user_id ? 'lead' : null]], 'project', false)[0],
        ], 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'project' => $this->withCustomFields([[...$this->present($project, $request->user()), 'my_role' => $project->memberRole($request->user())?->slug]], 'project', false)[0],
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('edit', $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:16'],
            'default_assignee_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $custom = $this->validatedCustomFields($request, 'project', partial: true);

        $updated = $this->service->update($project, $data);
        $this->storeCustomFields('project', $updated->id, $custom);

        return response()->json([
            'message' => __('Project updated.'),
            'project' => $this->withCustomFields([$this->present($updated, $request->user())], 'project', false)[0],
        ]);
    }

    public function archive(Request $request, Project $project): JsonResponse
    {
        $this->authorize('archive', $project);

        return response()->json([
            'message' => __('Project archived.'),
            'project' => $this->present($this->service->archive($project), $request->user()),
        ]);
    }

    public function restore(Request $request, Project $project): JsonResponse
    {
        $this->authorize('restore', $project);

        return response()->json([
            'message' => __('Project restored.'),
            'project' => $this->present($this->service->restore($project), $request->user()),
        ]);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $this->service->delete($project);

        return response()->json(['message' => __('Project deleted.')]);
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
            'color' => $project->color,
            'default_assignee_id' => $project->default_assignee_id,
            'lead_user_id' => $project->lead_user_id,
            'start_date' => $project->start_date?->toDateString(),
            'due_date' => $project->due_date?->toDateString(),
            'board_type' => $project->board_type ?? 'kanban',
            'archived_at' => $project->archived_at?->toISOString(),
            'members_count' => $project->members_count ?? $project->members()->count(),
            'tasks_count' => $project->tasks_count ?? $project->tasks()->count(),
            'statuses_count' => $project->statuses_count ?? $project->statuses()->count(),
            'my_role' => $user ? $project->memberRole($user)?->slug : null,
        ];
    }
}
