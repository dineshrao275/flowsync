<?php

namespace App\Services\Hrms\Leave;

use App\Enums\Hrms\LeaveHalf;
use App\Enums\Hrms\LeaveRequestStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Leave\LeaveType;
use App\Services\Hrms\AttendanceService;
use App\Services\Hrms\Holiday\HolidayService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Leave/HRMS — is this ask well-formed and affordable.
 *
 * Its own class because validation is a self-contained decision (five rules,
 * two cross-context reads) and the lifecycle service that consumes it would
 * otherwise pass the 300-line ceiling on validation alone. One entry point,
 * `validate()`, returns the boundary DTO the transaction body stores — a
 * rule that fails raises before anything is written.
 */
class LeaveRequestValidation
{
    public function __construct(
        private readonly LeaveBalanceReading $reading,
        private readonly AttendanceService $attendance,
        private readonly LeaveCalendar $calendar,
        private readonly HolidayService $holidays,
    ) {}

    /**
     * @param  array{leave_type_id: int, from_date: string, to_date: string, from_half?: string, to_half?: string, reason: string, contact_during_leave?: string|null, document_id?: int|null}  $data
     *
     * @throws ValidationException on an inactive type, a bad window, quota breach, overlap, or shortfall
     */
    public function validate(Employee $employee, array $data): LeaveRequestInput
    {
        $type = $this->resolveType((int) $data['leave_type_id']);

        $from = Carbon::parse((string) $data['from_date'])->startOfDay();
        $to = Carbon::parse((string) $data['to_date'])->startOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['to_date' => 'The range ends before it starts.']);
        }

        [$total, $split] = $this->splitDays($employee, $type, $from, $to, $data);

        if ($total <= 0) {
            throw ValidationException::withMessages(['form' => 'The range holds no chargeable day — only week-offs and holidays.']);
        }

        $this->checkQuota($type, $total, $from, $employee);
        $this->checkOverlap($employee, $from, $to);
        $this->checkBalance($employee, $type, $total, $from, $to);

        return new LeaveRequestInput(
            type: $type,
            from: $from,
            to: $to,
            total: $total,
            split: $split,
            reason: trim((string) $data['reason']),
            contact: isset($data['contact_during_leave']) ? (string) $data['contact_during_leave'] : null,
            documentId: isset($data['document_id']) ? (int) $data['document_id'] : null,
            fromHalf: (string) ($data['from_half'] ?? 'full'),
            toHalf: (string) ($data['to_half'] ?? 'full'),
        );
    }

    /**
     * The charged total for a range without any affordability judgment.
     *
     * The availability preview's numerator: same split math as validation
     * (halves, week-offs), none of its verdicts. Throws on malformed shape
     * (unknown type, backward range, bad halves) but never on quotas,
     * overlaps, or shortfalls — a preview that 422s on an empty balance
     * could never warn about it.
     *
     * @throws ValidationException on an unknown type or a bad window
     */
    public function previewTotal(
        Employee $employee,
        int $typeId,
        Carbon|string $from,
        Carbon|string $to,
        ?string $fromHalf = null,
        ?string $toHalf = null,
    ): float {
        $type = $this->resolveType($typeId);

        $from = $from instanceof Carbon ? $from->copy()->startOfDay() : Carbon::parse((string) $from)->startOfDay();
        $to = $to instanceof Carbon ? $to->copy()->startOfDay() : Carbon::parse((string) $to)->startOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['to_date' => 'The range ends before it starts.']);
        }

        [$total] = $this->splitDays($employee, $type, $from, $to, [
            'from_half' => $fromHalf,
            'to_half' => $toHalf,
        ]);

        return $total;
    }

    /** @throws ValidationException on an unknown or inactive type */
    private function resolveType(int $typeId): LeaveType
    {
        $type = LeaveType::find($typeId);

        if ($type === null) {
            throw ValidationException::withMessages(['leave_type_id' => 'That leave type does not exist.']);
        }

        if (! $type->is_active) {
            throw ValidationException::withMessages(['leave_type_id' => 'That leave type is not active.']);
        }

        return $type;
    }

    /**
     * Split a range into per-day rows and the charged total.
     *
     * Week-offs and holidays stay in the table flagged and out of the total
     * — the record keeps the whole range, the balance sees only worked
     * days. Endpoint halves apply only to charged days; a half on a
     * week-off is moot, not an error.
     *
     * @param  array{from_half?: string, to_half?: string}  $data
     * @return array{float, list<array{date: string, is_holiday: bool, is_week_off: bool, is_half_day: bool}>}
     *
     * @throws ValidationException on an unknown half or a disallowed half day
     */
    private function splitDays(Employee $employee, LeaveType $type, Carbon $from, Carbon $to, array $data): array
    {
        $fromHalf = LeaveHalf::tryFrom((string) ($data['from_half'] ?? 'full'));
        $toHalf = LeaveHalf::tryFrom((string) ($data['to_half'] ?? 'full'));

        if ($fromHalf === null || $toHalf === null) {
            throw ValidationException::withMessages(['from_half' => 'A day is full, first_half, or second_half.']);
        }

        if (($fromHalf !== LeaveHalf::Full || $toHalf !== LeaveHalf::Full) && ! $type->allow_half_day) {
            throw ValidationException::withMessages(['from_half' => 'This leave type does not allow half days.']);
        }

        // One range query for the window: the holiday branch of the single
        // source of truth, read once instead of per date.
        $holidayDates = array_flip($this->holidays->holidayDates($employee, $from, $to));

        $total = 0.0;
        $rows = [];

        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            $weekOff = $this->attendance->isWeekOff($employee, $date);
            $holiday = isset($holidayDates[$date->toDateString()]);
            $half = $date->equalTo($from) ? $fromHalf : ($date->equalTo($to) ? $toHalf : LeaveHalf::Full);
            $charged = ! $weekOff && ! $holiday;
            $fraction = $charged ? $half->days() : 0.0;

            $total += $fraction;
            $rows[] = [
                'date' => $date->toDateString(),
                'is_holiday' => $holiday,
                'is_week_off' => $weekOff,
                'is_half_day' => $charged && $fraction < 1,
            ];
        }

        return [round($total, 2), $rows];
    }

    /**
     * @throws ValidationException under the minimum or over the yearly maximum
     */
    private function checkQuota(LeaveType $type, float $total, Carbon $from, Employee $employee): void
    {
        if ($type->min_days_per_request !== null && $total < (float) $type->min_days_per_request) {
            throw ValidationException::withMessages([
                'form' => "This type asks for at least {$type->min_days_per_request} days per request.",
            ]);
        }

        if ($type->max_days_per_year === null) {
            return;
        }

        [$start, $end] = $this->calendar->leaveYearRange($this->calendar->leaveYearFor($from));

        // whereDate pairs, not whereBetween (the P5.2 SQLite trap).
        $used = (float) LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->whereIn('status', [LeaveRequestStatus::Submitted->value, LeaveRequestStatus::Pending->value, LeaveRequestStatus::Approved->value])
            ->whereDate('from_date', '<=', $end->toDateString())
            ->whereDate('to_date', '>=', $start->toDateString())
            ->sum('total_days');

        if ($used + $total > (float) $type->max_days_per_year) {
            throw ValidationException::withMessages([
                'form' => "This type allows {$type->max_days_per_year} days a year; {$used} are already taken or held.",
            ]);
        }
    }

    /**
     * @throws ValidationException on any live ask over the window, whatever its type
     */
    private function checkOverlap(Employee $employee, Carbon $from, Carbon $to): void
    {
        $clash = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [
                LeaveRequestStatus::Submitted->value,
                LeaveRequestStatus::Pending->value,
                LeaveRequestStatus::Approved->value,
            ])
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages(['form' => 'An ask already covers part of this range.']);
        }
    }

    /**
     * @throws ValidationException with the shortfall spelled out
     */
    private function checkBalance(Employee $employee, LeaveType $type, float $total, Carbon $from, Carbon $to): void
    {
        if ($type->allow_negative_balance) {
            return;
        }

        $available = $this->reading->availableDays($employee, $type, $from, $to);

        if ($available < $total) {
            throw ValidationException::withMessages([
                'form' => 'Available '.number_format($available, 2).' days, short by '.number_format(round($total - $available, 2), 2).'.',
            ]);
        }
    }
}
