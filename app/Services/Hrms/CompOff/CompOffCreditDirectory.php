<?php

namespace App\Services\Hrms\CompOff;

use App\Models\Hrms\CompOff\CompOffCredit;
use App\Models\Hrms\Employee\Employee;
use Illuminate\Support\Collection;

/**
 * CompOff/HRMS — whose bank a viewer may list.
 *
 * The set-level half of the credit policy (the DocumentDirectoryQuery
 * precedent): the viewer's own bank, or anyone's for a viewer with
 * `comp_off.view`. Ranges are optional bounds, not pages — banks are
 * small, and a paginator on a dozen rows is chrome.
 */
class CompOffCreditDirectory
{
    /**
     * @param  array{from?: string|null, to?: string|null}  $filters
     * @return Collection<int, CompOffCredit>
     */
    public function listFor(Employee $employee, array $filters = []): Collection
    {
        $query = CompOffCredit::query()
            ->where('employee_id', $employee->id)
            ->orderBy('work_date')
            ->orderBy('id');

        if (isset($filters['from'])) {
            $query->whereDate('work_date', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->whereDate('work_date', '<=', $filters['to']);
        }

        return $query->get();
    }
}
