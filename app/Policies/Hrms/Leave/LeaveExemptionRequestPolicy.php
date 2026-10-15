<?php

namespace App\Policies\Hrms\Leave;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveExemptionRequest;
use App\Models\User;
use App\Services\Hrms\Shared\ApprovalService;

/**
 * Leave/HRMS — who may raise and decide exemption asks.
 *
 * Exemptions are HR-owned: anyone may raise their own, but the queue and
 * the verdicts take `hrms.leave.manage` — with the step's approver able to
 * decide through the chain like every other approval in the app.
 */
class LeaveExemptionRequestPolicy
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function viewAny(User $user): bool
    {
        return $this->hasEmployee($user) || $this->canManage($user);
    }

    public function view(User $user, LeaveExemptionRequest $request): bool
    {
        return $this->isSelf($user, $request->employee) || $this->canManage($user);
    }

    public function create(User $user, Employee $employee): bool
    {
        return $this->isSelf($user, $employee) || $this->canManage($user);
    }

    public function decide(User $user, LeaveExemptionRequest $request): bool
    {
        if ($request->status->isTerminal() || $request->approval === null) {
            return false;
        }

        // The step's approver, like every other approval in the app: a
        // manage permission opens the queue, never someone else's verdict.
        return $this->approvals->canAct($request->approval, $user);
    }

    private function canManage(User $user): bool
    {
        return $user->hasPermission('hrms.leave.manage');
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
