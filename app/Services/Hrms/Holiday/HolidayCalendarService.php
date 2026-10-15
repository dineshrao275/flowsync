<?php

namespace App\Services\Hrms\Holiday;

use App\Models\Hrms\Holiday\EmployeeHolidayCalendar;
use App\Models\Hrms\Holiday\Holiday;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Models\Hrms\Holiday\HolidayOptionalHoliday;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Holiday/HRMS — the calendar catalogue and its rows.
 *
 * Calendars are tenant-owned configuration: creating and renaming is
 * free, deleting is refused while holidays or assignments point at the
 * row, and promoting a default demotes the predecessor in-transaction.
 * Slugs are server-allocated (the departments precedent) so a rename can
 * never collide with a string a report joins against.
 */
class HolidayCalendarService
{
    public function __construct(
        private readonly HolidayYearSeeder $seeder,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /** @return Collection<int, HolidayCalendar> */
    public function calendars(): Collection
    {
        return HolidayCalendar::query()->withCount('holidays')->orderBy('position')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCalendar(array $data, ?User $actor = null): HolidayCalendar
    {
        return DB::transaction(function () use ($data, $actor): HolidayCalendar {
            if (($data['is_default'] ?? false) === true) {
                HolidayCalendar::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $calendar = HolidayCalendar::create([
                ...$data,
                'slug' => $this->uniqueSlug((string) ($data['slug'] ?? ''), (string) $data['name']),
            ]);

            $this->audit->log($calendar, 'holiday.calendar_created', null, ['slug' => $calendar->slug], $actor);

            return $calendar->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCalendar(HolidayCalendar $calendar, array $data, ?User $actor = null): HolidayCalendar
    {
        return DB::transaction(function () use ($calendar, $data, $actor): HolidayCalendar {
            if (($data['is_default'] ?? false) === true) {
                HolidayCalendar::query()->whereKeyNot($calendar->id)->where('is_default', true)->update(['is_default' => false]);
            }

            $before = ['name' => $calendar->name, 'is_default' => $calendar->is_default, 'is_active' => $calendar->is_active];

            $calendar->update($data);

            $this->audit->log($calendar, 'holiday.calendar_updated', $before, [
                'name' => $calendar->name,
                'is_default' => $calendar->is_default,
                'is_active' => $calendar->is_active,
            ], $actor);

            return $calendar->refresh();
        });
    }

    /**
     * @throws ValidationException while holidays or assignments point at the row
     */
    public function deleteCalendar(HolidayCalendar $calendar, ?User $actor = null): void
    {
        if ($calendar->holidays()->exists()) {
            throw ValidationException::withMessages(['form' => 'This calendar still holds holidays. Move or delete them first.']);
        }

        if (EmployeeHolidayCalendar::query()->where('calendar_id', $calendar->id)->exists()) {
            throw ValidationException::withMessages(['form' => 'Employees still follow this calendar. Unassign them first.']);
        }

        if ($calendar->is_default) {
            throw ValidationException::withMessages(['form' => 'The default calendar cannot be deleted. Make another calendar default first.']);
        }

        $snapshot = ['slug' => $calendar->slug, 'name' => $calendar->name];
        $calendar->delete();

        $this->audit->log($calendar, 'holiday.calendar_deleted', $snapshot, null, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createHoliday(HolidayCalendar $calendar, array $data, ?User $actor = null): Holiday
    {
        $holiday = $calendar->holidays()->create($data);

        $this->audit->log($holiday, 'holiday.created', null, [
            'calendar_id' => $calendar->id,
            'name' => $holiday->name,
            'date' => $holiday->date->toDateString(),
        ], $actor);

        return $holiday->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHoliday(Holiday $holiday, array $data, ?User $actor = null): Holiday
    {
        $before = ['name' => $holiday->name, 'date' => $holiday->date->toDateString(), 'type' => $holiday->type->value];

        $holiday->update($data);

        $this->audit->log($holiday, 'holiday.updated', $before, [
            'name' => $holiday->name,
            'date' => $holiday->date->toDateString(),
            'type' => $holiday->type->value,
        ], $actor);

        return $holiday->refresh();
    }

    /**
     * @throws ValidationException while optional answers point at the row
     */
    public function deleteHoliday(Holiday $holiday, ?User $actor = null): void
    {
        if (HolidayOptionalHoliday::query()->where('holiday_id', $holiday->id)->exists()) {
            throw ValidationException::withMessages(['form' => 'Employees have answered this holiday. It cannot be deleted.']);
        }

        $snapshot = ['calendar_id' => $holiday->calendar_id, 'name' => $holiday->name];
        $holiday->delete();

        $this->audit->log($holiday, 'holiday.deleted', $snapshot, null, $actor);
    }

    /**
     * Expand a year on demand: the P8.2 seeder, shared by provisioning and
     * the seed-year endpoint so the two cannot disagree on what a year
     * contains.
     *
     * @return array{calendars: int, holidays: int}
     */
    public function seedYear(int $year, ?User $actor = null): array
    {
        $result = $this->seeder->seedFromConfig($year);

        // The seeder guarantees a default whenever it created anything; an
        // empty config leaves nothing to audit against, and an audit row
        // pointing at no record is worse than none.
        $default = HolidayCalendar::query()->default()->first();

        if ($default !== null) {
            $this->audit->log($default, 'holiday.year_seeded', null, ['year' => $year, ...$result], $actor);
        }

        return $result;
    }

    /**
     * Server-allocated slugs with an auto-suffix (the departments
     * precedent): accepting a client's would let two calendars collide on
     * the string the seeder joins against.
     */
    private function uniqueSlug(string $slug, string $name): string
    {
        $base = $slug !== '' ? Str::slug($slug) : Str::slug($name);
        $candidate = $base;
        $suffix = 2;

        while (HolidayCalendar::query()->where('slug', $candidate)->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
