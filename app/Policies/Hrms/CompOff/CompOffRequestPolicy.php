<?php

namespace App\Policies\Hrms\CompOff;

use App\Models\Hrms\CompOff\CompOffRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\HrmsScope;
use App\Services\Hrms\Shared\ApprovalService;

/**
 * CompOff/HRMS — who may file, read, and decide redemption asks.
 *
 * Named for its model (convention discovery binds by name — the
 * LeaveExemptionPolicy silent-deny lesson): a single `CompOffPolicy`
 * would be discoverable by nothing. Filing and reading one's own asks is
 * self-service; reading another's follows the caller's `view` scope; deciding
 * belongs to the step's approver; withdrawing is the requester's hand or a
 * manager's.
 */
class CompOffRequestPolicy
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function viewAny(User $user): bool
    {
        return $this->hasEmployee($user) || $this->canRead($user);
    }

    public function view(User $user, CompOffRequest $request): bool
    {
        return HrmsScope::coversEmployee($user, 'hrms.comp_off', $request->employee);
    }

    public function create(User $user, Employee $employee): bool
    {
        return $this->isSelf($user, $employee) || $user->hasPermission('hrms.comp_off.manage');
    }

    public function delete(User $user, CompOffRequest $request): bool
    {
        return $this->isSelf($user, $request->employee) || $user->hasPermission('hrms.comp_off.manage');
    }

    public function approve(User $user, CompOffRequest $request): bool
    {
        return $this->canDecide($user, $request);
    }

    public function reject(User $user, CompOffRequest $request): bool
    {
        return $this->canDecide($user, $request);
    }

    private function canDecide(User $user, CompOffRequest $request): bool
    {
        if ($request->status->isTerminal() || $request->approval === null) {
            return false;
        }

        return $this->approvals->canAct($request->approval, $user);
    }

    private function canRead(User $user): bool
    {
        return HrmsScope::canRead($user, 'hrms.comp_off');
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
