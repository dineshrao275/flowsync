<?php

namespace App\Policies\Hrms\Shared;

use App\Models\User;

/**
 * Shared/HRMS — who may read and edit approval chains (P2.4).
 *
 * One permission, `hrms.approvals.manage`. Deliberately NOT implied by the
 * R11 override (`hrms.approvals.override` acts on one approval; this rewrites
 * who approves everything), and nobody gets it by being an approver.
 */
class ApprovalTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hrms.approvals.manage');
    }

    public function update(User $user): bool
    {
        return $user->hasPermission('hrms.approvals.manage');
    }
}
