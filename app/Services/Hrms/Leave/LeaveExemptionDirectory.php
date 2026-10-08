<?php

namespace App\Services\Hrms\Leave;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveExemptionRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Leave/HRMS — which exemption asks a viewer may list.
 *
 * Stricter than the leave-ask queue by design: exemptions are statutory
 * trail, so the full list takes `hrms.leave.manage` while anyone else sees
 * only their own record — mirroring the policy, which answers the same
 * question per row.
 */
class LeaveExemptionDirectory
{
    /**
     * @param  array{status?: string|null, employee_id?: int|null}  $filters
     * @return Collection<int, LeaveExemptionRequest>
     */
    public function listFor(User $viewer, array $filters = []): Collection
    {
        $query = LeaveExemptionRequest::query()
            ->with(['employee:id,name,employee_code', 'type:id,name,code', 'approval:id,status,current_step'])
            ->orderByDesc('id');

        if (! $viewer->hasPermission('hrms.leave.manage')) {
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
}
