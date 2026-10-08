<?php

namespace App\Policies\Hrms\Performance;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\OneOnOne;
use App\Models\User;
use App\Services\Hrms\HrmsScope;

/**
 * Performance/HRMS — who may read or hold a 1:1.
 *
 * Participants (either side of the conversation), the view scope
 * (`HrmsScope`, so a manager's `_assigned` reads their reports'
 * conversations), or a talent manager to read; participants or manage to
 * write. A 1:1 belongs to the two people in the room — everyone else
 * reads over a shoulder.
 */
class OneOnOnePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isParticipant($user, null) || $this->canRead($user);
    }

    public function view(User $user, OneOnOne $oneOnOne): bool
    {
        return $this->isParticipant($user, $oneOnOne)
            || $this->canViewScoped($user, $oneOnOne);
    }

    public function create(User $user): bool
    {
        return $this->isParticipant($user, null)
            || $user->hasPermission('hrms.performance.manage');
    }

    public function update(User $user, OneOnOne $oneOnOne): bool
    {
        return $this->isParticipant($user, $oneOnOne)
            || $user->hasPermission('hrms.performance.manage');
    }

    /**
     * Scope-aware read mirroring the list clamp: either side of the 1:1
     * falls inside the caller's scope, so an `_assigned` manager opens a
     * report's conversation and a stranger's conversation stays a 403.
     * Talent managers read everything.
     */
    private function canViewScoped(User $user, OneOnOne $oneOnOne): bool
    {
        if ($user->hasPermission('hrms.talent.manage')) {
            return true;
        }

        return HrmsScope::coversEmployee($user, 'hrms.performance', $oneOnOne->employee)
            || ($oneOnOne->manager_employee_id !== null
                && HrmsScope::coversEmployee($user, 'hrms.performance', $oneOnOne->manager));
    }

    private function canRead(User $user): bool
    {
        return HrmsScope::canRead($user, 'hrms.performance')
            || $user->hasPermission('hrms.talent.manage');
    }

    /**
     * A participant, or — with no record — anyone holding an employment
     * record to converse from. Listing and filing need a person behind
     * them; the per-record check names the two people in the room.
     */
    private function isParticipant(User $user, ?OneOnOne $oneOnOne): bool
    {
        $employeeId = Employee::where('user_id', $user->id)->value('id');

        if ($employeeId === null) {
            return false;
        }

        if ($oneOnOne === null) {
            return true;
        }

        return (int) $oneOnOne->employee_id === (int) $employeeId
            || ($oneOnOne->manager_employee_id !== null && (int) $oneOnOne->manager_employee_id === (int) $employeeId);
    }
}
