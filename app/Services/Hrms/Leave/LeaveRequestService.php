<?php

namespace App\Services\Hrms\Leave;

use App\Enums\Hrms\LeaveRequestStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\User;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave/HRMS — raising an ask.
 *
 * The asking half of the lifecycle (deciding lives in
 * `LeaveRequestDecisions`, the money in `LeaveBalanceService` — the P5
 * split rule). Validate through `LeaveRequestValidation`, persist the row
 * with its day split, and open the routed chain; everything after
 * `submitted` belongs to the decider.
 *
 * Routing lives in `LeaveApprovalRouting` (manager, department head, HR) —
 * P6.3 upgraded the single manager step, and the engine's step model meant
 * the upgrade touched routing, not this file's transitions.
 */
class LeaveRequestService
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly LeaveRequestValidation $validation,
        private readonly LeaveApprovalRouting $routing,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array{leave_type_id: int, from_date: string, to_date: string, from_half?: string, to_half?: string, reason: string, contact_during_leave?: string|null, document_id?: int|null}  $data
     *
     * @throws ValidationException on an inactive type, a bad window, quota breach, overlap, or shortfall
     */
    public function request(Employee $employee, array $data, ?User $actor = null): LeaveRequest
    {
        $input = $this->validation->validate($employee, $data);

        return DB::transaction(fn (): LeaveRequest => $this->buildAsk($employee, $input, $actor));
    }

    /**
     * Price a range without judging it: the availability preview's entry
     * point, straight through to the shared split math.
     */
    public function previewTotal(
        Employee $employee,
        int $typeId,
        Carbon|string $from,
        Carbon|string $to,
        ?string $fromHalf = null,
        ?string $toHalf = null,
    ): float {
        return $this->validation->previewTotal($employee, $typeId, $from, $to, $fromHalf, $toHalf);
    }

    /**
     * Persist the ask, split its days, and open the manager step.
     */
    private function buildAsk(Employee $employee, LeaveRequestInput $input, ?User $actor): LeaveRequest
    {
        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $input->type->id,
            'from_date' => $input->from->toDateString(),
            'to_date' => $input->to->toDateString(),
            'from_half' => $input->fromHalf,
            'to_half' => $input->toHalf,
            'total_days' => $input->total,
            'reason' => $input->reason,
            'contact_during_leave' => $input->contact,
            'document_id' => $input->documentId,
            'status' => LeaveRequestStatus::Submitted->value,
            'created_by' => $actor?->id,
        ]);

        foreach ($input->split as $row) {
            $request->days()->create($row);
        }

        $approval = $this->approvals->request(
            $this->routing->stepsFor($employee),
            $request,
            'leave.request',
            'Leave request',
            ['work_dates' => $input->from->toDateString().' to '.$input->to->toDateString(), 'total_days' => $input->total],
            $actor,
            $employee->id,
        );

        $request->update(['approval_id' => $approval->id]);
        $this->audit->log($request, 'leave.requested', null, $this->snapshot($request), $actor);

        return $request->refresh();
    }

    /**
     * Edit an ask's notes without touching its priced range.
     *
     * Dates, halves and type are rejected by name in the FormRequest — this
     * only ever sees reason, contact and document. Re-dating is
     * cancel-and-refile, because the balance already prices the old range.
     *
     * @param  array{reason?: string, contact_during_leave?: string|null, document_id?: int|null}  $data
     *
     * @throws ValidationException on a terminal ask
     */
    public function updateAsk(LeaveRequest $request, array $data, ?User $actor = null): LeaveRequest
    {
        if ($request->status->isTerminal()) {
            throw ValidationException::withMessages(['form' => 'A decided ask cannot be edited.']);
        }

        $before = ['reason' => $request->reason];
        $updates = [];

        if (isset($data['reason'])) {
            $updates['reason'] = trim((string) $data['reason']);
        }

        if (array_key_exists('contact_during_leave', $data)) {
            $updates['contact_during_leave'] = $data['contact_during_leave'];
        }

        if (array_key_exists('document_id', $data)) {
            $updates['document_id'] = $data['document_id'];
        }

        if ($updates !== []) {
            $request->update($updates);
        }

        $this->audit->log($request, 'leave.updated', $before, ['reason' => $request->refresh()->reason], $actor);

        return $request->refresh();
    }

    /** @return array<string, mixed> */
    private function snapshot(LeaveRequest $request): array
    {
        return [
            'employee_id' => $request->employee_id,
            'leave_type_id' => $request->leave_type_id,
            'total_days' => (float) $request->total_days,
            'status' => $request->status->value,
            'approval_id' => $request->approval_id,
        ];
    }
}
