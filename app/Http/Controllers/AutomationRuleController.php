<?php

namespace App\Http\Controllers;

use App\Models\AutomationRule;
use App\Models\Label;
use App\Models\Priority;
use App\Models\Project;
use App\Services\ActivityLogger;
use App\Services\Automation\AutomationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Project automation rules: "when X happens and Y holds, do Z". Managed by whoever manages project settings. */
class AutomationRuleController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function catalog(): JsonResponse
    {
        return response()->json(AutomationCatalog::describe());
    }

    public function index(Project $project): JsonResponse
    {
        $this->authorize('settings', $project);

        return response()->json(['rules' => $project->automationRules()->orderBy('id')->get()->map(fn ($r) => $this->present($r))]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('settings', $project);
        $data = $this->validated($request, $project);

        $rule = $project->automationRules()->create($data + ['created_by' => $request->user()->id]);

        return response()->json(['message' => 'Rule created.', 'rule' => $this->present($rule)], 201);
    }

    public function update(Request $request, Project $project, AutomationRule $rule): JsonResponse
    {
        $this->authorize('settings', $project);
        abort_unless($rule->project_id === $project->id, 404);

        $rule->update($this->validated($request, $project));

        return response()->json(['message' => 'Rule saved.', 'rule' => $this->present($rule->refresh())]);
    }

    public function destroy(Project $project, AutomationRule $rule): JsonResponse
    {
        $this->authorize('settings', $project);
        abort_unless($rule->project_id === $project->id, 404);
        $rule->delete();

        return response()->json(['message' => 'Rule deleted.']);
    }

    public function runs(Project $project, AutomationRule $rule): JsonResponse
    {
        $this->authorize('settings', $project);
        abort_unless($rule->project_id === $project->id, 404);

        return response()->json(['runs' => $rule->runs()->orderByDesc('id')->limit(50)->get(['id', 'task_id', 'status', 'summary', 'created_at'])]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Project $project): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
            'conditions' => ['present', 'array'],
            'actions' => ['required', 'array'],
        ]);

        AutomationCatalog::assertValid($data['trigger'], $data['conditions'], $data['actions']);
        $this->assertReferences($project, $data['conditions'], $data['actions']);

        return $data;
    }

    /**
     * A rule may only point at things that exist in this project — otherwise it would fail
     * quietly on every run (or, worse, reach outside the project).
     *
     * @param  array<int, array<string, mixed>>  $conditions
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function assertReferences(Project $project, array $conditions, array $actions): void
    {
        $statusSlugs = $project->statuses()->pluck('slug')->all();
        $priorityTaken = fn (string $slug) => Priority::where('slug', $slug)->exists();
        $labelOk = fn ($id) => Label::where('workspace_id', $project->workspace_id)->whereKey((int) $id)->exists();
        $memberIds = $project->members()->pluck('users.id')->map(fn ($i) => (string) $i)->all();

        $bad = fn (string $key, string $why) => throw ValidationException::withMessages([$key => $why]);

        foreach ($conditions as $i => $c) {
            if ($c['field'] === 'status' && array_diff((array) $c['value'], $statusSlugs)) {
                $bad("conditions.{$i}", 'Unknown status for this project.');
            }
            if ($c['field'] === 'priority' && array_filter((array) $c['value'], fn ($v) => ! $priorityTaken($v))) {
                $bad("conditions.{$i}", 'Unknown priority.');
            }
            if ($c['field'] === 'label' && ! $labelOk($c['value'])) {
                $bad("conditions.{$i}", 'That label does not belong to this workspace.');
            }
        }

        foreach ($actions as $i => $a) {
            $ok = match ($a['type']) {
                'set_assignee' => in_array((string) $a['user'], ['reporter', 'lead', 'unassign', ...$memberIds], true),
                'set_priority' => $priorityTaken((string) $a['priority']),
                'move_to_status' => in_array((string) $a['status'], $statusSlugs, true),
                'add_label' => $labelOk($a['label']),
                'notify' => ! array_diff(array_map('strval', (array) ($a['to'] ?? ['assignee'])), ['assignee', 'reporter', 'lead', ...$memberIds]),
                default => true,
            };
            if (! $ok) {
                $bad("actions.{$i}", 'This action points at something that is not part of the project.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function present(AutomationRule $r): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'is_active' => $r->is_active, 'trigger' => $r->trigger,
            'conditions' => $r->conditions ?? [], 'actions' => $r->actions, 'run_count' => $r->run_count,
            'last_run_at' => $r->last_run_at?->toIso8601String(), 'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
