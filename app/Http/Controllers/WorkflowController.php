<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\StatusTransition;
use App\Models\Task;
use App\Services\Workflow\WorkflowGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The project's status workflow: optional allow-list of transitions + entry rules per status. */
class WorkflowController extends Controller
{
    public function __construct(private readonly WorkflowGuard $guard) {}

    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($this->payload($project));
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manageWorkflow', $project);

        $statusIds = $project->statuses()->pluck('id')->map(fn ($i) => (int) $i)->all();
        $data = $request->validate([
            'enforce_workflow' => ['required', 'boolean'],
            'transitions' => ['present', 'array', 'max:400'],
            'transitions.*.from_status_id' => ['nullable', 'integer', Rule::in($statusIds)],
            'transitions.*.to_status_id' => ['required', 'integer', Rule::in($statusIds)],
            'entry_rules' => ['sometimes', 'array'],
            'entry_rules.*' => ['array'],
            'entry_rules.*.*' => ['string', Rule::in(array_keys(WorkflowGuard::ENTRY_RULES))],
        ]);

        // Turning enforcement on with an empty allow-list would freeze every card.
        if ($data['enforce_workflow'] && $data['transitions'] === []) {
            throw ValidationException::withMessages(['transitions' => 'Allow at least one transition before enforcing the workflow.']);
        }

        DB::transaction(function () use ($project, $data, $statusIds): void {
            $project->update(['enforce_workflow' => $data['enforce_workflow']]);

            StatusTransition::where('project_id', $project->id)->delete();
            foreach (collect($data['transitions'])->unique(fn ($t) => ($t['from_status_id'] ?? 'any').'>'.$t['to_status_id']) as $t) {
                StatusTransition::create(['project_id' => $project->id, 'from_status_id' => $t['from_status_id'] ?? null, 'to_status_id' => $t['to_status_id']]);
            }

            foreach ($data['entry_rules'] ?? [] as $statusId => $rules) {
                if (in_array((int) $statusId, $statusIds, true)) {
                    $project->statuses()->whereKey((int) $statusId)->update(['entry_rules' => $rules === [] ? null : json_encode(array_values(array_unique($rules)))]);
                }
            }
        });

        return response()->json(['message' => 'Workflow saved.', ...$this->payload($project->refresh())]);
    }

    /** Where this task may go from here, and why a status is closed to it. */
    public function transitions(Project $project, Task $task): JsonResponse
    {
        $this->authorize('view', $task);
        $allowed = $this->guard->allowedTargets($task);

        return response()->json(['transitions' => $project->statuses()->orderBy('position')->get()
            ->filter(fn ($s) => (int) $s->id !== (int) $task->status_id)
            ->map(fn ($s) => [
                'status_id' => $s->id, 'name' => $s->name,
                'allowed' => in_array((int) $s->id, $allowed, true),
                'blocked_reason' => $this->guard->denial($task, $s),
            ])->values()]);
    }

    /** @return array<string, mixed> */
    private function payload(Project $project): array
    {
        return [
            'enforce_workflow' => $project->enforce_workflow,
            'statuses' => $project->statuses()->orderBy('position')->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'category' => $s->category, 'entry_rules' => $s->entry_rules ?? [], 'wip_limit' => $s->wip_limit]),
            'transitions' => StatusTransition::where('project_id', $project->id)->get(['from_status_id', 'to_status_id']),
            'entry_rule_catalog' => WorkflowGuard::ENTRY_RULES,
        ];
    }
}
