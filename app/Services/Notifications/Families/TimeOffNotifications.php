<?php

namespace App\Services\Notifications\Families;

use App\Models\Hrms\CompOff\CompOffRequest;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\BaseNotificationFamily;

/**
 * The `hrms.time_off` notification family (moved verbatim out of NotificationService, P1.6).
 */
class TimeOffNotifications extends BaseNotificationFamily
{
    public function family(): string
    {
        return 'hrms.time_off';
    }

    /**
     * Nudge whoever must act on a leave ask next: the current step's named
     * approver, or every holder of its role step. Skips the actor, mirroring
     * taskCommented — a manager filing for a report does not need a toast
     * about their own filing.
     *
     * @return list<UserNotification>
     */
    public function leaveRequested(LeaveRequest $request, ?User $actor = null): array
    {
        $step = $request->approval?->currentStepRecord();

        if ($step === null) {
            return [];
        }

        $recipientIds = $step->approver_user_id !== null
            ? [(int) $step->approver_user_id]
            : $this->usersWithRole((int) $step->approver_role_id);

        $sent = [];

        $recipients = $this->usersById($recipientIds);
        foreach ($recipientIds as $recipientId) {
            $recipient = $recipients->get((int) $recipientId);

            if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'hrms.leave.requested', $this->leavePayload($request), $actor);
        }

        return $sent;
    }

    /**
     * Tell the requester their ask was decided, with the transition named.
     * Skips the actor: a manager approving from the queue already watched
     * it happen, but the requester never does.
     */
    public function leaveDecided(LeaveRequest $request, string $fromStatus, ?User $actor = null): ?UserNotification
    {
        $userId = $request->employee?->user_id;

        if ($userId === null) {
            return null;
        }

        $recipient = User::find((int) $userId);

        if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
            return null;
        }

        $type = $request->status->value === 'approved' ? 'hrms.leave.approved' : 'hrms.leave.rejected';

        return $this->notify($recipient, $type, array_merge($this->leavePayload($request), [
            'from_status' => $fromStatus,
            'to_status' => $request->status->value,
        ]), $actor);
    }

    /**
     * Nudge whoever must act on a comp-off ask next: the current step's
     * named approver, or every holder of its role step. Skips the actor,
     * mirroring the leave nudge — a manager filing for a report does not
     * need a toast about their own filing.
     *
     * @return list<UserNotification>
     */
    public function compOffRequested(CompOffRequest $request, ?User $actor = null): array
    {
        $step = $request->approval?->currentStepRecord();

        if ($step === null) {
            return [];
        }

        $recipientIds = $step->approver_user_id !== null
            ? [(int) $step->approver_user_id]
            : $this->usersWithRole((int) $step->approver_role_id);

        $sent = [];

        $recipients = $this->usersById($recipientIds);
        foreach ($recipientIds as $recipientId) {
            $recipient = $recipients->get((int) $recipientId);

            if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'hrms.comp_off.requested', $this->compOffPayload($request), $actor);
        }

        return $sent;
    }

    /**
     * Tell the requester their comp-off ask was decided, with the
     * transition named. Skips the actor like the leave twin.
     */
    public function compOffDecided(CompOffRequest $request, string $fromStatus, ?User $actor = null): ?UserNotification
    {
        $userId = $request->employee?->user_id;

        if ($userId === null) {
            return null;
        }

        $recipient = User::find((int) $userId);

        if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
            return null;
        }

        $type = $request->status->value === 'approved' ? 'hrms.comp_off.approved' : 'hrms.comp_off.rejected';

        return $this->notify($recipient, $type, array_merge($this->compOffPayload($request), [
            'from_status' => $fromStatus,
            'to_status' => $request->status->value,
        ]), $actor);
    }

    /**
     * @return array<string, mixed>
     */
    private function leavePayload(LeaveRequest $request): array
    {
        return [
            'leave_request_id' => $request->id,
            'employee_id' => $request->employee_id,
            'employee_name' => $request->employee?->name,
            'leave_type_id' => $request->leave_type_id,
            'leave_type_name' => $request->type?->name,
            'from_date' => $request->from_date->toDateString(),
            'to_date' => $request->to_date->toDateString(),
            'total_days' => (float) $request->total_days,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compOffPayload(CompOffRequest $request): array
    {
        return [
            'comp_off_request_id' => $request->id,
            'employee_id' => $request->employee_id,
            'employee_name' => $request->employee?->name,
            'from_date' => $request->from_date->toDateString(),
            'to_date' => $request->to_date->toDateString(),
            'total_minutes' => $request->total_minutes,
        ];
    }
}
