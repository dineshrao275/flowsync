<?php

namespace App\Services\Hrms\CompOff;

use App\Models\Hrms\CompOff\CompOffRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * CompOff/HRMS — which redemption asks a viewer may list.
 *
 * The set-level half of the ask policy: a viewer with `comp_off.view` (or
 * manage) sees every ask, anyone else sees only their own employment
 * record's — both reading the same permissions the policy answers per
 * row, so the two cannot disagree.
 */
class CompOffRequestDirectory
{
    /**
     * @param  array{status?: string|null, employee_id?: int|null}  $filters
     * @return Collection<int, CompOffRequest>
     */
    public function listFor(User $viewer, array $filters = []): Collection
    {
        $query = CompOffRequest::query()
            ->with(['employee:id,name,employee_code', 'approval:id,status,current_step'])
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
        return $viewer->hasPermission('hrms.comp_off.view')
            || $viewer->hasPermission('hrms.comp_off.manage');
    }
}
