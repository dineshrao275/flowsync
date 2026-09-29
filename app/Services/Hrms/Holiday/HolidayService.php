<?php

namespace App\Services\Hrms\Holiday;

use App\Enums\Hrms\HolidayType;
use App\Enums\Hrms\OptionalHolidayStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Holiday\EmployeeHolidayCalendar;
use App\Models\Hrms\Holiday\Holiday;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Models\Hrms\Holiday\HolidayOptionalHoliday;
use App\Services\Hrms\AttendanceService;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Holiday/HRMS — the single source of non-working days.
 *
 * Calendars resolve per employee per year (assignments plus the tenant
 * default, recurring rows expanded by month/day); `isHoliday` and
 * `isWorkingDay` are what attendance, leave and comp-off all read (P8.3),
 * so this service is the only place that answers them. Optional holidays
 * count only when declared taken; restricted ones never close the office.
 */
class HolidayService
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * Calendars covering an employee for a year: assignments overlapping
     * the year, plus the tenant default when it is not already among them.
     *
     * @return Collection<int, HolidayCalendar>
     */
    public function calendarsFor(Employee $employee, int $year): Collection
    {
        $start = Carbon::create($year, 1, 1)->toDateString();
        $end = Carbon::create($year, 12, 31)->toDateString();

        // Scoped through the assignment rows explicitly: the link table
        // points at both ends, so no relation guessing is involved.
        $assigned = EmployeeHolidayCalendar::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->with('calendar')
            ->get()
            ->pluck('calendar')
            ->filter()
            ->unique('id')
            ->values();

        $default = HolidayCalendar::query()->default()->first();

        if ($default !== null && ! $assigned->contains('id', $default->id)) {
            $assigned->push($default);
        }

        return $assigned;
    }

    /**
     * One employee's year as date → holiday entries, recurring rows expanded
     * by month/day. A February 29 recurring row simply has no occurrence in
     * a common year — it recurs, it does not error.
     *
     * @return array<string, array{holiday_id: int, name: string, type: HolidayType, calendar_id: int, calendar_name: string, recurring: bool}>
     */
    public function calendar(Employee $employee, int $year): array
    {
        $days = [];

        foreach ($this->calendarsFor($employee, $year) as $calendar) {
            foreach ($calendar->holidays as $holiday) {
                foreach ($this->occurrences($holiday, $year) as $date) {
                    $days[$date] = [
                        'holiday_id' => $holiday->id,
                        'name' => $holiday->name,
                        'type' => $holiday->type,
                        'calendar_id' => $calendar->id,
                        'calendar_name' => $calendar->name,
                        'recurring' => $holiday->is_recurring,
                    ];
                }
            }
        }

        ksort($days);

        return $days;
    }

    /**
     * Whether a date closes the office for an employee: a public holiday,
     * or an optional one declared taken for that date.
     */
    public function isHoliday(Employee $employee, Carbon|string $date): bool
    {
        $day = $date instanceof Carbon ? $date->toDateString() : (string) $date;
        $entry = $this->calendar($employee, (int) substr($day, 0, 4))[$day] ?? null;

        if ($entry === null) {
            return false;
        }

        if ($entry['type'] === HolidayType::Public) {
            return true;
        }

        // Restricted holidays are working days, full stop; optional ones
        // count only when taken — on the day itself, or with no taken date
        // recorded (taken to mean the day).
        return $entry['type'] === HolidayType::Optional && HolidayOptionalHoliday::query()
            ->where('employee_id', $employee->id)
            ->where('holiday_id', $entry['holiday_id'])
            ->where('status', OptionalHolidayStatus::Taken->value)
            ->where(fn ($query) => $query->whereNull('taken_date')->orWhereDate('taken_date', $day))
            ->exists();
    }

    /**
     * Whether a date is a working day: neither a weekly off nor a holiday.
     * The one predicate attendance, leave and comp-off share — P8.3 wires
     * them to it instead of each keeping a roster copy.
     *
     * The attendance edge resolves lazily, not in the constructor: DayReading
     * (built by AttendanceService) depends on this service, so a
     * constructor edge back would close a resolve-time cycle. By the time
     * this runs, construction is long over.
     */
    public function isWorkingDay(Employee $employee, Carbon|string $date): bool
    {
        return ! app(AttendanceService::class)->isWeekOff($employee, $date)
            && ! $this->isHoliday($employee, $date);
    }

    /**
     * Every closing date in a window: public holidays across the
     * employee's calendars, plus taken optionals on their taken dates.
     *
     * The bulk twin of `isHoliday` — one query for the window, never per
     * date — for the month grid, the summary, and the day splits, which
     * all loop dates and must not query inside the loop.
     *
     * @return list<string> Y-m-d dates
     */
    public function holidayDates(Employee $employee, Carbon|string $from, Carbon|string $to): array
    {
        $from = $from instanceof Carbon ? $from->toDateString() : (string) $from;
        $to = $to instanceof Carbon ? $to->toDateString() : (string) $to;

        $calendarIds = EmployeeHolidayCalendar::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->pluck('calendar_id')
            ->all();

        $default = HolidayCalendar::query()->default()->value('id');

        if ($default !== null) {
            $calendarIds[] = $default;
        }

        // pluck() skips casts, so the SQLite time part is parsed off —
        // comparing raw strings would miss every row on the fast path.
        // Recurring rows expand by month/day (their stored year is just the
        // seeding's), concrete rows read as stored.
        $scoped = Holiday::query()
            ->whereIn('calendar_id', array_unique($calendarIds))
            ->where('type', HolidayType::Public->value);

        $public = (clone $scoped)
            ->where('is_recurring', false)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->pluck('date')
            ->map(fn ($date): string => Carbon::parse((string) $date)->toDateString());

        $years = range((int) substr($from, 0, 4), (int) substr($to, 0, 4));

        foreach ((clone $scoped)->where('is_recurring', true)->get(['date']) as $row) {
            $month = (int) Carbon::parse((string) $row->date)->format('m');
            $day = (int) Carbon::parse((string) $row->date)->format('d');

            foreach ($years as $year) {
                if (! checkdate($month, $day, $year)) {
                    continue;
                }

                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

                if ($date >= $from && $date <= $to) {
                    $public->push($date);
                }
            }
        }

        $public = $public->unique()->values();

        // Taken optionals count on their taken date; a null taken date
        // means the holiday's own day.
        $taken = HolidayOptionalHoliday::query()
            ->where('employee_id', $employee->id)
            ->where('status', OptionalHolidayStatus::Taken->value)
            ->where(fn ($query) => $query
                ->whereDate('taken_date', '>=', $from)->whereDate('taken_date', '<=', $to)
                ->orWhereNull('taken_date'))
            ->with('holiday:id,date')
            ->get()
            ->map(fn (HolidayOptionalHoliday $row): string => $row->taken_date?->toDateString()
                ?? Carbon::parse((string) $row->holiday?->date)->toDateString())
            ->filter(fn (string $date): bool => $date >= $from && $date <= $to);

        return $public->concat($taken)->unique()->values()->all();
    }

    /**
     * @return list<string> Y-m-d occurrences of a row in a year.
     */
    private function occurrences(Holiday $holiday, int $year): array
    {
        if (! $holiday->is_recurring) {
            return $holiday->date->toDateString() >= "{$year}-01-01" && $holiday->date->toDateString() <= "{$year}-12-31"
                ? [$holiday->date->toDateString()]
                : [];
        }

        $month = (int) $holiday->date->format('m');
        $day = (int) $holiday->date->format('d');

        if (! checkdate($month, $day, $year)) {
            return [];
        }

        return [sprintf('%04d-%02d-%02d', $year, $month, $day)];
    }
}
