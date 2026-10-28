<?php

declare(strict_types=1);

namespace App\Services\Hrms\Leave;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeaveType;

/**
 * Leave — finds (and optionally repairs) drift between the ledger and the
 * `leave_balances` projection.
 *
 * The ledger is the truth: a balance row that disagrees with the re-sum of its
 * ledger rows is wrong, and so is a ledger group with no balance row at all.
 * The expected values come from `LeaveBalanceService::project()` — the same
 * maths `rebuildBalance()` stores — so a repair always converges.
 */
class LeaveConsistency
{
    /** Columns compared; a difference below half a cent is rounding, not drift. */
    private const COLUMNS = ['opening', 'accrued', 'availed', 'encashed', 'lapsed', 'carried_forward', 'adjusted', 'balance'];

    public function __construct(private readonly LeaveBalanceService $balances) {}

    /**
     * @return array<int, array{employee_id: int, leave_type_id: int, year: int, kind: string, fields: array<string, array{stored: float, expected: float}>}>
     */
    public function scan(): array
    {
        $drift = [];

        $groups = LeaveAdjustment::query()
            ->select('employee_id', 'leave_type_id', 'year')
            ->distinct()
            ->get()
            ->map(fn ($g) => [(int) $g->employee_id, (int) $g->leave_type_id, (int) $g->year]);

        // Balance rows whose ledger is empty are drift too (everything should be 0).
        $balanceOnly = LeaveBalance::query()
            ->select('employee_id', 'leave_type_id', 'year')
            ->get()
            ->map(fn ($b) => [(int) $b->employee_id, (int) $b->leave_type_id, (int) $b->year]);

        $seen = [];
        $types = LeaveType::query()->get()->keyBy('id');

        foreach ($groups->concat($balanceOnly) as [$employeeId, $typeId, $year]) {
            $key = "$employeeId:$typeId:$year";
            if (isset($seen[$key]) || ! $types->has($typeId)) {
                continue;
            }
            $seen[$key] = true;

            $expected = $this->balances->project($employeeId, $types[$typeId], $year);
            $stored = LeaveBalance::query()
                ->where('employee_id', $employeeId)->where('leave_type_id', $typeId)->where('year', $year)
                ->first();

            if ($stored === null) {
                if (abs($expected['balance']) >= 0.005 || array_sum(array_map('abs', $expected)) >= 0.005) {
                    $drift[] = [
                        'employee_id' => $employeeId, 'leave_type_id' => $typeId, 'year' => $year,
                        'kind' => 'missing_balance',
                        'fields' => ['balance' => ['stored' => 0.0, 'expected' => $expected['balance']]],
                    ];
                }

                continue;
            }

            $fields = [];
            foreach (self::COLUMNS as $column) {
                $have = round((float) $stored->{$column}, 2);
                if (abs($have - $expected[$column]) >= 0.005) {
                    $fields[$column] = ['stored' => $have, 'expected' => $expected[$column]];
                }
            }

            if ($fields !== []) {
                $drift[] = [
                    'employee_id' => $employeeId, 'leave_type_id' => $typeId, 'year' => $year,
                    'kind' => 'drift', 'fields' => $fields,
                ];
            }
        }

        return $drift;
    }

    /**
     * Rewrite every drifted projection from the ledger. Returns how many rows
     * were rebuilt.
     *
     * @param  array<int, array{employee_id: int, leave_type_id: int, year: int}>  $drift
     */
    public function repair(array $drift): int
    {
        $types = LeaveType::query()->get()->keyBy('id');
        $rebuilt = 0;

        foreach ($drift as $row) {
            $employee = Employee::query()->find($row['employee_id']);

            if ($employee === null || ! $types->has($row['leave_type_id'])) {
                continue;
            }

            $this->balances->rebuildBalance($employee, $types[$row['leave_type_id']], $row['year']);
            $rebuilt++;
        }

        return $rebuilt;
    }
}
