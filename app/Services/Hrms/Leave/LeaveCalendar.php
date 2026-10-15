<?php

namespace App\Services\Hrms\Leave;

use App\Enums\Hrms\LeaveRequestStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeavePolicy;
use App\Models\Hrms\Leave\LeaveRequestDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Leave/HRMS — years, windows, and coverage.
 *
 * The calendar half of leave math, with no dependencies at all: years are
 * leave years (the default policy's `start_month` moves the boundary;
 * January when no policy exists), and coverage answers which dates an
 * approved ask holds. Both balance math and attendance reads share it, so a
 * boundary means one thing everywhere.
 */
class LeaveCalendar
{
    /**
     * The leave year a date belongs to under the default policy.
     */
    public function leaveYearFor(Carbon|string $date): int
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse((string) $date);
        $startMonth = LeavePolicy::query()->default()->value('start_month') ?? 1;

        return $date->month >= $startMonth ? $date->year : $date->year - 1;
    }

    /**
     * The calendar window a leave year covers under the default policy.
     *
     * @return array{Carbon, Carbon} Inclusive start and end.
     */
    public function leaveYearRange(int $year): array
    {
        $startMonth = LeavePolicy::query()->default()->value('start_month') ?? 1;
        $start = Carbon::create($year, $startMonth, 1)->startOfDay();

        return [$start, $start->copy()->addYear()->subDay()->startOfDay()];
    }

    /**
     * Charged approved-leave days in a window, split by whether the type
     * pays: paid days stay payable, unpaid days join loss-of-pay. Halves
     * count half — the engine sums fractions, not rows. Only charged rows
     * count (week-offs and holidays were never priced), and only approved
     * asks post — pending ones merely reserve.
     *
     * @return array{dates: list<string>, paid_days: float, unpaid_days: float}
     */
    public function chargedLeaveDays(Employee $employee, Carbon|string $from, Carbon|string $to): array
    {
        $from = $from instanceof Carbon ? $from->toDateString() : (string) $from;
        $to = $to instanceof Carbon ? $to->toDateString() : (string) $to;

        $rows = LeaveRequestDay::query()
            ->whereHas('request', fn (Builder $query) => $query
                ->where('employee_id', $employee->id)
                ->where('status', LeaveRequestStatus::Approved->value))
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->where('is_week_off', false)
            ->where('is_holiday', false)
            ->with('request.type:id,is_paid')
            ->orderBy('date')
            ->get();

        $dates = [];
        $paid = 0.0;
        $unpaid = 0.0;

        foreach ($rows as $row) {
            // The model carries casts, but the SQLite time part rides the
            // attribute either way — parse it off before comparing.
            $date = Carbon::parse((string) $row->date)->toDateString();
            $fraction = $row->is_half_day ? 0.5 : 1.0;

            $dates[] = $date;

            if ($row->request->type?->is_paid ?? true) {
                $paid += $fraction;
            } else {
                $unpaid += $fraction;
            }
        }

        return ['dates' => array_values(array_unique($dates)), 'paid_days' => round($paid, 2), 'unpaid_days' => round($unpaid, 2)];
    }

    /**
     * Dates in a window the employee holds under an approved ask: the
     * per-day split rows, excluding week-offs and holidays (those dates were
     * never charged, so they must never read as leave either).
     *
     * Cancelled and rejected asks vanish from the answer on their own — no
     * status filter to forget.
     *
     * @return list<string> Y-m-d dates
     */
    public function approvedLeaveDates(Employee $employee, Carbon|string $from, Carbon|string $to): array
    {
        $from = $from instanceof Carbon ? $from->toDateString() : (string) $from;
        $to = $to instanceof Carbon ? $to->toDateString() : (string) $to;

        // pluck() skips casts, so the SQLite time part is parsed off —
        // comparing raw strings would miss every row on the fast path.
        return LeaveRequestDay::query()
            ->whereHas('request', fn (Builder $query) => $query
                ->where('employee_id', $employee->id)
                ->where('status', LeaveRequestStatus::Approved->value))
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->where('is_week_off', false)
            ->where('is_holiday', false)
            ->pluck('date')
            ->map(fn ($date): string => Carbon::parse((string) ($date instanceof Carbon ? $date->toDateString() : $date))->toDateString())
            ->unique()
            ->values()
            ->all();
    }
}
