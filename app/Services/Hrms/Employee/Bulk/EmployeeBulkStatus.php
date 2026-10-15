<?php

namespace App\Services\Hrms\Employee\Bulk;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\User;
use App\Services\Hrms\Employee\EmployeeService;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Employee/HRMS — change many people's employment status in one go.
 *
 * Two modes over the same evaluation: `dry_run` reports what *would* happen
 * per person (changes / already there / refused / not permitted) and writes
 * nothing; the real run applies each allowed change through
 * `EmployeeService::changeStatus()` so every person still gets a history row
 * and an audit row. Terminal states (exited, terminated) are refused here —
 * ending an employment has its own offboarding flow and exit gate and must be
 * decided per person, not as a side effect of a bulk edit.
 */
class EmployeeBulkStatus
{
    public const MAX_EMPLOYEES = 200;

    /** @var array<int, EmployeeStatus> */
    private const ALLOWED = [EmployeeStatus::Active, EmployeeStatus::Probation, EmployeeStatus::OnNotice, EmployeeStatus::Suspended];

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array<int, int>  $ids
     * @param  array{effective_date?: string|null, reason?: string|null, note?: string|null}  $options
     * @return array{dry_run: bool, changed: int, results: array<int, array<string, mixed>>}
     */
    public function run(array $ids, EmployeeStatus $to, array $options, bool $dryRun, User $actor): array
    {
        if (! in_array($to, self::ALLOWED, true)) {
            throw ValidationException::withMessages(['to' => 'Ending an employment is done per person through offboarding, not in bulk.']);
        }
        if (count($ids) > self::MAX_EMPLOYEES) {
            throw ValidationException::withMessages(['employee_ids' => 'Change at most '.self::MAX_EMPLOYEES.' people at a time.']);
        }

        $results = [];
        $changed = 0;

        foreach (Employee::query()->whereIn('id', $ids)->orderBy('id')->get() as $employee) {
            $outcome = $this->evaluate($employee, $to, $actor);

            if ($outcome === 'will_change' && ! $dryRun) {
                $this->employees->changeStatus($employee, $to, $options, $actor);
                $outcome = 'changed';
                $changed++;
            }

            $results[] = [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->displayName(),
                'from' => $employee->status->value,
                'outcome' => $outcome,
            ];
        }

        if (! $dryRun && $changed > 0) {
            $this->audit->log(HrmsSetting::current(), 'employee.bulk_status_changed', null, [
                'to' => $to->value,
                'count' => $changed,
                'employee_ids' => array_slice(array_column(array_filter($results, fn ($r) => $r['outcome'] === 'changed'), 'id'), 0, 100),
            ], $actor);
        }

        return ['dry_run' => $dryRun, 'changed' => $changed, 'results' => $results];
    }

    private function evaluate(Employee $employee, EmployeeStatus $to, User $actor): string
    {
        if (! Gate::forUser($actor)->allows('changeStatus', $employee)) {
            return 'not_permitted';
        }
        if (! $employee->status->isEmployed()) {
            return 'refused_left';
        }

        return $employee->status === $to ? 'unchanged' : 'will_change';
    }
}
