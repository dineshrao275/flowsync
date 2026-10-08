<?php

namespace App\Policies\Hrms\Attendance;

use App\Models\Hrms\Attendance\AttendanceRegularizationRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\Shared\ApprovalService;

/**
 * Attendance/HRMS — who may ask for, read, and decide corrections.
 *
 * Self-service by design (D2.12): any employee may request a correction for
 * their own record and read their own asks with no attendance permission —
 * the manager step on the approval chain is what stands between an ask and
 * a rewritten day. Reviewing the queue takes `hrms.attendance.regularize`
 * (or manage); deciding takes being the step's approver, which the engine
 * answers — a manager with no HR permission can still decide their reports'
 * asks, and a permission holder who is not the approver cannot.
 */
class AttendanceRegularizationRequestPolicy
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function viewAny(User $user): bool
    {
        return $this->canReview($user) || $this->hasEmployee($user);
    }

    public function view(User $user, AttendanceRegularizationRequest $request): bool
    {
        return $this->isSelf($user, $request->employee) || $this->canReview($user);
    }

    public function create(User $user): bool
    {
        return $this->hasEmployee($user);
    }

    public function approve(User $user, AttendanceRegularizationRequest $request): bool
    {
        return $this->canDecide($user, $request);
    }

    public function reject(User $user, AttendanceRegularizationRequest $request): bool
    {
        return $this->canDecide($user, $request);
    }

    private function canDecide(User $user, AttendanceRegularizationRequest $request): bool
    {
        if (! $request->isOpen() || $request->approval === null) {
            return false;
        }

        return $this->approvals->canAct($request->approval, $user);
    }

    private function canReview(User $user): bool
    {
        return $user->hasPermission('hrms.attendance.regularize')
            || $user->hasPermission('hrms.attendance.manage');
    }

    private function hasEmployee(User $user): bool
    {
        return Employee::where('user_id', $user->id)->exists();
    }

    private function isSelf(User $user, ?Employee $employee): bool
    {
        return $employee !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === (int) $user->id;
    }
}
