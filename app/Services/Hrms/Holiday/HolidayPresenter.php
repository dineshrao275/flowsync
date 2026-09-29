<?php

namespace App\Services\Hrms\Holiday;

use App\Models\Hrms\Holiday\Holiday;
use App\Models\Hrms\Holiday\HolidayCalendar;

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
}
