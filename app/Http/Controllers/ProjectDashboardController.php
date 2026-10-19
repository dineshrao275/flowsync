<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Dashboards\ProjectDashboardService;
use App\Support\TaskScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectDashboardController extends Controller
{
    public function __construct(private readonly ProjectDashboardService $service) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        TaskScope::assertCanRead($project, $request->user());

        $widgets = $this->service->layoutFor($project, $request->user());

        return response()->json([
            'widgets' => $widgets,
            'available' => ProjectDashboardService::WIDGETS,
            'data' => $this->service->data($project, $request->user(), $widgets),
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $data = $request->validate([
            'widgets' => ['required', 'array', 'min:1', 'max:12'],
            'widgets.*' => ['string', Rule::in(ProjectDashboardService::WIDGETS)],
            'shared' => ['sometimes', 'boolean'],
        ]);

        $shared = (bool) ($data['shared'] ?? false);
        if ($shared) {
            $this->authorize('settings', $project); // the project default is a manager's call
        }

        $this->service->save($project, $request->user(), $data['widgets'], $shared);

        return response()->json(['message' => __('Dashboard saved.')]);
    }

    public function reset(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $this->service->reset($project, $request->user());

        return response()->json(['message' => __('Dashboard reset.')]);
    }
}
