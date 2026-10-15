<?php

namespace App\Policies\Hrms\Performance;

use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\User;

/**
 * Performance/HRMS — who may see or move a cycle.
 *
 * Reads take the view permission (or manage); every stage move takes
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
            || $user->hasPermission('hrms.performance.manage');
    }
}
