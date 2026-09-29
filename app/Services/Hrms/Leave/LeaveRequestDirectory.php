<?php

namespace App\Services\Hrms\Leave;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Leave/HRMS — which asks a viewer may list.
 *
 * The set-level half of the policy question (the DocumentDirectoryQuery
 * precedent): per-record answers live in the policy, the list scope lives
 * here, and both read the same permissions so they cannot disagree. A
 * viewer with `hrms.leave.view` (or manage) sees every ask; anyone else
 * sees only their own employment record's.
 */
class LeaveRequestDirectory
{
    /**
     * @param  array{status?: string|null, employee_id?: int|null}  $filters
     * @return Collection<int, LeaveRequest>
     */
    public function listFor(User $viewer, array $filters = []): Collection
    {
        $query = LeaveRequest::query()
            ->with(['employee:id,name,employee_code', 'type:id,name,code', 'approval:id,status,current_step'])
            ->orderByDesc('id');

        if (! $this->mayReviewAll($viewer)) {
            $employeeId = Employee::where('user_id', $viewer->id)->value('id');

            $query->where('employee_id', $employeeId ?? -1);
        } elseif (isset($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    public function mayReviewAll(User $viewer): bool
    {
        return $viewer->hasPermission('hrms.leave.view')
            || $viewer->hasPermission('hrms.leave.manage');
    }
}
