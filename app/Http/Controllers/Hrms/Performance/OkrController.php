<?php

namespace App\Http\Controllers\Hrms\Performance;

use App\Http\Controllers\Controller;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\OkrKeyResult;
use App\Models\Hrms\Performance\OkrObjective;
use App\Models\User;
use App\Services\Hrms\Employee\ReportingLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * OKRs. Company objectives (no owner) are readable by everyone with HR access and written by performance managers;
 * a personal objective belongs to its owner, who writes it and updates its key results; their manager and
 * performance managers can read and write it too. Progress is computed from the numbers, never typed.
 */
class OkrController extends Controller
{
    public function __construct(private readonly ReportingLine $reporting) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = OkrObjective::query()->with(['keyResults', 'employee:id,name,user_id,manager_id'])->orderByDesc('id')->limit(300)->get()
            ->filter(fn (OkrObjective $o) => $o->employee_id === null || $this->canWrite($user, $o->employee) || $user->hasPermission('hrms.performance.view_all'))->values();

        return response()->json(['objectives' => $rows->map(fn ($o) => $this->present($o, $user))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'scope' => ['required', 'in:company,mine,employee'],
            'employee_id' => ['required_if:scope,employee', 'nullable', 'integer', 'exists:employees,id'],
            'parent_id' => ['nullable', 'integer', 'exists:okr_objectives,id'],
            'title' => ['required', 'string', 'max:200'],
            'period' => ['required', 'string', 'max:32'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'key_results' => ['sometimes', 'array', 'max:10'],
            'key_results.*.title' => ['required', 'string', 'max:200'],
            'key_results.*.start_value' => ['nullable', 'numeric'],
            'key_results.*.target_value' => ['required', 'numeric'],
            'key_results.*.unit' => ['nullable', 'string', 'max:24'],
        ]);

        $owner = match ($data['scope']) {
            'company' => null,
            'mine' => Employee::query()->where('user_id', $user->id)->first() ?? throw ValidationException::withMessages(['scope' => __('You need an employment record to own an objective.')]),
            default => Employee::findOrFail($data['employee_id']),
        };
        abort_unless($owner === null ? $user->hasPermission('hrms.performance.manage') : $this->canWrite($user, $owner), 403);

        $objective = OkrObjective::create([
            'employee_id' => $owner?->id, 'parent_id' => $data['parent_id'] ?? null, 'title' => trim($data['title']), 'period' => $data['period'],
            'start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'status' => 'active', 'created_by_user_id' => $user->id,
        ]);
        foreach ($data['key_results'] ?? [] as $kr) {
            $objective->keyResults()->create([
                'title' => trim($kr['title']), 'start_value' => $kr['start_value'] ?? 0, 'target_value' => $kr['target_value'],
                'current_value' => $kr['start_value'] ?? 0, 'unit' => $kr['unit'] ?? null,
            ]);
        }

        return response()->json(['message' => __('Objective created.'), 'objective' => $this->present($objective->load(['keyResults', 'employee']), $user)], 201);
    }

    public function updateKeyResult(Request $request, OkrKeyResult $keyResult): JsonResponse
    {
        $objective = $keyResult->objective_id ? OkrObjective::with(['keyResults', 'employee'])->findOrFail($keyResult->objective_id) : abort(404);
        abort_unless($this->mayWrite($request->user(), $objective) && $objective->status === 'active', 403);

        $keyResult->update($request->validate(['current_value' => ['required', 'numeric']]));

        return response()->json(['objective' => $this->present($objective->refresh()->load(['keyResults', 'employee']), $request->user())]);
    }

    public function close(Request $request, OkrObjective $objective): JsonResponse
    {
        abort_unless($this->mayWrite($request->user(), $objective), 403);
        $objective->update(['status' => 'closed']);

        return response()->json(['message' => __('Objective closed.'), 'objective' => $this->present($objective->load(['keyResults', 'employee']), $request->user())]);
    }

    private function mayWrite(User $user, OkrObjective $o): bool
    {
        return $o->employee_id === null ? $user->hasPermission('hrms.performance.manage') : $this->canWrite($user, $o->employee);
    }

    private function canWrite(User $user, ?Employee $employee): bool
    {
        return $employee !== null && (
            $employee->user_id === $user->id
            || $user->hasPermission('hrms.performance.manage')
            || $this->reporting->managerOf($employee)?->user_id === $user->id
        );
    }

    /** @return array<string, mixed> */
    private function present(OkrObjective $o, User $user): array
    {
        return [
            'id' => $o->id,
            'scope' => $o->employee_id === null ? 'company' : 'personal',
            'owner' => $o->employee ? ['id' => $o->employee->id, 'name' => $o->employee->name] : null,
            'parent_id' => $o->parent_id,
            'title' => $o->title,
            'period' => $o->period,
            'start_date' => $o->start_date->toDateString(),
            'end_date' => $o->end_date->toDateString(),
            'status' => $o->status,
            'progress' => $o->progress(),
            'can_edit' => $o->status === 'active' && $this->mayWrite($user, $o),
            'key_results' => $o->keyResults->map(fn ($k) => [
                'id' => $k->id, 'title' => $k->title, 'start_value' => $k->start_value, 'target_value' => $k->target_value,
                'current_value' => $k->current_value, 'unit' => $k->unit, 'progress' => (int) round($k->progress()),
            ])->values(),
        ];
    }
}
