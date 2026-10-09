<?php

namespace App\Services\Hrms\Leave;

use App\Enums\Hrms\LeaveAdjustmentKind;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave/HRMS — a manager's signed correction to a balance.
 *
 * Append-only, like every ledger write: a correction is a new `adjustment` row
 * (never an edit of history) carrying a mandatory reason and the actor, then
 * the projection is rebuilt. A debit that would push a type that forbids
 * negative balances below zero is refused rather than silently floored by the
 * projection — the ledger must not claim a deduction the balance never showed.
 */
class LeaveManualAdjustment
{
    public const MAX_QUANTITY = 365.0;

    public function __construct(
        private readonly LeaveBalanceService $balances,
        private readonly HrmsAuditLogger $audit,
    ) {}

    public function adjust(Employee $employee, LeaveType $type, int $year, float $quantity, string $reason, ?User $actor = null): LeaveAdjustment
    {
        $quantity = round($quantity, 2);

        if ($quantity == 0.0 || abs($quantity) > self::MAX_QUANTITY) {
            throw ValidationException::withMessages(['quantity' => 'Enter a non-zero adjustment of at most '.(int) self::MAX_QUANTITY.' days.']);
        }

        return DB::transaction(function () use ($employee, $type, $year, $quantity, $reason, $actor): LeaveAdjustment {
            $current = (float) (LeaveBalance::query()
                ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $year)
                ->value('balance') ?? 0.0);

            if ($quantity < 0 && ! $type->allow_negative_balance && $current + $quantity < 0) {
                throw ValidationException::withMessages(['quantity' => "That would take {$type->name} below zero (balance {$current})."]);
            }

            $row = LeaveAdjustment::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'year' => $year,
                'kind' => LeaveAdjustmentKind::Adjustment->value,
                'quantity' => $quantity,
                'reference_type' => 'manual',
                'reference_id' => null,
                'note' => $reason,
                'actor_user_id' => $actor?->id,
                'created_at' => now(),
            ]);

            $balance = $this->balances->rebuildBalance($employee, $type, $year);

            $this->audit->log($row, 'leave.balance_adjusted', ['balance' => $current], [
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'year' => $year,
                'quantity' => $quantity,
                'balance' => (float) $balance->balance,
            ], $actor);

            return $row->refresh();
        });
    }

    /**
     * The ledger behind a balance, newest first.
     *
     * @return Collection<int, LeaveAdjustment>
     */
    public function ledger(Employee $employee, ?LeaveType $type, ?int $year): Collection
    {
        return LeaveAdjustment::query()
            ->where('employee_id', $employee->id)
            ->when($type !== null, fn ($q) => $q->where('leave_type_id', $type->id))
            ->when($year !== null, fn ($q) => $q->where('year', $year))
            ->with(['type', 'actor'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }
}
