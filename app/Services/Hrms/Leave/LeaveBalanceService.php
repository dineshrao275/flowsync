<?php

namespace App\Services\Hrms\Leave;

use App\Enums\Hrms\LeaveAccrualMethod;
use App\Enums\Hrms\LeaveAdjustmentKind;
use App\Enums\Hrms\LeaveRequestStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Leave/HRMS — accruals, balances, and availability.
 *
 * The money half of leave (the request lifecycle lives in
 * `LeaveRequestService`, the P5 split rule: the file that would pass 300
 * lines gets a bounded context). Three rules own this file:
 *
 * - **The ledger is the truth; the balance is its projection.**
 *   `rebuildBalance()` re-sums the ledger into `leave_balances`, so the
 *   projection can never drift from the rows it summarizes.
 * - **Accruals are idempotent per period.** A scheduled run that crashes
 *   halfway is re-run, not repaired — the period key below makes the second
 *   pass a no-op instead of a double credit.
 * - **Years are leave years.** The default policy's `start_month` moves the
 *   boundary (April in India); nothing here reads the calendar year.
 */
class LeaveBalanceService
{
    public function __construct(
        private readonly HrmsAuditLogger $audit,
        private readonly LeaveCalendar $calendar,
    ) {}

    /**
     * Credit one period's accrual, or return the existing row when this
     * period already closed.
     *
     * `accrual_rate` means "days per period of the type's method": 30 for an
     * annual type, 1.5 for a monthly one. Amounts round to cents at the
     * write boundary — the ledger is decimal(6,2) while rates carry three
     * places, and an unrounded float would drift the projection.
     *
     * @return LeaveAdjustment|null Null when the type never auto-accrues
     *                              (`none`) or accrues through payroll
     *                              (`per_payroll`, credited by the P9 run).
     *
     * @throws ValidationException when `$asOf` falls outside `$year`'s leave year
     */
    public function accrue(Employee $employee, LeaveType $type, int $year, Carbon $asOf, ?User $actor = null): ?LeaveAdjustment
    {
        $method = $type->accrual_method;

        if (! $method->isScheduled()) {
            Log::channel('hrms')->debug('leave.accrual.skipped', [
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'method' => $method->value,
            ]);

            return null;
        }

        if ($this->calendar->leaveYearFor($asOf) !== $year) {
            throw ValidationException::withMessages([
                'year' => "The date falls in leave year {$this->calendar->leaveYearFor($asOf)}, not {$year}.",
            ]);
        }

        $key = $this->periodKey($method, $year, $asOf);

        $existing = LeaveAdjustment::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('kind', LeaveAdjustmentKind::Accrual->value)
            ->where('reference_type', 'accrual')
            ->where('reference_id', $key)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $amount = round((float) $type->accrual_rate, 2);

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($employee, $type, $year, $asOf, $actor, $key, $amount): LeaveAdjustment {
            $row = LeaveAdjustment::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'year' => $year,
                'kind' => LeaveAdjustmentKind::Accrual->value,
                'quantity' => $amount,
                'reference_type' => 'accrual',
                'reference_id' => $key,
                'note' => "Accrual for {$this->periodLabel($type->accrual_method, $year, $asOf)}.",
                'actor_user_id' => $actor?->id,
                'created_at' => now(),
            ]);

            $this->rebuildBalance($employee, $type, $year);
            $this->audit->log($row, 'leave.accrued', null, $this->snapshot($row), $actor);

            return $row->refresh();
        });
    }

    /**
     * Re-sum the ledger into the projection row.
     *
     * Per-kind columns keep their family's signed sum; `balance` is the
     * total, capped at `max_balance` and floored at zero unless the type
     * allows negative. The cap touches only `balance` — the columns stay
     * raw, which is what keeps a capped balance answerable.
     */
    public function rebuildBalance(Employee $employee, LeaveType $type, int $year): LeaveBalance
    {
        $rows = LeaveAdjustment::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->get();

        $columns = [
            'opening' => 0.0,
            'accrued' => 0.0,
            'availed' => 0.0,
            'encashed' => 0.0,
            'lapsed' => 0.0,
            'carried_forward' => 0.0,
            'adjusted' => 0.0,
        ];

        foreach ($rows as $row) {
            $columns[$row->kind->balanceColumn()] += (float) $row->quantity;
        }

        $balance = round(array_sum($columns), 2);

        if ($type->max_balance !== null) {
            $balance = min($balance, (float) $type->max_balance);
        }

        if (! $type->allow_negative_balance) {
            $balance = max(0.0, $balance);
        }

        return LeaveBalance::query()->updateOrCreate(
            ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
            [
                'opening' => round($columns['opening'], 2),
                'accrued' => round($columns['accrued'], 2),
                'availed' => round($columns['availed'], 2),
                'encashed' => round($columns['encashed'], 2),
                'lapsed' => round($columns['lapsed'], 2),
                'carried_forward' => round($columns['carried_forward'], 2),
                'adjusted' => round($columns['adjusted'], 2),
                'balance' => $balance,
            ],
        );
    }

    /**
     * Days free for a new ask in a window: the stored balance minus asks
     * already holding some of it.
     *
     * Only `submitted`/`pending` asks reserve — approved ones already posted
     * their `availed` row into the balance, and counting them again would
     * charge twice. A missing projection row is rebuilt on the fly so a
     * reader never sees a stale zero.
     */
    public function availableDays(Employee $employee, LeaveType $type, Carbon|string $from, Carbon|string $to): float
    {
        $from = $from instanceof Carbon ? $from->copy()->startOfDay() : Carbon::parse((string) $from)->startOfDay();
        $to = $to instanceof Carbon ? $to->copy()->startOfDay() : Carbon::parse((string) $to)->startOfDay();

        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $this->calendar->leaveYearFor($from))
            ->first()
            ?? $this->rebuildBalance($employee, $type, $this->calendar->leaveYearFor($from));

        // whereDate pairs, not whereBetween: a 'Y-m-d' upper bound
        // string-compares below the stored 'Y-m-d H:i:s' value on SQLite and
        // silently drops the window's last day (the P5.2 trap).
        $reserved = (float) $this->openRequests($employee, $type)
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->sum('total_days');

        return round((float) $balance->balance - $reserved, 2);
    }

    /**
     * Asks currently holding balance for an employee and type.
     *
     * @return Builder<LeaveRequest>
     */
    private function openRequests(Employee $employee, LeaveType $type): Builder
    {
        return LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->whereIn('status', [LeaveRequestStatus::Submitted->value, LeaveRequestStatus::Pending->value]);
    }

    /**
     * The idempotency key for one accrual period, encoded in the ledger's
     * `reference_id` (an integer by schema): calendar year alone for annual
     * types, YYYYMM for monthly, YYYYQ for quarterly.
     */
    private function periodKey(LeaveAccrualMethod $method, int $year, Carbon $asOf): int
    {
        return match ($method) {
            LeaveAccrualMethod::Annual => $year,
            LeaveAccrualMethod::Monthly => $year * 100 + $asOf->month,
            LeaveAccrualMethod::Quarterly => $year * 10 + $asOf->quarter,
            LeaveAccrualMethod::None, LeaveAccrualMethod::PerPayroll => 0,
        };
    }

    private function periodLabel(LeaveAccrualMethod $method, int $year, Carbon $asOf): string
    {
        return match ($method) {
            LeaveAccrualMethod::Annual => (string) $year,
            LeaveAccrualMethod::Monthly => $asOf->format('Y-m'),
            LeaveAccrualMethod::Quarterly => $year.'-Q'.$asOf->quarter,
            LeaveAccrualMethod::None, LeaveAccrualMethod::PerPayroll => (string) $year,
        };
    }

    /** @return array<string, mixed> */
    private function snapshot(LeaveAdjustment $row): array
    {
        return [
            'employee_id' => $row->employee_id,
            'leave_type_id' => $row->leave_type_id,
            'year' => $row->year,
            'quantity' => (float) $row->quantity,
        ];
    }
}
