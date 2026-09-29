<?php

namespace App\Services\Hrms\Holiday;

use App\Enums\Hrms\HolidayType;
use App\Enums\Hrms\OptionalHolidayStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Holiday\EmployeeHolidayCalendar;
use App\Models\Hrms\Holiday\Holiday;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Models\Hrms\Holiday\HolidayOptionalHoliday;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Services\Hrms\AttendanceService;
use App\Services\HrmsAuditLogger;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly HrmsAuditLogger $audit,
    ) {}

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
     */
    public function isWorkingDay(Employee $employee, Carbon|string $date): bool
    {
        return ! $this->attendance->isWeekOff($employee, $date) && ! $this->isHoliday($employee, $date);
    }

    /**
     * Expand `config('hrms.holidays')` (country → month/day entries) into
     * concrete rows for a year, creating per-country calendars as needed.
     * Idempotent on (calendar, name, date): reruns and repairs find, never
     * duplicate. Unparseable entries skip with a warning — a typo in
     * config must not fail every tenant's provisioning.
     *
     * @return array{calendars: int, holidays: int}
     */
    public function seedFromConfig(int $year): array
    {
        $calendars = 0;
        $holidays = 0;

        foreach ((array) config('hrms.holidays', []) as $country => $entries) {
            $calendar = HolidayCalendar::query()->firstOrCreate(
                ['slug' => 'national-'.strtolower((string) $country)],
                [
                    'name' => strtoupper((string) $country).' National',
                    'country' => strtoupper((string) $country),
                    'is_active' => true,
                ],
            );

            if ($calendar->wasRecentlyCreated) {
                $calendars++;
            }

            foreach ((array) $entries as $entry) {
                if (! isset($entry['name'], $entry['month'], $entry['day'])) {
                    Log::channel('hrms')->warning('holiday.seed.skipped', ['entry' => $entry, 'reason' => 'missing name, month, or day']);

                    continue;
                }

                $month = (int) $entry['month'];
                $day = (int) $entry['day'];

                if (! checkdate($month, $day, $year)) {
                    Log::channel('hrms')->warning('holiday.seed.skipped', [
                        'entry' => $entry,
                        'year' => $year,
                        'reason' => 'not a calendar date this year',
                    ]);

                    continue;
                }

                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

                // An explicit find, never firstOrCreate on the date: the
                // `date` cast serializes with a time part on SQLite's
                // typeless columns, so an exact-string match misses the row
                // just written and the rerun duplicates (the P5.2 trap the
                // comp-off accrual learned the same way).
                $row = Holiday::query()
                    ->where('calendar_id', $calendar->id)
                    ->where('name', (string) $entry['name'])
                    ->whereDate('date', $date)
                    ->first();

                if ($row !== null) {
                    continue;
                }

                Holiday::create([
                    'calendar_id' => $calendar->id,
                    'name' => (string) $entry['name'],
                    'date' => $date,
                    'type' => ! empty($entry['is_optional']) ? HolidayType::Optional->value : HolidayType::Public->value,
                    'is_recurring' => true,
                ]);

                $holidays++;
            }
        }

        $this->ensureDefault();

        Log::channel('hrms')->info('holiday.seed.completed', [
            'tenant_id' => app(TenantContext::class)->currentId(),
            'year' => $year,
            'calendars' => $calendars,
            'holidays' => $holidays,
        ]);

        return ['calendars' => $calendars, 'holidays' => $holidays];
    }

    /**
     * The tenant fallback: its own country's calendar, else the first one
     * standing. Set once and left alone — a tenant that chose its default
     * keeps it across repairs.
     */
    private function ensureDefault(): void
    {
        if (HolidayCalendar::query()->default()->exists()) {
            return;
        }

        $country = strtoupper((string) (HrmsSetting::current()->country ?? 'US'));

        $calendar = HolidayCalendar::query()->where('country', $country)->orderBy('id')->first()
            ?? HolidayCalendar::query()->orderBy('id')->first();

        $calendar?->update(['is_default' => true]);
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
