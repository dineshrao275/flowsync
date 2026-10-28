<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\HrAutomationRule;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HR event → tasks rules. Managing them is an HR onboarding decision, but the tasks land in a project,
 * so the author must also be allowed to configure that project (ProjectPolicy::settings).
 */
class HrAutomationRuleController extends Controller
{
    public const TRIGGERS = ['employee.created', 'leave.approved', 'onboarding.case_started', 'offboarding.case_initiated', 'resignation.approved'];

    public function index(): JsonResponse
    {
        return response()->json([
            'rules' => HrAutomationRule::query()->with('project:id,name,key')->orderBy('name')->get()->map(fn ($r) => $this->present($r))->values(),
            'triggers' => self::TRIGGERS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->authorize('settings', Project::findOrFail($data['project_id']));

        $rule = HrAutomationRule::create($data + ['created_by' => $request->user()->id, 'is_active' => $data['is_active'] ?? true]);

        return response()->json(['message' => __('Rule saved.'), 'rule' => $this->present($rule->load('project:id,name,key'))], 201);
    }

    public function update(Request $request, HrAutomationRule $rule): JsonResponse
    {
        $data = $this->validated($request);
        $this->authorize('settings', Project::findOrFail($data['project_id']));

        $rule->update($data);

        return response()->json(['message' => __('Rule saved.'), 'rule' => $this->present($rule->refresh()->load('project:id,name,key'))]);
    }

    public function destroy(HrAutomationRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(['message' => __('Rule deleted.')]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', Rule::in(self::TRIGGERS)],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'is_active' => ['sometimes', 'boolean'],
            'tasks' => ['required', 'array', 'min:1', 'max:20'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string', 'max:2000'],
            'tasks.*.assignee' => ['sometimes', Rule::in(['none', 'employee', 'manager'])],
            'tasks.*.due_in_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(HrAutomationRule $r): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'trigger' => $r->trigger, 'is_active' => $r->is_active,
            'project' => $r->project ? ['id' => $r->project->id, 'name' => $r->project->name, 'key' => $r->project->key] : null,
            'tasks' => $r->tasks, 'run_count' => $r->run_count, 'last_run_at' => $r->last_run_at?->toIso8601String(),
        ];
    }
}
