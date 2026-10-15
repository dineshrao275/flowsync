<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Hrms\HrmsScope;

/**
 * Whether a caller reads every performance row (view_all / legacy / manage)
 * or only their own scope. One definition for the goal, check-in, 1:1 and
 * feedback lists.
 */
trait ChecksPerformanceScope
{
    private function seesAll(User $user): bool
    {
        return HrmsScope::seesAll($user, 'hrms.performance')
            || $user->hasPermission('hrms.talent.manage');
    }
}
