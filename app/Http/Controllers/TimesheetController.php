<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TimesheetApproveRequest;
use App\Http\Requests\TimesheetRejectRequest;
use App\Http\Requests\TimesheetSubmitRequest;
use App\Http\Requests\TimesheetWeekRequest;
use App\Models\Timesheet;
use App\Services\Time\TimesheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Weekly timesheets: see your week, submit it, and (as an approver) decide the ones waiting for you. */
class TimesheetController extends Controller
{
    public function __construct(private readonly TimesheetService $service) {}

    public function week(TimesheetWeekRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['timesheet' => $this->service->week($request->user(), $data['week'] ?? now())]);
    }

    public function submit(TimesheetSubmitRequest $request): JsonResponse
    {
        $data = $request->validated();

        $sheet = $this->service->submit($request->user(), $data['week'] ?? now());

        return response()->json([
            'message' => __('Timesheet submitted.'),
            'timesheet' => $this->service->week($request->user(), $sheet->week_start),
        ]);
    }

    public function pending(Request $request): JsonResponse
    {
        return response()->json([
            'timesheets' => $this->service->pendingFor($request->user())->map(fn (Timesheet $s) => $this->present($s))->values(),
        ]);
    }

    public function show(Request $request, Timesheet $timesheet): JsonResponse
    {
        abort_unless(
            $timesheet->user_id === $request->user()->id || $this->service->canDecide($request->user(), $timesheet),
            403,
            __('You cannot view this timesheet.')
        );

        $timesheet->load('user:id,name,email');

        return response()->json([
            'timesheet' => $this->present($timesheet) + ['detail' => $this->service->week($timesheet->user, $timesheet->week_start)],
        ]);
    }

    public function approve(TimesheetApproveRequest $request, Timesheet $timesheet): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'message' => __('Timesheet approved.'),
            'timesheet' => $this->present($this->service->decide($timesheet, $request->user(), true, $data['note'] ?? null)->load('user:id,name,email')),
        ]);
    }

    public function reject(TimesheetRejectRequest $request, Timesheet $timesheet): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'message' => __('Timesheet sent back.'),
            'timesheet' => $this->present($this->service->decide($timesheet, $request->user(), false, $data['note'])->load('user:id,name,email')),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Timesheet $s): array
    {
        return [
            'id' => $s->id,
            'user' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name, 'email' => $s->user->email] : null,
            'week_start' => $s->week_start->toDateString(),
            'status' => $s->status,
            'total_minutes' => $s->total_minutes,
            'billable_minutes' => $s->billable_minutes,
            'submitted_at' => $s->submitted_at?->toISOString(),
            'decided_at' => $s->decided_at?->toISOString(),
            'decision_note' => $s->decision_note,
        ];
    }
}
