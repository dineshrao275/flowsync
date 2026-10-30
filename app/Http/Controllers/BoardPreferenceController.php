<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BoardPreference;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Personal board view settings. Self-scoped: any project viewer may read and
 * write their OWN row; shared column edits stay on StatusController
 * (`manageWorkflow`).
 */
class BoardPreferenceController extends Controller
{
    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['preferences' => $this->resolve($request, $project)]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $data = $request->validate([
            'hidden' => ['sometimes', 'array', 'max:100'],
            'hidden.*' => ['integer'],
            'collapsed' => ['sometimes', 'array', 'max:100'],
            'collapsed.*' => ['integer'],
            'width' => ['sometimes', Rule::in(BoardPreference::WIDTHS)],
            'density' => ['sometimes', Rule::in(BoardPreference::DENSITIES)],
            'hide_empty' => ['sometimes', 'boolean'],
        ]);

        $row = BoardPreference::firstOrNew(['user_id' => $request->user()->id, 'project_id' => $project->id]);
        $row->preferences = array_merge($row->preferences ?? [], $data);
        $row->save();

        return response()->json([
            'message' => __('Board preferences updated.'),
            'preferences' => $this->resolve($request, $project),
        ]);
    }

    /**
     * Stored values over the defaults, with column ids pruned to the project's
     * real statuses (a deleted column must not linger as "hidden").
     *
     * @return array<string, mixed>
     */
    private function resolve(Request $request, Project $project): array
    {
        $row = BoardPreference::where('user_id', $request->user()->id)->where('project_id', $project->id)->first();
        $prefs = array_merge(BoardPreference::defaults(), $row?->preferences ?? []);
        $ids = $project->statuses()->pluck('id')->all();

        foreach (['hidden', 'collapsed'] as $key) {
            $prefs[$key] = array_values(array_intersect(array_map('intval', $prefs[$key]), $ids));
        }

        return $prefs;
    }
}
