<?php

namespace App\Services\Hrms\Leave;

use App\Enums\Hrms\LeaveRequestStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Leave\LeaveType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Leave/HRMS — reading balances back out.
 *
 * The read half to `LeaveBalanceService`'s writes (the DayComputation /
 * DayReading split): availability math and the per-employee projection
 * list. Reads never post — a reader that also writes is how two code paths
 * disagree about a balance.
 */
class LeaveBalanceReading
{
    public function __construct(
        private readonly LeaveCalendar $calendar,
        private readonly LeaveBalanceService $balances,
    ) {}

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
            ->first();

        if ($balance === null) {
            $balance = $this->balances->rebuildBalance($employee, $type, $this->calendar->leaveYearFor($from));
        }

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
     * Every active type's projection for an employee and year, materializing
     * missing rows so a reader never sees a stale zero.
     *
     * Materializing means rebuilding, not zero-filling: ledger rows may
     * already exist (an approval posts before anyone opens the balances
     * tab), and a zero row over them would lie until the next rebuild.
     *
     * @return Collection<int, LeaveBalance>
     */
    public function balancesFor(Employee $employee, ?int $year = null): Collection
    {
        $year ??= $this->calendar->leaveYearFor(today());

        return LeaveType::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(function (LeaveType $type) use ($employee, $year): LeaveBalance {
                $exists = LeaveBalance::query()
                    ->where('employee_id', $employee->id)
                    ->where('leave_type_id', $type->id)
                    ->where('year', $year)
                    ->exists();

                if (! $exists) {
                    return $this->balances->rebuildBalance($employee, $type, $year)->load('type');
                }

                return LeaveBalance::query()
                    ->where('employee_id', $employee->id)
                    ->where('leave_type_id', $type->id)
                    ->where('year', $year)
                    ->firstOrFail()
                    ->load('type');
            });
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
}
