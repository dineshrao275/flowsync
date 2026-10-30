<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\SlaPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Per-project SLA policies; managed by whoever manages project settings, readable by members. */
class SlaPolicyController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['policies' => SlaPolicy::query()->where('project_id', $project->id)->orderBy('id')->get()]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('settings', $project);

        $policy = SlaPolicy::create($this->validated($request) + ['project_id' => $project->id]);

        return response()->json(['message' => __('SLA saved.'), 'policy' => $policy], 201);
    }

    public function update(Request $request, Project $project, SlaPolicy $policy): JsonResponse
    {
        $this->authorize('settings', $project);
        abort_unless($policy->project_id === $project->id, 404);

        $policy->update($this->validated($request));

        return response()->json(['message' => __('SLA saved.'), 'policy' => $policy->refresh()]);
    }

    public function destroy(Project $project, SlaPolicy $policy): JsonResponse
    {
        $this->authorize('settings', $project);
        abort_unless($policy->project_id === $project->id, 404);
        $policy->delete();

        return response()->json(['message' => __('SLA removed.')]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'priority_id' => ['nullable', 'integer', 'exists:priorities,id'],
            'target_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
