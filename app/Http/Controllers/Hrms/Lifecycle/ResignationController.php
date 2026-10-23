<?php

namespace App\Http\Controllers\Hrms\Lifecycle;

use App\Enums\Hrms\ResignationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Lifecycle\ResignationApproveRequest;
use App\Http\Requests\Hrms\Lifecycle\ResignationRejectRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\ResignationRequest;
use App\Services\Hrms\Lifecycle\ResignationService;
use App\Services\Hrms\Shared\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resignations over HTTP. Filing is self-service (your own employment record only); reading is
 * your own, the ones waiting for your decision, or — with `hrms.offboarding.view` — everyone's;
 * deciding is the approval engine's call (the step's approver, a delegate, or the audited override).
 */
class ResignationController extends Controller
{
    public function __construct(
        private readonly ResignationService $service,
        private readonly ApprovalService $approvals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $own = Employee::query()->where('user_id', $user->id)->value('id');

        $mine = $own ? ResignationRequest::query()->with('employee:id,name')->where('employee_id', $own)->latest('id')->get() : collect();

        $toDecide = ResignationRequest::query()->with(['employee:id,name', 'approval'])
            ->where('status', ResignationStatus::Pending->value)
            ->get()
            ->filter(fn (ResignationRequest $r) => $r->approval && $this->approvals->canAct($r->approval, $user) && $r->employee_id !== $own)
            ->values();

        $all = $user->hasPermission('hrms.offboarding.view')
            ? ResignationRequest::query()->with('employee:id,name')->latest('id')->limit(100)->get()
            : null;

        return response()->json([
            'mine' => $mine->map(fn ($r) => $this->present($r))->values(),
            'to_decide' => $toDecide->map(fn ($r) => $this->present($r))->values(),
            'all' => $all?->map(fn ($r) => $this->present($r))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = Employee::query()->where('user_id', $request->user()->id)->first();
        abort_if($employee === null, 403, __('You have no employment record to resign from.'));

        $data = $request->validate([
            'requested_last_day' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'notice_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $resignation = $this->service->submit($employee, $data, $request->user());

        return response()->json(['message' => __('Resignation submitted.'), 'resignation' => $this->present($resignation->load('employee:id,name'))], 201);
    }

    public function approve(ResignationApproveRequest $request, ResignationRequest $resignation): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'message' => __('Resignation decided.'),
            'resignation' => $this->present($this->service->approve($resignation, $request->user(), $data['note'] ?? null)->load('employee:id,name')),
        ]);
    }

    public function reject(ResignationRejectRequest $request, ResignationRequest $resignation): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'message' => __('Resignation rejected.'),
            'resignation' => $this->present($this->service->reject($resignation, $request->user(), $data['note'])->load('employee:id,name')),
        ]);
    }

    public function withdraw(Request $request, ResignationRequest $resignation): JsonResponse
    {
        abort_unless($resignation->employee?->user_id === $request->user()->id, 403, __('Only the employee can withdraw a resignation.'));

        return response()->json([
            'message' => __('Resignation withdrawn.'),
            'resignation' => $this->present($this->service->withdraw($resignation, $request->user())->load('employee:id,name')),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(ResignationRequest $r): array
    {
        return [
            'id' => $r->id,
            'employee' => $r->employee ? ['id' => $r->employee->id, 'name' => $r->employee->name] : null,
            'requested_last_day' => $r->requested_last_day->toDateString(),
            'notice_period_days' => $r->notice_period_days,
            'reason' => $r->reason,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'status_color' => $r->status->color(),
            'decision_note' => $r->decision_note,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'offboarding_case_id' => $r->offboarding_case_id,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
