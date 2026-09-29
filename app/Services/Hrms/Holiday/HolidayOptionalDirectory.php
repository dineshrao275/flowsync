<?php

namespace App\Services\Hrms\Holiday;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Holiday\HolidayOptionalHoliday;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Holiday/HRMS — whose optional answers a viewer may list.
 *
 * The set-level half of the answer policy: managers read the whole queue,
 * anyone else reads only their own record — both reading the same
 * permission the policy answers per row, so the two cannot disagree.
 */
class HolidayOptionalDirectory
{
    /**
     * @param  array{status?: string|null, employee_id?: int|null}  $filters
     * @return Collection<int, HolidayOptionalHoliday>
     */
    public function listFor(User $viewer, array $filters = []): Collection
    {
        $query = HolidayOptionalHoliday::query()
            ->with(['employee:id,name,employee_code', 'holiday:id,name,date'])
            ->orderByDesc('id');

        if (! $viewer->hasPermission('hrms.holidays.manage')) {
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
