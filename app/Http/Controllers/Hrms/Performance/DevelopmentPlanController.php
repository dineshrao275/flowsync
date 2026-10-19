<?php

namespace App\Http\Controllers\Hrms\Performance;

use App\Http\Controllers\Controller;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\DevelopmentPlan;
use App\Models\User;
use App\Services\Hrms\Employee\ReportingLine;
use App\Services\HrmsAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Individual development plans. The employee owns theirs (creates it, ticks actions, closes it); their manager and
 * performance managers can open one for them and follow it. Nobody else sees it.
 */
class DevelopmentPlanController extends Controller
{
    public function __construct(
        private readonly ReportingLine $reporting,
        private readonly HrmsAuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = DevelopmentPlan::query()->with('employee:id,name,user_id,manager_id')->latest('id')->limit(200)->get()
            ->filter(fn (DevelopmentPlan $p) => $this->allowed($user, $p->employee))->values();

        return response()->json(['plans' => $rows->map(fn ($p) => $this->present($p))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'title' => ['required', 'string', 'max:200'],
            'focus' => ['nullable', 'string', 'max:2000'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'actions' => ['sometimes', 'array', 'max:20'],
            'actions.*.title' => ['required', 'string', 'max:200'],
            'actions.*.due_date' => ['nullable', 'date'],
        ]);

        $own = Employee::query()->where('user_id', $user->id)->first();
        $employee = ! empty($data['employee_id']) ? Employee::findOrFail($data['employee_id']) : $own;
        abort_if($employee === null, 422, __('You need an employment record to have a development plan.'));
        abort_unless($this->allowed($user, $employee), 403, __('You cannot open a plan for this person.'));

        if (Carbon::parse($data['end_date'])->lte(Carbon::parse($data['start_date']))) {
            throw ValidationException::withMessages(['end_date' => __('The plan must end after it starts.')]);
        }

        $plan = DevelopmentPlan::create([
            'employee_id' => $employee->id, 'created_by_user_id' => $user->id, 'title' => trim($data['title']), 'focus' => $data['focus'] ?? null,
            'start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'status' => 'active',
            'actions' => collect($data['actions'] ?? [])->map(fn ($a) => ['id' => (string) Str::uuid(), 'title' => trim($a['title']), 'due_date' => $a['due_date'] ?? null, 'done' => false])->values()->all(),
        ]);
        $this->audit->log($plan, 'development_plan.opened', null, ['employee_id' => $employee->id], $user);

        return response()->json(['message' => __('Plan opened.'), 'plan' => $this->present($plan->load('employee'))], 201);
    }

    public function action(Request $request, DevelopmentPlan $plan, string $action): JsonResponse
    {
        abort_unless($this->allowed($request->user(), $plan->employee) && $plan->isOpen(), 403);
        $done = (bool) $request->validate(['done' => ['required', 'boolean']])['done'];

        $found = false;
        $actions = collect($plan->actions ?? [])->map(function ($a) use ($action, $done, &$found) {
            if ($a['id'] === $action) {
                $found = true;
                $a['done'] = $done;
            }

            return $a;
        })->all();
        abort_unless($found, 404);

        $plan->update(['actions' => $actions]);

        return response()->json(['plan' => $this->present($plan->refresh()->load('employee'))]);
    }

    public function close(Request $request, DevelopmentPlan $plan): JsonResponse
    {
        abort_unless($this->allowed($request->user(), $plan->employee) && $plan->isOpen(), 403);
        $status = $request->validate(['status' => ['required', 'in:completed,cancelled']])['status'];

        $plan->update(['status' => $status, 'closed_at' => now()]);
        $this->audit->log($plan, 'development_plan.closed', ['status' => 'active'], ['status' => $status], $request->user());

        return response()->json(['message' => __('Plan closed.'), 'plan' => $this->present($plan->refresh()->load('employee'))]);
    }

    private function allowed(User $user, ?Employee $employee): bool
    {
        return $employee !== null && (
            $employee->user_id === $user->id
            || $user->hasPermission('hrms.performance.manage')
            || $this->reporting->managerOf($employee)?->user_id === $user->id
        );
    }

    /** @return array<string, mixed> */
    private function present(DevelopmentPlan $p): array
    {
        return [
            'id' => $p->id,
            'employee' => $p->employee ? ['id' => $p->employee->id, 'name' => $p->employee->name] : null,
            'title' => $p->title,
            'focus' => $p->focus,
            'start_date' => $p->start_date->toDateString(),
            'end_date' => $p->end_date->toDateString(),
            'status' => $p->status,
            'actions' => $p->actions ?? [],
            'closed_at' => $p->closed_at?->toIso8601String(),
        ];
    }
}
