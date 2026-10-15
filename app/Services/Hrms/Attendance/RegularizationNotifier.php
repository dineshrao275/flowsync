<?php

namespace App\Services\Hrms\Attendance;

use App\Models\Hrms\Attendance\AttendanceRegularizationRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\NotificationService;

/**
 * Attendance/HRMS — who gets told about a correction ask.
 *
 * Its own class because the event names, the payload shapes and the
 * skip-self rule would otherwise scatter across the service's request,
 * approve and reject paths and drift apart. Nobody is nudged about their
 * own action: the manager who approves does not need a "decided" toast for
 * the decision they just made.
 */
class RegularizationNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function requestRaised(AttendanceRegularizationRequest $request, ?Employee $manager, ?User $actor): void
    {
        $this->send($manager?->user_id, 'hrms.attendance.regularization.requested', [
            'regularization_request_id' => $request->id,
            'employee_id' => $request->employee_id,
            'employee_name' => $request->employee->name,
            'work_date' => $request->work_date->toDateString(),
        ], $actor);
    }

    public function decided(AttendanceRegularizationRequest $request, ?User $actor): void
    {
        $this->send($request->employee->user_id, 'hrms.attendance.regularization.decided', [
            'regularization_request_id' => $request->id,
            'status' => $request->status->value,
            'work_date' => $request->work_date->toDateString(),
        ], $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(?int $userId, string $type, array $data, ?User $actor): void
    {
        if ($userId === null) {
            return;
        }

        $recipient = User::find($userId);

        if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
            return;
        }

        $this->notifications->notify($recipient, $type, $data, $actor);
    }
}
