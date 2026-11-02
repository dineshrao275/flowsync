<?php

declare(strict_types=1);

namespace App\Policies\Hrms\Performance;

use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\User;
use App\Services\Hrms\HrmsScope;

/**
 * Performance/HRMS — who may see or move a cycle.
 *
 * Reads take any performance view scope (`_own`/`_assigned`/`_all`, or the legacy
 * `view`) or manage: a cycle is tenant-level metadata, and an employee must be able to
 * open the cycle their own goals live in. Which goals/reviews they then see is still
 * decided per record by their own policies. Every stage move takes
 * manage alone — opening rooms and sealing history are HR decisions, and
 * the service still refuses out-of-order moves underneath.
 */
class PerformanceCyclePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, PerformanceCycle $cycle): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.performance.manage');
    }

    public function transition(User $user, PerformanceCycle $cycle): bool
    {
        return $user->hasPermission('hrms.performance.manage');
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.performance.view')
            || HrmsScope::canRead($user, 'hrms.performance');
    }
}
