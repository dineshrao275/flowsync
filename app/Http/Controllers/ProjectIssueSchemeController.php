<?php

namespace App\Http\Controllers;

use App\Models\IssueType;
use App\Models\Project;
use App\Services\Projects\ProjectIssueScheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Which issue types a project allows (empty = all). Members read it; project settings managers change it. */
class ProjectIssueSchemeController extends Controller
{
    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        $ids = ProjectIssueScheme::ids($project);

        return response()->json([
            'board_type' => $project->board_type,
            'issue_type_ids' => $ids,
            'issue_types' => IssueType::query()->orderBy('position')->get(['id', 'name', 'slug', 'color', 'hierarchy_level'])
                ->map(fn ($t) => $t->toArray() + ['allowed' => $ids === [] || in_array($t->id, $ids, true)])->values(),
            'board_types' => collect(config('board_types.types'))->map(fn ($t, $k) => ['key' => $k, 'label' => $t['label'], 'description' => $t['description']])->values(),
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('settings', $project);
        $data = $request->validate(['issue_type_ids' => ['present', 'array'], 'issue_type_ids.*' => ['integer', 'exists:issue_types,id']]);

        ProjectIssueScheme::replace($project, $data['issue_type_ids']);

        return response()->json(['message' => __('Issue types saved.'), 'issue_type_ids' => ProjectIssueScheme::ids($project)]);
    }
}
