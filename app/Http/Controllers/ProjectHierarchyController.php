<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Tasks\HierarchyTree;
use App\Support\TaskScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Initiative -> epic -> issue -> sub-task tree of a project (P4.1), scoped to the caller's visible rows. */
class ProjectHierarchyController extends Controller
{
    public function __construct(private readonly HierarchyTree $tree) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        TaskScope::assertCanRead($project, $request->user());

        return response()->json($this->tree->build($project, $request->user()));
    }
}
