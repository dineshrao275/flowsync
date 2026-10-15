<?php

namespace App\Policies\Hrms\Shared;

use App\Models\Hrms\Shared\ApprovalDelegation;
use App\Models\User;

/**
 * Shared/HRMS — approval delegations (P2.4).
 *
 * Self-service: anyone may hand over (and take back) their OWN approvals.
 * Delegating someone else's seat, or revoking another person's delegation,
 * needs `hrms.approvals.manage`.
 */
class ApprovalDelegationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user, ?int $fromUserId = null): bool
    {
        return $fromUserId === null
            || (int) $fromUserId === (int) $user->id
            || $user->hasPermission('hrms.approvals.manage');
    }

    public function revoke(User $user, ApprovalDelegation $delegation): bool
    {
        return (int) $delegation->from_user_id === (int) $user->id
            || $user->hasPermission('hrms.approvals.manage');
    }

    public function viewAll(User $user): bool
    {
        return $user->hasPermission('hrms.approvals.manage');
    }
}
