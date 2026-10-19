<?php

namespace App\Http\Controllers\Hrms\Employee;

use App\Http\Controllers\Controller;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Employee\EmployeeChange;
use App\Services\Hrms\Employee\ReportingLine;
use App\Services\Hrms\Lifecycle\EmployeeChangeService;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\ReportsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Transfers, promotions and confirmations. A request comes from HR (`hrms.employees.manage`) or from the
 * employee's reporting manager; deciding is the approval engine's call; HR sees every change, a manager
 * the changes of their own reports, an approver the ones waiting for them.
 */
class EmployeeChangeController extends Controller
{
    public function __construct(
        private readonly EmployeeChangeService $service,
        private readonly ApprovalService $approvals,
        private readonly ReportingLine $reporting,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $all = $user->hasPermission('hrms.employees.manage') || $user->hasPermission('hrms.employees.view');

        $query = EmployeeChange::query()->with(['employee:id,name,employee_code', 'approval'])->latest('id')->limit(100);
        $rows = $query->get();

        if (! $all) {
            $mine = Employee::query()->whereIn('user_id', [$user->id])->pluck('id')->all();
            $reports = Employee::query()->whereIn('user_id', ReportsTo::idsFor($user))->pluck('id')->all();
            $rows = $rows->filter(fn ($c) => in_array($c->employee_id, [...$mine, ...$reports], true)
                || $c->requested_by_user_id === $user->id
                || ($c->approval && $this->approvals->canAct($c->approval, $user)))->values();
        }

        return response()->json([
            'changes' => $rows->map(fn ($c) => $this->present($c, $user))->values(),
            'types' => EmployeeChange::TYPES,
        ]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        $user = $request->user();
        $isManager = $this->reporting->managerOf($employee)?->user_id === $user->id;
        abort_unless($user->hasPermission('hrms.employees.manage') || $isManager, 403, __('You cannot request a change for this person.'));

        $data = $request->validate([
            'type' => ['required', Rule::in(EmployeeChange::TYPES)],
            'effective_date' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'changes' => ['sometimes', 'array'],
        ]);

        $change = $this->service->request($employee, $data['type'], $data['effective_date'], $data['changes'] ?? [], $data['reason'] ?? null, $user);

        return response()->json(['message' => __('Change requested.'), 'change' => $this->present($change->load('employee:id,name,employee_code'), $user)], 201);
    }

    public function approve(Request $request, EmployeeChange $change): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['message' => __('Change decided.'), 'change' => $this->present($this->service->approve($change, $request->user(), $data['note'] ?? null)->load('employee:id,name,employee_code'), $request->user())]);
    }

    public function reject(Request $request, EmployeeChange $change): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return response()->json(['message' => __('Change rejected.'), 'change' => $this->present($this->service->reject($change, $request->user(), $data['note'])->load('employee:id,name,employee_code'), $request->user())]);
    }

    public function withdraw(Request $request, EmployeeChange $change): JsonResponse
    {
        abort_unless(
            $change->requested_by_user_id === $request->user()->id || $request->user()->hasPermission('hrms.employees.manage'),
            403,
            __('Only the requester or HR can withdraw a change.')
        );

        return response()->json(['message' => __('Change withdrawn.'), 'change' => $this->present($this->service->withdraw($change, $request->user())->load('employee:id,name,employee_code'), $request->user())]);
    }

    /** @return array<string, mixed> */
    private function present(EmployeeChange $c, $user): array
    {
        return [
            'id' => $c->id,
            'employee' => $c->employee ? ['id' => $c->employee->id, 'name' => $c->employee->name, 'employee_code' => $c->employee->employee_code] : null,
            'type' => $c->type,
            'effective_date' => $c->effective_date->toDateString(),
            'changes' => (object) ($c->changes ?? []),
            'reason' => $c->reason,
            'status' => $c->status,
            'decision_note' => $c->decision_note,
            'can_decide' => $c->isOpen() && $c->approval !== null && $this->approvals->canAct($c->approval, $user),
            'applied_at' => $c->applied_at?->toIso8601String(),
        ];
    }
}
