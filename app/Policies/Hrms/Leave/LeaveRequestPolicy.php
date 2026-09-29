<?php

namespace App\Policies\Hrms\Leave;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\User;
use App\Services\Hrms\Shared\ApprovalService;

/**
 * Leave/HRMS — who may file, read, and decide leave asks.
 *
 * Filing and reading one's own asks is self-service (D2.12): any employee
 * may ask, and managers read their reports through `hrms.leave.view`, not
 * through a second permission. Deciding belongs to the approval step's
 * approver — a manager with no leave permission decides their reports'
 * asks, and a permission holder who is not on the chain cannot (the
 * regularization shape). Cancellation is the requester's own hand while
 * the ask is live; HR stops someone else's ask by rejecting it.
 */
class LeaveRequestPolicy
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function viewAny(User $user): bool
    {
        return $this->hasEmployee($user) || $this->canView($user);
    }

    public function view(User $user, LeaveRequest $request): bool
    {
        return $this->isSelf($user, $request->employee) || $this->canView($user);
    }

    public function create(User $user, Employee $employee): bool
    {
        return $this->isSelf($user, $employee) || $user->hasPermission('hrms.leave.manage');
    }

    public function update(User $user, LeaveRequest $request): bool
    {
        return $this->isSelf($user, $request->employee) || $user->hasPermission('hrms.leave.manage');
    }

    public function delete(User $user, LeaveRequest $request): bool
    {
        return $this->isSelf($user, $request->employee) || $user->hasPermission('hrms.leave.manage');
    }

    public function approve(User $user, LeaveRequest $request): bool
    {
        return $this->canDecide($user, $request);
    }

    public function reject(User $user, LeaveRequest $request): bool
    {
        return $this->canDecide($user, $request);
    }

    private function canDecide(User $user, LeaveRequest $request): bool
    {
        if ($request->status->isTerminal() || $request->approval === null) {
            return false;
        }

        return $this->approvals->canAct($request->approval, $user);
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.leave.view')
            || $user->hasPermission('hrms.leave.manage');
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
