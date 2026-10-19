<?php

namespace App\Http\Controllers\Hrms\Performance;

use App\Http\Controllers\Controller;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\ImprovementPlan;
use App\Models\User;
use App\Services\Hrms\Employee\ReportingLine;
use App\Services\Hrms\Performance\ImprovementPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Improvement plans are confidential: only the person, their manager and performance managers can see one.
 * A broad `view_all` grant is deliberately not enough.
 */
class ImprovementPlanController extends Controller
{
    public function __construct(
        private readonly ImprovementPlanService $service,
        private readonly ReportingLine $reporting,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = ImprovementPlan::query()->with('employee:id,name,employee_code,user_id,manager_id')->latest('id')->limit(200)->get()
            ->filter(fn (ImprovementPlan $p) => $this->canSee($user, $p))->values();

        return response()->json(['plans' => $rows->map(fn ($p) => $this->present($p, $user))->values()]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canRun($user, $employee), 403, __('You cannot open a plan for this person.'));

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:3000'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'milestones' => ['sometimes', 'array', 'max:20'],
            'milestones.*.title' => ['required', 'string', 'max:200'],
            'milestones.*.due_date' => ['nullable', 'date'],
        ]);

        $plan = $this->service->open($employee, $data['reason'], $data['start_date'], $data['end_date'], $data['milestones'] ?? [], $user);

        return response()->json(['message' => __('Plan opened.'), 'plan' => $this->present($plan->load('employee'), $user)], 201);
    }

    public function milestone(Request $request, ImprovementPlan $plan, string $milestone): JsonResponse
    {
        abort_unless($this->canRun($request->user(), $plan->employee), 403);
        $data = $request->validate(['done' => ['required', 'boolean']]);

        return response()->json(['plan' => $this->present($this->service->toggleMilestone($plan, $milestone, (bool) $data['done'], $request->user())->load('employee'), $request->user())]);
    }

    public function extend(Request $request, ImprovementPlan $plan): JsonResponse
    {
        abort_unless($this->canRun($request->user(), $plan->employee), 403);
        $data = $request->validate(['end_date' => ['required', 'date']]);

        return response()->json(['message' => __('Plan extended.'), 'plan' => $this->present($this->service->extend($plan, $data['end_date'], $request->user())->load('employee'), $request->user())]);
    }

    public function close(Request $request, ImprovementPlan $plan): JsonResponse
    {
        abort_unless($this->canRun($request->user(), $plan->employee), 403);
        $data = $request->validate(['status' => ['required', 'string'], 'outcome' => ['required', 'string', 'max:3000']]);

        return response()->json(['message' => __('Plan closed.'), 'plan' => $this->present($this->service->close($plan, $data['status'], $data['outcome'], $request->user())->load('employee'), $request->user())]);
    }

    private function canRun(User $user, ?Employee $employee): bool
    {
        if ($employee === null) {
            return false;
        }

        return $user->hasPermission('hrms.performance.manage')
            || ($this->reporting->managerOf($employee)?->user_id === $user->id);
    }

    private function canSee(User $user, ImprovementPlan $plan): bool
    {
        return $this->canRun($user, $plan->employee) || $plan->employee?->user_id === $user->id;
    }

    /** @return array<string, mixed> */
    private function present(ImprovementPlan $p, User $user): array
    {
        return [
            'id' => $p->id,
            'employee' => $p->employee ? ['id' => $p->employee->id, 'name' => $p->employee->name] : null,
            'reason' => $p->reason,
            'start_date' => $p->start_date->toDateString(),
            'end_date' => $p->end_date->toDateString(),
            'status' => $p->status,
            'milestones' => $p->milestones ?? [],
            'outcome' => $p->outcome,
            'closed_at' => $p->closed_at?->toIso8601String(),
            'can_manage' => $p->isOpen() && $this->canRun($user, $p->employee),
        ];
    }
}
