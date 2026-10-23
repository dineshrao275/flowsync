<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hrms\Shift;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Shift\ShiftSwapApproveRequest;
use App\Http\Requests\Hrms\Shift\ShiftSwapRejectRequest;
use App\Models\Hrms\Attendance\ShiftSwap;
use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\Hrms\Shift\ShiftSwapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shift swaps. Anyone with an employment record can ask to swap their own shift; the counterparty and the
 * manager decide through the approval engine. HR (`hrms.shifts.manage`) sees every swap, others only
 * the ones they asked for, were asked for, or can decide.
 */
class ShiftSwapController extends Controller
{
    public function __construct(
        private readonly ShiftSwapService $service,
        private readonly ApprovalService $approvals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $all = $user->hasPermission('hrms.shifts.manage');
        $mine = Employee::query()->where('user_id', $user->id)->value('id');

        $rows = ShiftSwap::query()->with(['requester:id,name,employee_code', 'counterparty:id,name,employee_code', 'approval'])->latest('id')->limit(100)->get();
        if (! $all) {
            $rows = $rows->filter(fn (ShiftSwap $s) => ($mine !== null && in_array($mine, [$s->requester_employee_id, $s->counterparty_employee_id], true))
                || ($s->approval !== null && $this->approvals->canAct($s->approval, $user)))->values();
        }

        return response()->json(['swaps' => $rows->map(fn ($s) => $this->present($s, $user))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $requester = Employee::query()->where('user_id', $user->id)->first();
        abort_if($requester === null, 403, __('You need an employment record to swap shifts.'));

        $data = $request->validate([
            'counterparty_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'swap_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $swap = $this->service->request($requester, Employee::findOrFail($data['counterparty_employee_id']), $data['swap_date'], $data['reason'] ?? null, $user);

        return response()->json(['message' => __('Swap requested.'), 'swap' => $this->present($this->load($swap), $user)], 201);
    }

    public function approve(ShiftSwapApproveRequest $request, ShiftSwap $swap): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['message' => __('Swap decided.'), 'swap' => $this->present($this->load($this->service->approve($swap, $request->user(), $data['note'] ?? null)), $request->user())]);
    }

    public function reject(ShiftSwapRejectRequest $request, ShiftSwap $swap): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['message' => __('Swap rejected.'), 'swap' => $this->present($this->load($this->service->reject($swap, $request->user(), $data['note'])), $request->user())]);
    }

    public function withdraw(Request $request, ShiftSwap $swap): JsonResponse
    {
        abort_unless(
            $swap->requested_by_user_id === $request->user()->id || $request->user()->hasPermission('hrms.shifts.manage'),
            403,
            __('Only the requester or HR can withdraw a swap.')
        );

        return response()->json(['message' => __('Swap withdrawn.'), 'swap' => $this->present($this->load($this->service->withdraw($swap, $request->user())), $request->user())]);
    }

    private function load(ShiftSwap $swap): ShiftSwap
    {
        return $swap->load(['requester:id,name,employee_code', 'counterparty:id,name,employee_code', 'approval']);
    }

    /** @return array<string, mixed> */
    private function present(ShiftSwap $s, $user): array
    {
        return [
            'id' => $s->id,
            'requester' => $s->requester ? ['id' => $s->requester->id, 'name' => $s->requester->name] : null,
            'counterparty' => $s->counterparty ? ['id' => $s->counterparty->id, 'name' => $s->counterparty->name] : null,
            'swap_date' => $s->swap_date->toDateString(),
            'requester_shift_id' => $s->requester_shift_id,
            'counterparty_shift_id' => $s->counterparty_shift_id,
            'reason' => $s->reason,
            'status' => $s->status,
            'decision_note' => $s->decision_note,
            'can_decide' => $s->isOpen() && $s->approval !== null && $this->approvals->canAct($s->approval, $user),
        ];
    }
}
