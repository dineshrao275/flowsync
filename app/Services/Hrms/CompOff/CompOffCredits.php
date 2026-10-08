<?php

namespace App\Services\Hrms\CompOff;

use App\Enums\Hrms\CompOffSource;
use App\Models\Hrms\CompOff\CompOffCredit;
use App\Models\Hrms\CompOff\CompOffRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\User;
use App\Services\Hrms\AttendanceService;
use App\Services\Hrms\Holiday\HolidayService;
use App\Services\Hrms\Leave\LeaveCalendar;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * CompOff/HRMS — banking rest days and reading the balance back.
 *
 * The bank half of comp-off (redeeming lives in `CompOffService` — the P5
 * split rule). Credits are append-only rows; the balance is derived from
 * them, never stored, so cancellation restores by status alone with no
 * ledger repair to forget.
 */
class CompOffCredits
{
    /**
     * Minutes in one standard banked day, for credits and redemptions
     * alike: banking and spending stay fair without a shift lookup.
     */
    public const DAY_MINUTES = 480;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly LeaveCalendar $leave,
        private readonly HolidayService $holidays,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * Bank every qualifying rest day in a window. Idempotent per
     * (employee, date, source): a rerun returns the existing rows instead
     * of double-crediting. Dry runs walk the same loop and count, writing
     * nothing — the estimate and the run can never disagree about what
     * qualifies.
     *
     * @return array{credited: int, skipped: int}
     */
    public function creditFromCalendar(Employee $employee, Carbon|string $from, Carbon|string $to, ?User $actor = null, bool $dryRun = false): array
    {
        $from = $from instanceof Carbon ? $from->copy()->startOfDay() : Carbon::parse((string) $from)->startOfDay();
        $to = $to instanceof Carbon ? $to->copy()->startOfDay() : Carbon::parse((string) $to)->startOfDay();

        $leaveDates = array_flip($this->leave->approvedLeaveDates($employee, $from, $to));
        $credited = 0;
        $skipped = 0;

        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            $day = $date->toDateString();

            // Presence and leave both disqualify: worked time stays in
            // attendance, leave days were never worked.
            if (isset($leaveDates[$day]) || $this->attendance->hasPunches($employee, $date)) {
                $skipped++;

                continue;
            }

            $source = $this->creditSource($employee, $day);

            if ($source === null) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $credited++;

                continue;
            }

            if ($this->firstCredit($employee, $day, $source, self::DAY_MINUTES, $actor, "Rest-day credit for {$day}.")->wasRecentlyCreated) {
                $credited++;
            } else {
                $skipped++;
            }
        }

        return ['credited' => $credited, 'skipped' => $skipped];
    }

    /**
     * Bank a declared grant: event days, policy specials, manual top-ups.
     *
     * @throws ValidationException on non-positive minutes, a future date, or a duplicate grant
     */
    public function creditManual(
        Employee $employee,
        Carbon|string $date,
        int $minutes,
        CompOffSource $source = CompOffSource::Manual,
        ?string $note = null,
        ?User $actor = null,
    ): CompOffCredit {
        $day = $date instanceof Carbon ? $date->toDateString() : (string) $date;

        if ($minutes <= 0) {
            throw ValidationException::withMessages(['minutes' => 'A credit banks positive minutes.']);
        }

        if ($day > today()->toDateString()) {
            throw ValidationException::withMessages(['work_date' => 'Rest time cannot be banked before it is worked.']);
        }

        $exists = CompOffCredit::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $day)
            ->where('source_type', $source->value)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['form' => 'This date already holds a credit from this source.']);
        }

        $credit = $this->firstCredit($employee, $day, $source, $minutes, $actor, $note);

        $this->audit->log($credit, 'comp_off.credited', null, $this->snapshot($credit), $actor);

        return $credit->refresh();
    }

    /**
     * Spendable minutes as of a date: unexpired credits minus approved
     * redemptions. Pending asks reserve through availability, not here —
     * the balance answers what is banked, availability answers what is
     * free.
     */
    public function balance(Employee $employee, Carbon|string|null $asOf = null): int
    {
        $day = $this->asOf($asOf);

        $credits = (int) CompOffCredit::query()
            ->where('employee_id', $employee->id)
            ->where(fn ($query) => $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $day))
            ->sum('minutes');

        $spent = (int) CompOffRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->sum('total_minutes');

        return $credits - $spent;
    }

    /**
     * Minutes lost to expiry as of a date — the "use it or lose it" figure.
     */
    public function expiredBalance(Employee $employee, Carbon|string|null $asOf = null): int
    {
        $day = $this->asOf($asOf);

        return (int) CompOffCredit::query()
            ->where('employee_id', $employee->id)
            ->whereDate('expiry_date', '<', $day)
            ->sum('minutes');
    }

    /**
     * Minutes expiring within the coming window — the P7.4 expiring-soon
     * panel reads this, not the ledger.
     */
    public function expiringBalance(Employee $employee, Carbon|string|null $asOf = null, int $days = 30): int
    {
        $day = $this->asOf($asOf);
        $horizon = Carbon::parse($day)->addDays(max(1, $days))->toDateString();

        return (int) CompOffCredit::query()
            ->where('employee_id', $employee->id)
            ->whereDate('expiry_date', '>=', $day)
            ->whereDate('expiry_date', '<=', $horizon)
            ->sum('minutes');
    }

    /**
     * Chargeable dates in a range: working days only — week-offs and
     * holidays stay out, so redeeming on a closed office is impossible.
     * Shared by redemption pricing so both sides of the bank agree on
     * what a range is worth.
     *
     * @return list<string> Y-m-d dates
     */
    public function chargeableDays(Employee $employee, Carbon|string $from, Carbon|string $to): array
    {
        $from = $from instanceof Carbon ? $from->copy()->startOfDay() : Carbon::parse((string) $from)->startOfDay();
        $to = $to instanceof Carbon ? $to->copy()->startOfDay() : Carbon::parse((string) $to)->startOfDay();

        $holidayDates = array_flip($this->holidays->holidayDates($employee, $from, $to));

        $dates = [];

        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            if (! $this->attendance->isWeekOff($employee, $date) && ! isset($holidayDates[$date->toDateString()])) {
                $dates[] = $date->toDateString();
            }
        }

        return $dates;
    }

    /**
     * Which rest source a date banks, if any: week-offs per the roster when
     * the tenant banks weekends, holidays per the calendars when it banks
     * those — the P8 branch, live since the calendars landed.
     */
    private function creditSource(Employee $employee, string $day): ?CompOffSource
    {
        $settings = HrmsSetting::current();

        if ($this->attendance->isWeekOff($employee, $day) && $settings->setting('comp_off.from_weekends', true)) {
            return CompOffSource::Weekend;
        }

        if ($this->holidays->isHoliday($employee, $day) && $settings->setting('comp_off.from_holidays', true)) {
            return CompOffSource::Holiday;
        }

        return null;
    }

    /**
     * Insert-or-return the credit row: reruns find, manual grants refuse
     * earlier (duplicates there are user error, not idempotency).
     *
     * An explicit find-then-create, never `firstOrCreate`: the `date` cast
     * serializes with a time part on SQLite's typeless columns, so the
     * finder's exact-string match would miss the row it just wrote and the
     * unique triple would 500 the second pass (the P5.2 trap). The unique
     * index backstops true races.
     */
    private function firstCredit(
        Employee $employee,
        string $day,
        CompOffSource $source,
        int $minutes,
        ?User $actor,
        ?string $note,
    ): CompOffCredit {
        $validity = HrmsSetting::current()->setting('comp_off.validity_months', 3);

        $existing = CompOffCredit::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $day)
            ->where('source_type', $source->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return CompOffCredit::create([
            'employee_id' => $employee->id,
            'work_date' => $day,
            'source_type' => $source->value,
            'minutes' => $minutes,
            'expiry_date' => $validity === null || (int) $validity <= 0
                ? null
                : Carbon::parse($day)->addMonths((int) $validity)->toDateString(),
            'note' => $note,
            'actor_user_id' => $actor?->id,
            'created_by' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    private function asOf(Carbon|string|null $date): string
    {
        return $date instanceof Carbon ? $date->toDateString() : (string) ($date ?? today()->toDateString());
    }

    /** @return array<string, mixed> */
    private function snapshot(CompOffCredit $credit): array
    {
        return [
            'employee_id' => $credit->employee_id,
            'work_date' => $credit->work_date->toDateString(),
            'source_type' => $credit->source_type->value,
            'minutes' => $credit->minutes,
        ];
    }
}
