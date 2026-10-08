<?php

namespace App\Services\Hrms\Holiday;

use App\Enums\Hrms\HolidayType;
use App\Models\Hrms\Holiday\EmployeeHolidayCalendar;
use App\Models\Hrms\Holiday\Holiday;
use App\Models\Hrms\Holiday\HolidayCalendar;
use App\Models\Hrms\Holiday\HolidayOptionalHoliday;

/**
 * Holiday/HRMS — what calendars and holidays look like over HTTP.
 *
 * One shape per resource, defined once (D2.16.4). Assignment and optional
 * shapes join this presenter with their own task.
 */
class HolidayPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function calendar(HolidayCalendar $calendar): array
    {
        return [
            'id' => $calendar->id,
            'name' => $calendar->name,
            'slug' => $calendar->slug,
            'country' => $calendar->country,
            'region' => $calendar->region,
            'description' => $calendar->description,
            'is_default' => $calendar->is_default,
            'is_active' => $calendar->is_active,
            'position' => $calendar->position,
            'holidays_count' => (int) ($calendar->holidays_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function holiday(Holiday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'calendar_id' => $holiday->calendar_id,
            'name' => $holiday->name,
            'date' => $holiday->date->toDateString(),
            'type' => $holiday->type->value,
            'type_label' => $holiday->type->label(),
            'is_recurring' => $holiday->is_recurring,
            'description' => $holiday->description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assignment(EmployeeHolidayCalendar $assignment): array
    {
        $assignment->loadMissing(['employee:id,name,employee_code', 'calendar:id,name,slug']);

        return [
            'id' => $assignment->id,
            'employee' => $assignment->employee === null ? null : [
                'id' => $assignment->employee->id,
                'name' => $assignment->employee->name,
                'employee_code' => $assignment->employee->employee_code,
            ],
            'calendar' => $assignment->calendar === null ? null : [
                'id' => $assignment->calendar->id,
                'name' => $assignment->calendar->name,
                'slug' => $assignment->calendar->slug,
            ],
            'effective_from' => $assignment->effective_from->toDateString(),
            'effective_to' => $assignment->effective_to?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function optional(HolidayOptionalHoliday $answer): array
    {
        $answer->loadMissing(['employee:id,name,employee_code', 'holiday:id,name,date']);

        return [
            'id' => $answer->id,
            'employee' => $answer->employee === null ? null : [
                'id' => $answer->employee->id,
                'name' => $answer->employee->name,
                'employee_code' => $answer->employee->employee_code,
            ],
            'holiday' => $answer->holiday === null ? null : [
                'id' => $answer->holiday->id,
                'name' => $answer->holiday->name,
                'date' => $answer->holiday->date->toDateString(),
            ],
            'status' => $answer->status->value,
            'status_label' => $answer->status->label(),
            'taken_date' => $answer->taken_date?->toDateString(),
            'note' => $answer->note,
        ];
    }

    /**
     * The resolved per-employee year: every closing date with its source,
     * shaped for the grid. The service owns the merge; this owns the keys.
     *
     * @param  array<string, array{holiday_id: int, name: string, type: HolidayType, calendar_id: int, calendar_name: string, recurring: bool}>  $days
     * @return array<string, mixed>
     */
    public function resolved(int $year, array $days): array
    {
        $shaped = [];

        foreach ($days as $date => $entry) {
            $shaped[$date] = [
                'name' => $entry['name'],
                'type' => $entry['type']->value,
                'type_label' => $entry['type']->label(),
                'calendar_name' => $entry['calendar_name'],
                'recurring' => $entry['recurring'],
            ];
        }

        return ['year' => $year, 'days' => $shaped];
    }
}
