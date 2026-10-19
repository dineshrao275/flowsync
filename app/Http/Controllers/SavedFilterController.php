<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\SavedFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saved task filters (F3.1). Everyone who can see the project can save their own filters and use
 * the ones teammates shared; changing or deleting a filter is limited to its owner and to project
 * managers (`ProjectPolicy::edit`) for shared ones.
 */
class SavedFilterController extends Controller
{
    /** Filter keys the task list endpoint understands — anything else is dropped on save. */
    private const KEYS = [
        'status_id', 'priority_id', 'assignee_id', 'label_id', 'issue_type_id', 'version_id', 'epic_id',
        'component_id', 'sprint', 'q', 'due_from', 'due_to', 'cf',
    ];

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $filters = SavedFilter::query()
            ->with('owner:id,name')
            ->where('project_id', $project->id)
            ->where(fn ($q) => $q->where('owner_user_id', $request->user()->id)->orWhere('is_shared', true))
            ->orderBy('name')
            ->get();

        return response()->json(['filters' => $filters->map(fn (SavedFilter $f) => $this->present($f, $request, $project))->values()]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $data = $this->validated($request);
        abort_if(SavedFilter::where('project_id', $project->id)->where('owner_user_id', $request->user()->id)->count() >= 50, 422, __('You can save up to 50 filters per project.'));

        // Sharing with the whole project is a manager's call; anyone may keep a private filter.
        if (! empty($data['is_shared'])) {
            $this->authorize('edit', $project);
        }

        $filter = SavedFilter::create($data + ['is_shared' => false, 'project_id' => $project->id, 'owner_user_id' => $request->user()->id]);

        return response()->json(['message' => __('Filter saved.'), 'filter' => $this->present($filter->load('owner:id,name'), $request, $project)], 201);
    }

    public function update(Request $request, Project $project, SavedFilter $filter): JsonResponse
    {
        $this->assertBelongs($project, $filter);
        $this->assertCanManage($request, $project, $filter);

        $data = $this->validated($request, partial: true);

        if (($data['is_shared'] ?? $filter->is_shared) && ! $filter->is_shared) {
            $this->authorize('edit', $project);
        }

        $filter->update($data);

        return response()->json(['message' => __('Filter updated.'), 'filter' => $this->present($filter->refresh()->load('owner:id,name'), $request, $project)]);
    }

    public function destroy(Request $request, Project $project, SavedFilter $filter): JsonResponse
    {
        $this->assertBelongs($project, $filter);
        $this->assertCanManage($request, $project, $filter);

        $filter->delete();

        return response()->json(['message' => __('Filter deleted.')]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $data = $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:80'],
            'filters' => [$partial ? 'sometimes' : 'required', 'array'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['filters'])) {
            $data['filters'] = array_filter(
                array_intersect_key($data['filters'], array_flip(self::KEYS)),
                fn ($v) => $v !== null && $v !== '' && $v !== [],
            );
        }

        return $data;
    }

    private function assertBelongs(Project $project, SavedFilter $filter): void
    {
        abort_unless((int) $filter->project_id === (int) $project->id, 404);
    }

    private function assertCanManage(Request $request, Project $project, SavedFilter $filter): void
    {
        $this->authorize('view', $project);

        if ((int) $filter->owner_user_id === (int) $request->user()->id) {
            return;
        }

        // Somebody else's shared filter: project managers only. Somebody else's private one is invisible.
        abort_unless($filter->is_shared, 404);
        $this->authorize('edit', $project);
    }

    /** @return array<string, mixed> */
    private function present(SavedFilter $f, Request $request, Project $project): array
    {
        return [
            'id' => $f->id,
            'name' => $f->name,
            'filters' => (object) ($f->filters ?? []),
            'is_shared' => $f->is_shared,
            'owner' => $f->owner ? ['id' => $f->owner->id, 'name' => $f->owner->name] : null,
            'mine' => (int) $f->owner_user_id === (int) $request->user()->id,
        ];
    }
}
