<?php

namespace App\Policies\Hrms\Org;

use App\Models\User;
use App\Policies\Hrms\Employee\EmployeePolicy;

/**
 * Org/HRMS — the shape every org record shares.
 *
 * One base for the three concrete policies because the answer is the same for
 * all of them and they differ only in the model. Three near-identical classes
 * is three places for a permission check to drift, and the drift is silent: a
 * department becomes readable by anybody with the roster permission while a
 * location does not, and nothing fails.
 *
 * Split from {@see EmployeePolicy} on purpose. That
 * one is broader than manage, because reading your own employment record is
 * the basis of self-service. An org record has no self-service answer — there
 * is no "my department" for a manager's chart to be about — so `view` and
 * `manage` are simply the two halves of one permission pair.
 */
abstract class OrgRecordPolicy
{
    /**
     * Any org reader.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hrms.org.view');
    }

    /**
     * Read one record.
     */
    public function view(User $user, mixed $record): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Create a new record.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.org.manage');
    }

    /**
     * Edit a record, including re-parenting and reordering.
     */
    public function update(User $user, mixed $record): bool
    {
        return $user->hasPermission('hrms.org.manage');
    }

    /**
     * Reorder a sibling list.
     *
     * Its own method rather than folded into `update`, because a reorder names
     * no record: it is a write over a *list*, and a policy that can only answer
     * per-record has no honest way to express it.
     */
    public function reorder(User $user): bool
    {
        return $user->hasPermission('hrms.org.manage');
    }

    /**
     * Remove or retire a record.
     */
    public function delete(User $user, mixed $record): bool
    {
        return $user->hasPermission('hrms.org.manage');
    }
}
