<?php

namespace App\Services\Hrms\Holiday;

use App\Enums\Hrms\HolidayType;
use App\Models\Hrms\Holiday\Holiday;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Holiday/HRMS — expanding the config catalogue into concrete years.
 *
 * Split from `HolidayService` at the ceiling: reads answer per employee,
 * seeding answers per tenant per year, and the two share nothing but the
 * models. The provisioner seeds the current year for every tenant; P8.4's
 * seed-year endpoint reuses this for any other year.
 */
class HolidayYearSeeder
{
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
}
