<?php

namespace App\Policies\Hrms\Performance;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\OneOnOne;
use App\Models\User;

/**
 * Performance/HRMS — who may read or hold a 1:1.
 *
 * Participants (either side of the conversation) or the view permission
 * to read; participants or manage to write. A 1:1 belongs to the two
 * people in the room — everyone else reads over a shoulder.
 */
class OneOnOnePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isParticipant($user, null) || $this->canView($user);
    }

    public function view(User $user, OneOnOne $oneOnOne): bool
    {
        return $this->isParticipant($user, $oneOnOne) || $this->canView($user);
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

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.performance.view')
            || $user->hasPermission('hrms.performance.manage');
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
