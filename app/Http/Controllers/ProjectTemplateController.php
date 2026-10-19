<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Workspace;
use App\Services\Projects\ProjectTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectTemplateController extends Controller
{
    public function __construct(private readonly ProjectTemplateService $service) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'templates' => ProjectTemplate::query()->with('creator:id,name')->orderBy('name')->get()->map(fn (ProjectTemplate $t) => $this->present($t))->values(),
        ]);
    }

    /** Save a project's setup as a template — a project manager's call. */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('settings', $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'include_tasks' => ['sometimes', 'boolean'],
        ]);

        $template = $this->service->fromProject($project, $data['name'], $data['description'] ?? null, (bool) ($data['include_tasks'] ?? false), $request->user());

        return response()->json(['message' => __('Template saved.'), 'template' => $this->present($template->load('creator:id,name'))], 201);
    }

    public function fromTemplate(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('createProject', $workspace);

        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'key' => ['nullable', 'string', 'max:16', 'alpha_num'],
            'description' => ['nullable', 'string', 'max:20000'],
        ]);

        $template = ProjectTemplate::find($data['template_id']);
        abort_if($template === null, 422, __('Choose an existing template.'));

        $project = $this->service->instantiate($workspace, $template, $data, $request->user());

        return response()->json(['message' => __('Project created.'), 'project' => ['id' => $project->id, 'key' => $project->key, 'name' => $project->name]], 201);
    }

    public function destroy(Request $request, ProjectTemplate $template): JsonResponse
    {
        abort_unless(
            $template->created_by === $request->user()->id || $request->user()->hasPermission('workspaces.manage'),
            403,
            __('Only its creator or a tenant admin can delete a template.')
        );

        $template->delete();

        return response()->json(['message' => __('Template deleted.')]);
    }

    /** @return array<string, mixed> */
    private function present(ProjectTemplate $t): array
    {
        $s = $t->snapshot;

        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'statuses' => array_column($s['statuses'] ?? [], 'name'),
            'components' => count($s['components'] ?? []),
            'tasks' => count($s['tasks'] ?? []),
            'creator' => $t->creator?->name,
        ];
    }
}
